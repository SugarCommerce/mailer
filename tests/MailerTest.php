<?php
/**
 * Mailer test suite.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCommerce\Mailer\Address;
use SugarCommerce\Mailer\Attachment;
use SugarCommerce\Mailer\Mailer;
use SugarCommerce\Mailer\Message;
use SugarCommerce\Mailer\Mime;
use SugarCommerce\Mailer\SendResult;
use SugarCommerce\Mailer\Transports\Log;
use SugarCommerce\Mailer\Transports\Memory;

#[CoversClass( Address::class )]
#[CoversClass( Attachment::class )]
#[CoversClass( Mailer::class )]
#[CoversClass( Message::class )]
#[CoversClass( Mime::class )]
#[CoversClass( SendResult::class )]
#[CoversClass( Log::class )]
#[CoversClass( Memory::class )]
final class MailerTest extends TestCase {

	/* ─── Address ───────────────────────────────────────────────────── */

	#[Group( 'address' )]
	public function test_address_accepts_a_bare_email(): void {
		$address = new Address( 'dave@example.com' );

		$this->assertSame( 'dave@example.com', $address->email );
		$this->assertSame( '', $address->name );
		$this->assertSame( 'dave@example.com', $address->header() );
	}

	#[Group( 'address' )]
	public function test_address_quotes_a_display_name(): void {
		$address = new Address( 'dave@example.com', 'Dave Sherlock' );

		$this->assertSame( '"Dave Sherlock" <dave@example.com>', $address->header() );
	}

	#[Group( 'address' )]
	public function test_address_escapes_quotes_in_a_display_name(): void {
		$address = new Address( 'a@b.com', 'The "Boss"' );

		$this->assertSame( '"The \"Boss\"" <a@b.com>', $address->header() );
	}

	/**
	 * An unquoted comma in a display name splits one recipient into two.
	 */
	#[Group( 'address' )]
	public function test_address_containing_a_comma_stays_one_recipient(): void {
		$address = new Address( 'a@b.com', 'Sherlock, David' );

		$this->assertStringStartsWith( '"', $address->header() );
		$this->assertStringContainsString( '"Sherlock, David"', $address->header() );
	}

	#[Group( 'address' )]
	#[DataProvider( 'header_injection_attempts' )]
	public function test_address_rejects_header_injection( string $email, string $name ): void {
		$this->expectException( InvalidArgumentException::class );

		new Address( $email, $name );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function header_injection_attempts(): array {
		return array(
			'newline in email'   => array( "a@b.com\nBcc: evil@x.com", '' ),
			'carriage in email'  => array( "a@b.com\rBcc: evil@x.com", '' ),
			'newline in name'    => array( 'a@b.com', "Dave\nBcc: evil@x.com" ),
			'null byte in name'  => array( 'a@b.com', "Dave\x00" ),
		);
	}

	#[Group( 'address' )]
	public function test_address_rejects_a_malformed_email(): void {
		$this->expectException( InvalidArgumentException::class );

		new Address( 'not-an-email' );
	}

	#[Group( 'address' )]
	public function test_address_parses_the_angle_bracket_form(): void {
		$address = Address::parse( 'Dave Sherlock <dave@example.com>' );

		$this->assertSame( 'dave@example.com', $address->email );
		$this->assertSame( 'Dave Sherlock', $address->name );
	}

	#[Group( 'address' )]
	public function test_address_parses_a_quoted_display_name(): void {
		$address = Address::parse( '"Sherlock, David" <dave@example.com>' );

		$this->assertSame( 'Sherlock, David', $address->name );
	}

	#[Group( 'address' )]
	public function test_address_from_accepts_every_shape(): void {
		$existing = new Address( 'a@b.com', 'A' );

		$this->assertSame( $existing, Address::from( $existing ) );
		$this->assertSame( 'a@b.com', Address::from( 'a@b.com' )->email );
		$this->assertSame( 'A', Address::from( array( 'a@b.com', 'A' ) )->name );
	}

	#[Group( 'address' )]
	public function test_address_reports_its_domain(): void {
		$this->assertSame( 'example.com', ( new Address( 'dave@example.com' ) )->domain() );
	}

	/* ─── Message ───────────────────────────────────────────────────── */

	#[Group( 'message' )]
	public function test_message_builds_fluently(): void {
		$message = Message::to( 'dave@example.com' )
			->from( 'billing@shop.com', 'Shop' )
			->subject( 'Your receipt' )
			->html( '<p>Thanks</p>' )
			->text( 'Thanks' );

		$this->assertCount( 1, $message->recipients() );
		$this->assertSame( 'billing@shop.com', $message->sender()?->email );
		$this->assertSame( 'Your receipt', $message->get_subject() );
		$this->assertSame( 'Thanks', $message->get_text() );
	}

	#[Group( 'message' )]
	public function test_message_accepts_several_recipients_at_once(): void {
		$message = Message::to( array( 'a@b.com', 'c@d.com', new Address( 'e@f.com', 'E' ) ) );

		$this->assertCount( 3, $message->recipients() );
		$this->assertSame( 'E', $message->recipients()[2]->name );
	}

	#[Group( 'message' )]
	public function test_message_rejects_a_subject_containing_a_newline(): void {
		$this->expectException( InvalidArgumentException::class );

		Message::to( 'a@b.com' )->subject( "Hi\nBcc: evil@x.com" );
	}

	#[Group( 'message' )]
	public function test_message_rejects_an_invalid_header_name(): void {
		$this->expectException( InvalidArgumentException::class );

		Message::to( 'a@b.com' )->header( 'Bad Header', 'value' );
	}

	#[Group( 'message' )]
	public function test_message_rejects_a_header_value_containing_a_newline(): void {
		$this->expectException( InvalidArgumentException::class );

		Message::to( 'a@b.com' )->header( 'X-Thing', "a\r\nBcc: evil@x.com" );
	}

	#[Group( 'message' )]
	public function test_message_derives_text_from_html_when_absent(): void {
		$message = Message::to( 'a@b.com' )->html( '<p>Hello <strong>world</strong></p>' );

		$this->assertSame( 'Hello world', $message->get_text() );
	}

	/**
	 * A stripped-tags conversion loses the URL entirely, which is fatal
	 * for a message whose whole point is a download link.
	 */
	#[Group( 'message' )]
	public function test_derived_text_keeps_link_targets(): void {
		$message = Message::to( 'a@b.com' )->html( '<a href="https://x.com/d">Download</a>' );

		$this->assertSame( 'Download (https://x.com/d)', $message->get_text() );
	}

	#[Group( 'message' )]
	public function test_derived_text_turns_breaks_into_newlines(): void {
		$message = Message::to( 'a@b.com' )->html( '<p>One</p><p>Two</p>' );

		$this->assertSame( "One\nTwo", $message->get_text() );
	}

	#[Group( 'message' )]
	public function test_derived_text_decodes_entities(): void {
		$message = Message::to( 'a@b.com' )->html( '<p>Tom &amp; Jerry &mdash; friends</p>' );

		$this->assertSame( 'Tom & Jerry — friends', $message->get_text() );
	}

	#[Group( 'message' )]
	public function test_explicit_text_wins_over_derivation(): void {
		$message = Message::to( 'a@b.com' )->html( '<p>HTML</p>' )->text( 'Plain' );

		$this->assertSame( 'Plain', $message->get_text() );
	}

	#[Group( 'message' )]
	public function test_message_reports_what_it_is_missing(): void {
		$problems = ( new Message() )->problems();

		$this->assertCount( 4, $problems );
	}

	#[Group( 'message' )]
	public function test_a_complete_message_has_no_problems(): void {
		$message = Message::to( 'a@b.com' )->from( 'c@d.com' )->subject( 'Hi' )->text( 'Body' );

		$this->assertSame( array(), $message->problems() );
	}

	#[Group( 'message' )]
	public function test_message_carries_metadata_and_a_tag(): void {
		$message = Message::to( 'a@b.com' )->meta( 'order_id', '1234' )->tag( 'receipt' );

		$this->assertSame( array( 'order_id' => '1234' ), $message->get_metadata() );
		$this->assertSame( 'receipt', $message->get_tag() );
	}

	/* ─── Attachment ────────────────────────────────────────────────── */

	#[Group( 'attachment' )]
	public function test_attachment_encodes_its_content(): void {
		$attachment = new Attachment( 'a.txt', 'hello', 'text/plain' );

		$this->assertSame( base64_encode( 'hello' ), $attachment->encoded() );
		$this->assertSame( 5, $attachment->size() );
		$this->assertFalse( $attachment->is_inline() );
	}

	#[Group( 'attachment' )]
	public function test_attachment_with_a_content_id_is_inline(): void {
		$attachment = new Attachment( 'logo.png', 'bytes', 'image/png', 'logo' );

		$this->assertTrue( $attachment->is_inline() );
	}

	#[Group( 'attachment' )]
	public function test_attachment_reads_a_file(): void {
		$path = tempnam( sys_get_temp_dir(), 'sm' );
		file_put_contents( $path, 'contents' );

		try {
			$attachment = Attachment::from_path( $path, 'named.txt' );

			$this->assertSame( 'named.txt', $attachment->filename );
			$this->assertSame( 'contents', $attachment->content );
		} finally {
			unlink( $path );
		}
	}

	#[Group( 'attachment' )]
	public function test_attachment_throws_for_a_missing_file(): void {
		$this->expectException( RuntimeException::class );

		Attachment::from_path( '/nonexistent/nope.txt' );
	}

	/* ─── MIME ──────────────────────────────────────────────────────── */

	#[Group( 'mime' )]
	public function test_mime_writes_the_core_headers(): void {
		$mime = Mime::build( $this->message() );

		$this->assertStringContainsString( 'MIME-Version: 1.0', $mime );
		$this->assertStringContainsString( 'From: "Shop" <billing@shop.com>', $mime );
		$this->assertStringContainsString( 'To: dave@example.com', $mime );
		$this->assertStringContainsString( 'Subject: Your receipt', $mime );
		$this->assertStringContainsString( 'Message-ID: <', $mime );
		$this->assertStringContainsString( '@shop.com>', $mime );
	}

	#[Group( 'mime' )]
	public function test_mime_uses_crlf_line_endings(): void {
		$mime = Mime::build( $this->message() );

		$this->assertSame( 0, preg_match( '/(?<!\r)\n/', $mime ), 'A bare LF is an RFC 5322 violation.' );
	}

	/**
	 * Writing Bcc into the headers shows every blind recipient to every
	 * other one.
	 */
	#[Group( 'mime' )]
	public function test_mime_never_writes_bcc(): void {
		$message = $this->message()->bcc( 'secret@example.com' );

		$this->assertStringNotContainsString( 'secret@example.com', Mime::build( $message ) );
	}

	#[Group( 'mime' )]
	public function test_mime_writes_cc(): void {
		$message = $this->message()->cc( 'copy@example.com' );

		$this->assertStringContainsString( 'Cc: copy@example.com', Mime::build( $message ) );
	}

	#[Group( 'mime' )]
	public function test_text_and_html_produce_multipart_alternative(): void {
		$mime = Mime::build( $this->message() );

		$this->assertStringContainsString( 'Content-Type: multipart/alternative;', $mime );
		$this->assertStringContainsString( 'Content-Type: text/plain; charset=UTF-8', $mime );
		$this->assertStringContainsString( 'Content-Type: text/html; charset=UTF-8', $mime );
	}

	#[Group( 'mime' )]
	public function test_text_only_produces_a_single_plain_part(): void {
		$message = Message::to( 'a@b.com' )->from( 'c@d.com' )->subject( 'Hi' )->text( 'Body' );
		$mime    = Mime::build( $message );

		$this->assertStringContainsString( 'Content-Type: text/plain; charset=UTF-8', $mime );
		$this->assertStringNotContainsString( 'multipart', $mime );
	}

	#[Group( 'mime' )]
	public function test_attachments_produce_multipart_mixed(): void {
		$message = $this->message()->attach( new Attachment( 'invoice.pdf', 'PDFBYTES', 'application/pdf' ) );
		$mime    = Mime::build( $message );

		$this->assertStringContainsString( 'Content-Type: multipart/mixed;', $mime );
		$this->assertStringContainsString( 'Content-Disposition: attachment; filename="invoice.pdf"', $mime );
		$this->assertStringContainsString( base64_encode( 'PDFBYTES' ), $mime );
	}

	/**
	 * An inline image needs multipart/related, or the `cid:` reference
	 * does not resolve and the logo arrives as a second download.
	 */
	#[Group( 'mime' )]
	public function test_inline_images_produce_multipart_related(): void {
		$message = $this->message()->attach( new Attachment( 'logo.png', 'PNG', 'image/png', 'logo' ) );
		$mime    = Mime::build( $message );

		$this->assertStringContainsString( 'Content-Type: multipart/related;', $mime );
		$this->assertStringContainsString( 'Content-ID: <logo>', $mime );
		$this->assertStringContainsString( 'Content-Disposition: inline;', $mime );
	}

	#[Group( 'mime' )]
	public function test_inline_and_attached_nest_related_inside_mixed(): void {
		$message = $this->message()
			->attach( new Attachment( 'logo.png', 'PNG', 'image/png', 'logo' ) )
			->attach( new Attachment( 'invoice.pdf', 'PDF', 'application/pdf' ) );

		$mime = Mime::build( $message );

		$mixed   = strpos( $mime, 'multipart/mixed' );
		$related = strpos( $mime, 'multipart/related' );

		$this->assertIsInt( $mixed );
		$this->assertIsInt( $related );
		$this->assertLessThan( $related, $mixed, 'mixed must be the outer container.' );
	}

	#[Group( 'mime' )]
	public function test_boundaries_are_unique_per_level(): void {
		$message = $this->message()->attach( new Attachment( 'a.pdf', 'X', 'application/pdf' ) );

		preg_match_all( '/boundary="([^"]+)"/', Mime::build( $message ), $matches );

		$this->assertCount( 2, $matches[1] );
		$this->assertNotSame( $matches[1][0], $matches[1][1] );
	}

	#[Group( 'mime' )]
	public function test_non_ascii_subjects_are_encoded_words(): void {
		$message = $this->message()->subject( 'Rechnung für Müller' );

		$this->assertStringContainsString( '=?UTF-8?B?', Mime::build( $message ) );
	}

	#[Group( 'mime' )]
	public function test_ascii_headers_are_left_alone(): void {
		$this->assertSame( 'Plain subject', Mime::encode_header( 'Plain subject' ) );
	}

	#[Group( 'mime' )]
	public function test_encoded_words_round_trip(): void {
		$original = 'Rechnung für Herrn Müller — Übersicht';
		$encoded  = Mime::encode_header( $original );

		$this->assertSame( $original, mb_decode_mimeheader( $encoded ) );
	}

	/**
	 * Splitting on bytes rather than characters cuts a multi-byte
	 * sequence in half and produces mojibake at each chunk boundary.
	 */
	#[Group( 'mime' )]
	public function test_long_non_ascii_headers_split_on_character_boundaries(): void {
		$original = str_repeat( 'Übersicht über Zahlungen ', 12 );
		$encoded  = Mime::encode_header( $original );

		$this->assertStringContainsString( "\r\n ", $encoded, 'A long value should fold.' );
		$this->assertSame( $original, mb_decode_mimeheader( $encoded ) );
	}

	#[Group( 'mime' )]
	public function test_quoted_printable_normalises_newlines(): void {
		// Every flavour of line ending becomes a CRLF hard break, and
		// none survives as an encoded `=0A` — which would decode to a
		// bare newline in the delivered body.
		$encoded = Mime::quoted_printable( "one\ntwo\r\nthree\rfour" );

		$this->assertSame( "one\r\ntwo\r\nthree\r\nfour", $encoded );
		$this->assertStringNotContainsString( '=0A', $encoded );
	}

	#[Group( 'mime' )]
	public function test_quoted_printable_soft_breaks_survive(): void {
		$encoded = Mime::quoted_printable( str_repeat( 'x', 200 ) );

		$this->assertStringContainsString( "=\r\n", $encoded );
		$this->assertSame( 0, preg_match( '/=\r\r\n/', $encoded ) );
	}

	#[Group( 'mime' )]
	public function test_quoted_printable_survives_a_round_trip(): void {
		$original = "Hello — Müller\nSecond line";

		$this->assertSame(
			str_replace( "\n", "\r\n", $original ),
			quoted_printable_decode( Mime::quoted_printable( $original ) )
		);
	}

	/**
	 * A line that is a single dot terminates DATA, so a message
	 * containing one truncates itself unless it is stuffed.
	 */
	#[Group( 'mime' )]
	public function test_dot_stuffing_doubles_a_leading_dot(): void {
		$this->assertSame( "..hidden\r\n", Mime::dot_stuff( ".hidden\r\n" ) );
		$this->assertSame( "a\r\n..\r\nb", Mime::dot_stuff( "a\r\n.\r\nb" ) );
		$this->assertSame( "a\r\nb.c", Mime::dot_stuff( "a\r\nb.c" ) );
	}

	/* ─── Mailer ────────────────────────────────────────────────────── */

	#[Group( 'mailer' )]
	public function test_mailer_sends_through_its_transport(): void {
		$transport = new Memory();
		$result    = ( new Mailer( $transport ) )->send( $this->message() );

		$this->assertTrue( $result->sent );
		$this->assertSame( 1, $transport->count() );
		$this->assertTrue( $transport->sent_to( 'dave@example.com' ) );
	}

	#[Group( 'mailer' )]
	public function test_mailer_applies_a_default_sender(): void {
		$transport = new Memory();
		$mailer    = new Mailer( $transport, from: new Address( 'noreply@shop.com', 'Shop' ) );

		(void) $mailer->send( Message::to( 'a@b.com' )->subject( 'Hi' )->text( 'Body' ) );

		$this->assertSame( 'noreply@shop.com', $transport->last()?->sender()?->email );
	}

	#[Group( 'mailer' )]
	public function test_an_explicit_sender_beats_the_default(): void {
		$transport = new Memory();
		$mailer    = new Mailer( $transport, from: new Address( 'noreply@shop.com' ) );

		(void) $mailer->send( Message::to( 'a@b.com' )->from( 'real@shop.com' )->subject( 'Hi' )->text( 'B' ) );

		$this->assertSame( 'real@shop.com', $transport->last()?->sender()?->email );
	}

	#[Group( 'mailer' )]
	public function test_mailer_applies_a_default_reply_to(): void {
		$transport = new Memory();
		$mailer    = new Mailer( $transport, from: new Address( 'noreply@shop.com' ), reply_to: new Address( 'help@shop.com' ) );

		(void) $mailer->send( Message::to( 'a@b.com' )->subject( 'Hi' )->text( 'B' ) );

		$this->assertSame( 'help@shop.com', $transport->last()?->reply_address()?->email );
	}

	#[Group( 'mailer' )]
	public function test_mailer_refuses_an_incomplete_message(): void {
		$transport = new Memory();
		$result    = ( new Mailer( $transport ) )->send( Message::to( 'a@b.com' ) );

		$this->assertFalse( $result->sent );
		$this->assertFalse( $result->retryable );
		$this->assertSame( 0, $transport->count() );
	}

	/**
	 * The usual way a staging site emails real customers is that nobody
	 * set this.
	 */
	#[Group( 'mailer' )]
	public function test_redirect_diverts_every_message(): void {
		$transport = new Memory();
		$mailer    = new Mailer( $transport, redirect: 'staging@dev.local' );

		(void) $mailer->send( $this->message() );

		$last = $transport->last();

		$this->assertSame( 'staging@dev.local', $last?->recipients()[0]->email );
		$this->assertFalse( $transport->sent_to( 'dave@example.com' ) );
		$this->assertStringContainsString( 'dave@example.com', (string) $last?->get_subject() );
		$this->assertSame( 'dave@example.com', $last?->custom_headers()['X-Original-To'] ?? '' );
	}

	#[Group( 'mailer' )]
	public function test_redirect_keeps_attachments_and_metadata(): void {
		$transport = new Memory();
		$mailer    = new Mailer( $transport, redirect: 'staging@dev.local' );

		(void) $mailer->send( $this->message()->attach( new Attachment( 'a.pdf', 'X' ) )->meta( 'order_id', '9' ) );

		$this->assertCount( 1, $transport->last()?->attachments() ?? array() );
		$this->assertSame( array( 'order_id' => '9' ), $transport->last()?->get_metadata() );
	}

	#[Group( 'mailer' )]
	public function test_mailer_reports_a_transport_failure(): void {
		$result = ( new Mailer( new Memory( succeed: false ) ) )->send( $this->message() );

		$this->assertTrue( $result->failed_send() );
		$this->assertTrue( $result->retryable );
		$this->assertSame( 'Simulated failure', $result->error );
	}

	#[Group( 'mailer' )]
	public function test_mailer_exposes_its_transport(): void {
		$this->assertSame( 'memory', ( new Mailer( new Memory() ) )->transport()->name() );
	}

	/* ─── Transports ────────────────────────────────────────────────── */

	#[Group( 'transport' )]
	public function test_memory_transport_collects_and_clears(): void {
		$transport = new Memory();

		$transport->send( $this->message() );
		$transport->send( $this->message() );

		$this->assertSame( 2, $transport->count() );
		$this->assertCount( 2, $transport->messages() );

		$transport->clear();

		$this->assertSame( 0, $transport->count() );
		$this->assertNull( $transport->last() );
	}

	#[Group( 'transport' )]
	public function test_memory_matches_recipients_case_insensitively(): void {
		$transport = new Memory();
		$transport->send( Message::to( 'Dave@Example.com' )->from( 'a@b.com' )->subject( 'x' )->text( 'y' ) );

		$this->assertTrue( $transport->sent_to( 'dave@example.com' ) );
	}

	#[Group( 'transport' )]
	public function test_log_transport_writes_the_serialised_message(): void {
		$path = tempnam( sys_get_temp_dir(), 'sm-log' );

		try {
			$result = ( new Log( $path ) )->send( $this->message() );
			$log    = (string) file_get_contents( $path );

			$this->assertTrue( $result->sent );
			$this->assertNotSame( '', $result->message_id );
			$this->assertStringContainsString( 'Subject: Your receipt', $log );
			$this->assertStringContainsString( 'dave@example.com', $log );
		} finally {
			unlink( $path );
		}
	}

	#[Group( 'transport' )]
	public function test_log_transport_appends(): void {
		$path = tempnam( sys_get_temp_dir(), 'sm-log' );

		try {
			$transport = new Log( $path );
			$transport->send( $this->message() );
			$transport->send( $this->message() );

			$this->assertSame( 2, substr_count( (string) file_get_contents( $path ), 'Subject: Your receipt' ) );
		} finally {
			unlink( $path );
		}
	}

	#[Group( 'transport' )]
	public function test_log_transport_reports_an_unwritable_path(): void {
		$result = ( new Log( '/nonexistent/dir/mail.log' ) )->send( $this->message() );

		$this->assertFalse( $result->sent );
	}

	/* ─── SendResult ────────────────────────────────────────────────── */

	#[Group( 'result' )]
	public function test_send_result_shapes(): void {
		$ok = SendResult::ok( 'abc', 202 );

		$this->assertTrue( $ok->sent );
		$this->assertFalse( $ok->failed_send() );
		$this->assertSame( 'abc', $ok->message_id );
		$this->assertSame( 202, $ok->status );

		$bad = SendResult::failed( 'nope', 500, true );

		$this->assertFalse( $bad->sent );
		$this->assertTrue( $bad->failed_send() );
		$this->assertTrue( $bad->retryable );
	}

	/* ─── Helpers ───────────────────────────────────────────────────── */

	/**
	 * A complete message, for tests that only care about one aspect.
	 */
	private function message(): Message {
		return Message::to( 'dave@example.com' )
			->from( 'billing@shop.com', 'Shop' )
			->subject( 'Your receipt' )
			->html( '<p>Thanks for your order.</p>' )
			->text( 'Thanks for your order.' );
	}
}
