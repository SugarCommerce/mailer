<?php
/**
 * SMTP transport.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer\Transports;

use SugarCommerce\Mailer\{Address, Message, Mime, SendResult, Transport};

/**
 * Class Smtp
 *
 * The universal fallback. One implementation reaches Postmark, SES,
 * Mailgun, Cloudflare, Fastmail and any host's own server.
 *
 * Prefer an API transport where one exists. SMTP needs five to eight
 * round trips before a message is accepted, where an API call needs one;
 * and shared hosts routinely block outbound 25, 465 and 587, which is
 * the single most common reason "email doesn't work" on a self-hosted
 * install. SMTP is what you use when nothing else is available — or when
 * the operator insists on their own server.
 *
 * @since 1.0.0
 */
final readonly class Smtp implements Transport {

	/**
	 * Most bytes one server reply may run to.
	 *
	 * A real EHLO reply is a few hundred bytes. A server that keeps
	 * sending continuation lines is not a mail server, and reading it to
	 * the end would hold the worker until memory ran out.
	 *
	 * @since 1.3.0
	 */
	private const int MAX_REPLY = 65_536;

	/**
	 * TLS versions accepted, for both implicit TLS and STARTTLS.
	 *
	 * 1.2 and 1.3 only: PHP's generic TLS client constant still admits
	 * 1.0 and 1.1 on some builds.
	 *
	 * @since 1.3.0
	 */
	private const int CRYPTO = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

	/**
	 * Configure the connection.
	 *
	 * @since 1.0.0
	 * @since 1.3.0 Added `$require_tls`, on by default.
	 *
	 * @param string $host         Server hostname.
	 * @param int    $port         587 for STARTTLS, 465 for implicit TLS.
	 * @param string $username     Auth username.
	 * @param string $password     Auth password.
	 * @param bool   $implicit_tls Wrap the socket in TLS from the start,
	 *                             as port 465 requires. Port 587 upgrades
	 *                             with STARTTLS instead.
	 * @param int    $timeout      Seconds.
	 * @param bool   $verify_peer  Verify the server certificate. Turning
	 *                             this off makes the connection
	 *                             interceptable and is never right in
	 *                             production.
	 * @param bool   $require_tls  Refuse to continue when a server reached
	 *                             without implicit TLS does not offer
	 *                             STARTTLS. Anyone on the path can strip
	 *                             the STARTTLS line from the reply, and
	 *                             without this the password and the
	 *                             message would then go in the clear.
	 *                             Turn it off only for a local catcher
	 *                             such as Mailpit.
	 */
	public function __construct(
		private string $host,
		private int $port = 587,
		#[\SensitiveParameter] private string $username = '',
		#[\SensitiveParameter] private string $password = '',
		private bool $implicit_tls = false,
		private int $timeout = 15,
		private bool $verify_peer = true,
		private bool $require_tls = true,
	) {}

	/**
	 * What var_dump() and print_r() show: the settings, never the
	 * credentials.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, string|int|bool>
	 */
	public function __debugInfo(): array {
		return array(
			'host'         => $this->host,
			'port'         => $this->port,
			'username'     => '' === $this->username ? '' : '[redacted]',
			'password'     => '' === $this->password ? '' : '[redacted]',
			'implicit_tls' => $this->implicit_tls,
			'timeout'      => $this->timeout,
			'verify_peer'  => $this->verify_peer,
			'require_tls'  => $this->require_tls,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function send( Message $message ): SendResult {
		$context = stream_context_create( array(
			'ssl' => array(
				'verify_peer'       => $this->verify_peer,
				'verify_peer_name'  => $this->verify_peer,
				'allow_self_signed' => false,
				'peer_name'         => $this->host,
				'SNI_enabled'       => true,
				'crypto_method'     => self::CRYPTO,
			),
		) );

		$scheme = $this->implicit_tls ? 'ssl://' : 'tcp://';
		$socket = @stream_socket_client(
			$scheme . $this->host . ':' . $this->port,
			$errno,
			$errstr,
			$this->timeout,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if ( false === $socket ) {
			// Almost always a blocked outbound port on shared hosting.
			return SendResult::failed(
				'Could not connect to ' . $this->host . ':' . $this->port . ' — ' . ( $errstr ?: 'connection refused' ),
				0,
				true
			);
		}

		stream_set_timeout( $socket, $this->timeout );

		try {
			return $this->converse( $socket, $message );
		} catch ( \RuntimeException $e ) {
			return SendResult::failed( $e->getMessage(), 0, true );
		} finally {
			@fwrite( $socket, "QUIT\r\n" );
			@fclose( $socket );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function name(): string {
		return 'smtp';
	}

	/**
	 * Run the SMTP conversation.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $socket  Open socket.
	 * @param Message  $message Message.
	 *
	 * @return SendResult
	 *
	 * @throws \RuntimeException On an unexpected reply.
	 */
	private function converse( $socket, Message $message ): SendResult {
		$this->expect( $socket, 220 );

		$domain = $message->sender()?->domain() ?: 'localhost';

		$this->command( $socket, 'EHLO ' . $domain );
		$capabilities = self::capabilities( $this->expect( $socket, 250 ) );

		if ( ! $this->implicit_tls ) {
			if ( isset( $capabilities['STARTTLS'] ) ) {
				$this->command( $socket, 'STARTTLS' );
				$this->expect( $socket, 220 );

				// Anything already waiting arrived before the handshake, in
				// the clear, and would be read afterwards as if the server
				// had sent it over TLS: the STARTTLS response injection
				// attack. A real server says nothing until we do.
				if ( self::pending( $socket ) ) {
					throw new \RuntimeException( 'The mail server sent data before the TLS handshake; refusing to continue.' );
				}

				if ( true !== @stream_socket_enable_crypto( $socket, true, self::CRYPTO ) ) {
					throw new \RuntimeException( 'Could not negotiate TLS with ' . $this->host . '.' );
				}

				// The capability list must be re-read after the upgrade: a
				// server may only advertise AUTH once the channel is secure,
				// and nothing said before it can be trusted.
				$this->command( $socket, 'EHLO ' . $domain );
				$capabilities = self::capabilities( $this->expect( $socket, 250 ) );
			} elseif ( $this->require_tls ) {
				throw new \RuntimeException( $this->host . ' does not offer STARTTLS; refusing to send credentials or mail in the clear.' );
			}
		}

		if ( '' !== $this->username ) {
			$this->authenticate( $socket, $capabilities );
		}

		$this->command( $socket, 'MAIL FROM:<' . $message->sender()?->email . '>' );
		$this->expect( $socket, 250 );

		// Bcc recipients are given to the server but never written into
		// the headers — that is the whole point of a blind copy, and
		// writing them is a privacy incident.
		$envelope = array_merge( $message->recipients(), $message->cc_recipients(), $message->bcc_recipients() );

		foreach ( $envelope as $recipient ) {
			$this->command( $socket, 'RCPT TO:<' . $recipient->email . '>' );
			$this->expect( $socket, array( 250, 251 ) );
		}

		$this->command( $socket, 'DATA' );
		$this->expect( $socket, 354 );

		$this->write( $socket, Mime::dot_stuff( Mime::build( $message ) ) . "\r\n.\r\n" );
		$reply = $this->expect( $socket, 250 );

		// Many servers return the queue id here; keep it for tracing.
		preg_match( '/\b([0-9A-Za-z]{6,})\b\s*$/', trim( $reply ), $m );

		return SendResult::ok( $m[1] ?? '', 250 );
	}

	/**
	 * Authenticate, preferring the mechanism the server advertises.
	 *
	 * @since 1.0.0
	 *
	 * @param resource              $socket       Open socket.
	 * @param array<string, string> $capabilities EHLO keywords, as
	 *                                            capabilities() returns
	 *                                            them.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When authentication fails.
	 */
	private function authenticate( $socket, array $capabilities ): void {
		$mechanisms = explode( ' ', $capabilities['AUTH'] ?? '' );

		if ( in_array( 'PLAIN', $mechanisms, true ) ) {
			$this->command( $socket, 'AUTH PLAIN ' . base64_encode( "\0" . $this->username . "\0" . $this->password ) );
			$this->expect( $socket, 235 );

			return;
		}

		if ( in_array( 'LOGIN', $mechanisms, true ) ) {
			$this->command( $socket, 'AUTH LOGIN' );
			$this->expect( $socket, 334 );

			$this->command( $socket, base64_encode( $this->username ) );
			$this->expect( $socket, 334 );

			$this->command( $socket, base64_encode( $this->password ) );
			$this->expect( $socket, 235 );

			return;
		}

		throw new \RuntimeException( 'The server advertises no supported authentication mechanism.' );
	}

	/**
	 * The keywords an EHLO reply advertises.
	 *
	 * Read line by line rather than searched as text, so a greeting that
	 * merely mentions a keyword (a host called `starttls.example.com`)
	 * is not taken for the server offering it.
	 *
	 * @since 1.3.0
	 *
	 * @param string $reply The full EHLO reply.
	 *
	 * @return array<string, string> Upper-cased keyword => its parameters,
	 *                               e.g. `AUTH` => `PLAIN LOGIN`.
	 */
	private static function capabilities( string $reply ): array {
		$capabilities = array();
		$lines        = preg_split( '/\r?\n/', trim( $reply ) ) ?: array();

		// The first line is the server's greeting, not a keyword.
		foreach ( array_slice( $lines, 1 ) as $line ) {
			$words = preg_split( '/[ \t]+/', strtoupper( trim( substr( $line, 4 ) ) ) ) ?: array();
			$key   = (string) array_shift( $words );

			if ( '' !== $key ) {
				$capabilities[ $key ] = implode( ' ', $words );
			}
		}

		return $capabilities;
	}

	/**
	 * Whether the server has sent bytes nobody has asked for yet.
	 *
	 * Checks PHP's own read buffer and then the socket itself, without
	 * waiting.
	 *
	 * @since 1.3.0
	 *
	 * @param resource $socket Open socket.
	 *
	 * @return bool
	 */
	private static function pending( $socket ): bool {
		if ( stream_get_meta_data( $socket )['unread_bytes'] > 0 ) {
			return true;
		}

		$read   = array( $socket );
		$write  = null;
		$except = null;

		return 0 < (int) @stream_select( $read, $write, $except, 0 );
	}

	/**
	 * Send a command line.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $socket Open socket.
	 * @param string   $line   Command.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException On a write failure.
	 */
	private function command( $socket, string $line ): void {
		$this->write( $socket, $line . "\r\n" );
	}

	/**
	 * Write to the socket.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $socket Open socket.
	 * @param string   $data   Bytes.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException On a write failure.
	 */
	private function write( $socket, string $data ): void {
		if ( false === @fwrite( $socket, $data ) ) {
			throw new \RuntimeException( 'The connection closed while sending.' );
		}
	}

	/**
	 * Read a reply and assert its code.
	 *
	 * @since 1.0.0
	 *
	 * @param resource       $socket   Open socket.
	 * @param int|array<int> $expected Acceptable codes.
	 *
	 * @return string The full reply.
	 *
	 * @throws \RuntimeException On an unexpected code, a timeout, or a
	 *                           reply longer than MAX_REPLY.
	 */
	private function expect( $socket, int|array $expected ): string {
		$expected = (array) $expected;
		$reply    = '';

		// A multi-line reply marks continuations with "250-" and the last
		// line with "250 " (or a bare "250"). Stopping at the first line
		// loses the capability list.
		while ( true ) {
			$line = @fgets( $socket, 1024 );

			if ( false === $line ) {
				throw new \RuntimeException( 'The mail server stopped responding.' );
			}

			$reply .= $line;

			if ( strlen( $reply ) > self::MAX_REPLY ) {
				throw new \RuntimeException( 'The mail server sent a reply too long to be one.' );
			}

			if ( 1 === preg_match( '/^\d{3}(?:[ \r\n]|$)/', $line ) ) {
				break;
			}
		}

		$code = (int) substr( $reply, 0, 3 );

		if ( ! in_array( $code, $expected, true ) ) {
			throw new \RuntimeException( 'The mail server replied: ' . trim( $reply ) );
		}

		return $reply;
	}
}
