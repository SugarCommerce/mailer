<?php
/**
 * Hostile input and hostile servers.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCommerce\Mailer\Address;
use SugarCommerce\Mailer\Attachment;
use SugarCommerce\Mailer\Message;
use SugarCommerce\Mailer\Mime;
use SugarCommerce\Mailer\SendResult;
use SugarCommerce\Mailer\Transport;
use SugarCommerce\Mailer\Transports\Api;
use SugarCommerce\Mailer\Transports\Cloudflare;
use SugarCommerce\Mailer\Transports\Log;
use SugarCommerce\Mailer\Transports\Postmark;
use SugarCommerce\Mailer\Transports\Resend;
use SugarCommerce\Mailer\Transports\Ses;
use SugarCommerce\Mailer\Transports\Smtp;

/**
 * Class SecurityTest
 *
 * Each test here is an attack that worked before 1.3.0.
 */
#[CoversClass( Address::class )]
#[CoversClass( Attachment::class )]
#[CoversClass( Mime::class )]
#[CoversClass( Smtp::class )]
#[CoversClass( Ses::class )]
#[CoversClass( Log::class )]
final class SecurityTest extends TestCase {

	/* ─── Headers ───────────────────────────────────────────────────── */

	/**
	 * FILTER_VALIDATE_EMAIL accepts a quoted local part holding a
	 * vertical tab or DEL, which then lands in RCPT TO.
	 */
	public function test_address_rejects_control_characters_in_a_quoted_local_part(): void {
		$this->expectException( InvalidArgumentException::class );

		new Address( "\"a\x0Bb\"@example.com" );
	}

	public function test_address_still_allows_a_tab_in_a_name(): void {
		$this->assertSame( "A\tB", ( new Address( 'a@example.com', "A\tB" ) )->name );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function hostile_attachments(): array {
		return array(
			'newline in filename'      => array( "a.pdf\r\nBcc: x@evil.com", 'application/pdf', '' ),
			'newline in content type'  => array( 'a.pdf', "application/pdf\r\nX-Evil: 1", '' ),
			'content type not a type'  => array( 'a.pdf', 'application/pdf"; x', '' ),
			'newline in content id'    => array( 'a.png', 'image/png', "logo\r\nX-Evil: 1" ),
			'bracket in content id'    => array( 'a.png', 'image/png', 'logo>' ),
			'space in content id'      => array( 'a.png', 'image/png', 'lo go' ),
		);
	}

	/**
	 * A filename from an upload is written into the part headers, so a
	 * line break in it would add headers or forge a part.
	 */
	#[DataProvider( 'hostile_attachments' )]
	public function test_attachment_rejects_header_injection( string $filename, string $type, string $cid ): void {
		$this->expectException( InvalidArgumentException::class );

		new Attachment( $filename, 'x', $type, $cid );
	}

	public function test_attachment_accepts_a_content_type_with_parameters(): void {
		$this->assertSame( 'text/plain; charset=UTF-8', ( new Attachment( 'a.txt', 'x', 'text/plain; charset=UTF-8' ) )->mime_type );
	}

	/**
	 * A quote in a filename used to end the quoted string early.
	 */
	public function test_mime_escapes_quotes_in_a_filename(): void {
		$mime = Mime::build( $this->message()->attach( new Attachment( 'a"; x="y.pdf', 'x', 'application/pdf' ) ) );

		$this->assertStringContainsString( 'filename="a\"; x=\"y.pdf"', $mime );
		$this->assertStringContainsString( 'name="a\"; x=\"y.pdf"', $mime );
	}

	public function test_attachment_from_path_refuses_a_remote_wrapper(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'local files' );

		Attachment::from_path( 'http://169.254.169.254/latest/meta-data/' );
	}

	public function test_attachment_from_path_accepts_a_file_url(): void {
		$path = (string) tempnam( sys_get_temp_dir(), 'sm' );
		file_put_contents( $path, 'contents' );

		try {
			$this->assertSame( 'contents', Attachment::from_path( 'file://' . $path, 'a.txt' )->content );
		} finally {
			unlink( $path );
		}
	}

	/* ─── SMTP smuggling ────────────────────────────────────────────── */

	/**
	 * `\n.\r\n` ends DATA on some servers; whatever follows is then sent
	 * as a second message on our credentials.
	 */
	public function test_dot_stuffing_normalises_bare_line_endings(): void {
		$this->assertSame( "a\r\n..\r\nMAIL FROM:<x@evil.com>", Mime::dot_stuff( "a\n.\r\nMAIL FROM:<x@evil.com>" ) );
		$this->assertSame( "a\r\n..\r\nb", Mime::dot_stuff( "a\r.\rb" ) );
		$this->assertSame( "a\r\n..\r\nb", Mime::dot_stuff( "a\r\n.\nb" ) );
	}

