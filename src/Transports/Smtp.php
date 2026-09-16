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
	 * @since 1.0.0
	 *
	 * @param string $host      Server hostname.
	 * @param int    $port      587 for STARTTLS, 465 for implicit TLS.
	 * @param string $username  Auth username.
	 * @param string $password  Auth password.
	 * @param bool   $implicit_tls Wrap the socket in TLS from the start,
	 *                             as port 465 requires. Port 587 upgrades
	 *                             with STARTTLS instead.
	 * @param int    $timeout   Seconds.
	 * @param bool   $verify_peer Verify the server certificate. Turning
	 *                            this off makes the connection
	 *                            interceptable and is never right in
	 *                            production.
	 */
	public function __construct(
		private string $host,
		private int $port = 587,
		#[\SensitiveParameter] private string $username = '',
		#[\SensitiveParameter] private string $password = '',
		private bool $implicit_tls = false,
		private int $timeout = 15,
		private bool $verify_peer = true,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function send( Message $message ): SendResult {
		$context = stream_context_create( array(
			'ssl' => array(
				'verify_peer'       => $this->verify_peer,
				'verify_peer_name'  => $this->verify_peer,
				'SNI_enabled'       => true,
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
		$capabilities = $this->expect( $socket, 250 );

		if ( ! $this->implicit_tls && str_contains( strtoupper( $capabilities ), 'STARTTLS' ) ) {
			$this->command( $socket, 'STARTTLS' );
			$this->expect( $socket, 220 );

			if ( ! @stream_socket_enable_crypto( $socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) {
				throw new \RuntimeException( 'Could not negotiate TLS with ' . $this->host . '.' );
			}

			// The capability list must be re-read after the upgrade: a
			// server may only advertise AUTH once the channel is secure.
			$this->command( $socket, 'EHLO ' . $domain );
			$capabilities = $this->expect( $socket, 250 );
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
	 * @param resource $socket       Open socket.
	 * @param string   $capabilities EHLO response.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When authentication fails.
	 */
	private function authenticate( $socket, string $capabilities ): void {
		$upper = strtoupper( $capabilities );

		if ( str_contains( $upper, 'PLAIN' ) ) {
			$this->command( $socket, 'AUTH PLAIN ' . base64_encode( "\0" . $this->username . "\0" . $this->password ) );
			$this->expect( $socket, 235 );

			return;
		}

		if ( str_contains( $upper, 'LOGIN' ) ) {
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
	 * @param resource  $socket   Open socket.
	 * @param int|int[] $expected Acceptable codes.
	 *
	 * @return string The full reply.
	 *
	 * @throws \RuntimeException On an unexpected code or a timeout.
	 */
	private function expect( $socket, int|array $expected ): string {
		$expected = (array) $expected;
		$reply    = '';

		// A multi-line reply marks continuations with "250-" and the last
		// line with "250 ". Stopping at the first line loses the
		// capability list.
		while ( true ) {
			$line = @fgets( $socket, 1024 );

			if ( false === $line ) {
				throw new \RuntimeException( 'The mail server stopped responding.' );
			}

			$reply .= $line;

			if ( 1 === preg_match( '/^\d{3} /', $line ) ) {
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