	/* ─── SMTP TLS ──────────────────────────────────────────────────── */

	/**
	 * Someone on the path deletes STARTTLS from the EHLO reply. The
	 * password must not follow in the clear.
	 */
	public function test_smtp_refuses_a_server_without_starttls(): void {
		[ $client, $server ] = $this->pair( "220 mx ready\r\n250-mx\r\n250 AUTH PLAIN LOGIN\r\n" );

		try {
			$this->converse( new Smtp( 'mx.example.com', 587, 'user', 'hunter2' ), $client );
			$this->fail( 'Continued without TLS.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'STARTTLS', $e->getMessage() );
		}

		$sent = $this->drain( $server );

		$this->assertStringNotContainsString( 'AUTH', $sent );
		$this->assertStringNotContainsString( 'MAIL FROM', $sent );
	}

	/**
	 * A keyword mentioned in the greeting is not the server offering it.
	 */
	public function test_smtp_reads_starttls_only_as_a_keyword(): void {
		[ $client, $server ] = $this->pair( "220 mx ready\r\n250-starttls.example.com greets you\r\n250 AUTH PLAIN\r\n" );

		$this->assertIsResource( $server ); // Held open: a closed peer fails the first write instead.
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'does not offer STARTTLS' );

		$this->converse( new Smtp( 'mx.example.com', 587, 'user', 'hunter2' ), $client );
	}

	/**
	 * Plaintext stays possible for a local catcher, when asked for.
	 */
	public function test_smtp_sends_in_the_clear_only_when_told_to(): void {
		[ $client, $server ] = $this->pair(
			"220 mx ready\r\n250-mx\r\n250 SIZE 1000\r\n250 ok\r\n250 ok\r\n354 go\r\n250 2.0.0 Ok: queued as ABC123XYZ\r\n"
		);

		$result = $this->converse( new Smtp( '127.0.0.1', 1025, require_tls: false ), $client );

		$this->assertTrue( $result->sent );
		$this->assertSame( 'ABC123XYZ', $result->message_id );
		$this->assertStringContainsString( "RCPT TO:<dave@example.com>\r\n", $this->drain( $server ) );
	}

	/**
	 * Bytes queued behind the 220 to STARTTLS were sent in the clear and
	 * would be read as the server's first reply over TLS.
	 */
	public function test_smtp_refuses_data_injected_before_the_tls_handshake(): void {
		[ $client, $server ] = $this->pair( "220 mx ready\r\n250-mx\r\n250 STARTTLS\r\n220 go ahead\r\n250-mx\r\n250 AUTH PLAIN\r\n" );

		$this->assertIsResource( $server ); // Held open: a closed peer fails the first write instead.
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'before the TLS handshake' );

		$this->converse( new Smtp( 'mx.example.com', 587, 'user', 'hunter2' ), $client );
	}

	public function test_smtp_caps_the_length_of_a_reply(): void {
		// A greeting that never ends. A memory stream rather than a socket,
		// which would block the writer once its buffer filled.
		$client = fopen( 'php://memory', 'r+' );

		$this->assertIsResource( $client );

		fwrite( $client, str_repeat( "220-padding padding padding padding padding\r\n", 2_000 ) );
		rewind( $client );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'too long' );

		$this->converse( new Smtp( 'mx.example.com', 587, require_tls: false ), $client );
	}

	/* ─── Secrets ───────────────────────────────────────────────────── */

	/**
	 * @return array<string, array{0: Transport, 1: string}>
	 */
	public static function transports_with_secrets(): array {
		return array(
			'smtp'       => array( new Smtp( 'mx.example.com', 587, 'user', 'smtp-secret-1' ), 'smtp-secret-1' ),
			'postmark'   => array( new Postmark( 'postmark-secret-1' ), 'postmark-secret-1' ),
			'resend'     => array( new Resend( 're_secret_1' ), 're_secret_1' ),
			'cloudflare' => array( new Cloudflare( 'cf-secret-1', 'acct' ), 'cf-secret-1' ),
			'ses'        => array( new Ses( 'AKIDSECRET1', 'ses-secret-1', 'eu-west-1' ), 'ses-secret-1' ),
		);
	}

	/**
	 * A transport dumped into an error page or a log keeps its key.
	 */
	#[DataProvider( 'transports_with_secrets' )]
	public function test_dumping_a_transport_does_not_show_its_secret( Transport $transport, string $secret ): void {
		$this->assertStringNotContainsString( $secret, print_r( $transport, true ) );

		ob_start();
		var_dump( $transport );
		$this->assertStringNotContainsString( $secret, (string) ob_get_clean() );
	}

	/**
	 * The region is part of the hostname the signed request goes to.
	 */
	public function test_ses_refuses_a_region_that_is_a_hostname(): void {
		$this->expectException( InvalidArgumentException::class );

		new Ses( 'AKID', 'secret', 'evil.example/x?' );
	}

	public function test_ses_accepts_real_regions(): void {
		foreach ( array( 'us-east-1', 'eu-central-2', 'us-gov-west-1', 'ap-southeast-4' ) as $region ) {
			$this->assertSame( 'ses', ( new Ses( 'AKID', 'secret', $region ) )->name() );
		}
	}

	/**
	 * A newline pasted in with a key would start a second request header.
	 */
	public function test_api_refuses_a_header_with_a_line_break_without_echoing_it(): void {
		$response = $this->api()->call_post( array( 'Authorization' => "Bearer key-1\r\nX-Evil: 1" ) );

		$this->assertSame( 0, $response['status'] );
		$this->assertStringContainsString( 'line break', $response['error'] );
		$this->assertStringNotContainsString( 'key-1', $response['error'] );
	}

	/**
	 * Cloudflare reports errors as a list; it used to read as "Array".
	 */
	public function test_api_reads_a_list_of_errors(): void {
		$result = $this->api()->call_classify(
			array( 'status' => 400, 'body' => '{"errors":[{"code":1,"message":"Bad sender"},{"code":2,"message":"Bad domain"}]}', 'error' => '' ),
			'id',
			'errors'
		);

		$this->assertSame( 'Bad sender; Bad domain', $result->error );
	}

	/* ─── Log ───────────────────────────────────────────────────────── */

	/**
	 * The log holds reset links and licence keys.
	 */
	public function test_log_creates_its_file_readable_by_the_owner_only(): void {
		$path = sys_get_temp_dir() . '/sm-log-' . bin2hex( random_bytes( 4 ) );
		$old  = umask( 0022 );

		try {
			( new Log( $path ) )->send( $this->message() );
			clearstatcache();

			$this->assertSame( 0600, fileperms( $path ) & 0777 );
		} finally {
			umask( $old );
			@unlink( $path );
		}
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	/**
	 * A complete message.
	 */
	private function message(): Message {
		return Message::to( 'dave@example.com' )
			->from( 'billing@shop.com', 'Shop' )
			->subject( 'Your receipt' )
			->text( 'Thanks.' );
	}

	/**
	 * A connected socket pair, the server side already holding its replies.
	 *
	 * @param string $replies What the server says, in order.
	 *
	 * @return array{0: resource, 1: resource} Client, server.
	 */
	private function pair( string $replies ): array {
		$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );

		$this->assertIsArray( $pair );

		[ $client, $server ] = $pair;

		stream_set_timeout( $client, 2 );

		if ( '' !== $replies ) {
			fwrite( $server, $replies );
		}

		return array( $client, $server );
	}

	/**
	 * Everything the client wrote.
	 *
	 * @param resource $server Server end.
	 */
	private function drain( $server ): string {
		stream_set_blocking( $server, false );

		return (string) stream_get_contents( $server );
	}

	/**
	 * Run the SMTP conversation over a given socket.
	 *
	 * @param resource $client Client end.
	 */
	private function converse( Smtp $smtp, $client ): SendResult {
		$result = ( new \ReflectionMethod( Smtp::class, 'converse' ) )->invoke( $smtp, $client, $this->message() );

		$this->assertInstanceOf( SendResult::class, $result );

		return $result;
	}

	/**
	 * A class exposing the Api trait's private plumbing.
	 */
	private function api(): object {
		return new class() {

			use Api;

			/**
			 * @param array<string, string> $headers
			 *
			 * @return array{status: int, body: string, error: string}
			 */
			public function call_post( array $headers ): array {
				return $this->post( 'https://127.0.0.1:9/', array( 'a' => 1 ), $headers, 1 );
			}

			/**
			 * @param array{status: int, body: string, error: string} $response
			 */
			public function call_classify( array $response, string $id_key, string $error_key ): SendResult {
				return $this->classify( $response, $id_key, $error_key );
			}
		};
	}
}
