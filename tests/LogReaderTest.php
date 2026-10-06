<?php
/**
 * Reading the log transport back.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Mailer\Attachment;
use SugarCommerce\Mailer\Message;
use SugarCommerce\Mailer\Transports\{Log, LogReader};

/**
 * Class LogReaderTest
 *
 * Round trips: whatever Log wrote, LogReader gives back as it was before it
 * was encoded.
 */
#[CoversClass( LogReader::class )]
final class LogReaderTest extends TestCase {

	private string $path = '';

	protected function setUp(): void {
		$this->path = sys_get_temp_dir() . '/mailer-log-' . bin2hex( random_bytes( 6 ) ) . '.log';
	}

	protected function tearDown(): void {
		@unlink( $this->path );
	}

	/**
	 * Log one message.
	 *
	 * @param Message $message Message.
	 *
	 * @return string The id Log gave it.
	 */
	private function log( Message $message ): string {
		return (string) ( new Log( $this->path ) )->send( $message )->message_id;
	}

	public function test_a_logged_message_reads_back_decoded(): void {
		// Long enough that quoted-printable soft-wraps it, with `=` in it,
		// which is where a reader that does not decode shows `=3D`.
		$url  = 'https://get.example.com/purchases/' . str_repeat( 'a1b2c3', 20 ) . '?next=/orders';
		$html = '<p>Hello — <a href="' . $url . '">your orders</a></p>';

		$id = $this->log(
			Message::to( 'buyer@example.com' )
				->from( 'shop@example.com', 'Café Records' )
				->subject( 'Your orders — ready' )
				->html( $html )
		);

		$messages = ( new LogReader( $this->path ) )->messages();

		$this->assertCount( 1, $messages );
		$this->assertSame( $id, $messages[0]['id'] );
		$this->assertSame( 'Your orders — ready', $messages[0]['subject'] );
		$this->assertSame( 'buyer@example.com', $messages[0]['to'] );
		$this->assertStringContainsString( 'Café Records', $messages[0]['from'] );
		$this->assertSame( $html, $messages[0]['html'] );
		$this->assertStringContainsString( $url, $messages[0]['text'], 'The derived text part keeps the link.' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC$/', $messages[0]['logged'] );
	}

	public function test_newest_first_and_limited(): void {
		foreach ( array( 'One', 'Two', 'Three' ) as $subject ) {
			$this->log( Message::to( 'a@example.com' )->from( 'b@example.com' )->subject( $subject )->text( $subject ) );
		}

		$reader = new LogReader( $this->path );

		$this->assertSame( array( 'Three', 'Two', 'One' ), array_column( $reader->messages(), 'subject' ) );
		$this->assertSame( array( 'Three' ), array_column( $reader->messages( 1 ), 'subject' ) );
		$this->assertSame( 'One', $reader->messages()[2]['text'] );
		$this->assertSame( '', $reader->messages()[2]['html'] );
	}

	/**
	 * An attachment is not the message, even an HTML one.
	 */
	public function test_an_attachment_is_not_the_body(): void {
		$this->log(
			Message::to( 'a@example.com' )
				->from( 'b@example.com' )
				->subject( 'With a file' )
				->html( '<p>The body</p>' )
				->attach( new Attachment( 'page.html', '<p>Not the body</p>', 'text/html' ) )
		);

		$this->assertSame( '<p>The body</p>', ( new LogReader( $this->path ) )->messages()[0]['html'] );
	}

	public function test_find_by_id(): void {
		$this->log( Message::to( 'a@example.com' )->from( 'b@example.com' )->subject( 'First' )->text( 'x' ) );
		$id = $this->log( Message::to( 'a@example.com' )->from( 'b@example.com' )->subject( 'Second' )->text( 'y' ) );

		$reader = new LogReader( $this->path );

		$this->assertSame( 'Second', $reader->find( $id )['subject'] ?? null );
		$this->assertNull( $reader->find( 'nope' ) );
		$this->assertNull( $reader->find( '' ) );
	}

	public function test_no_log_is_no_messages(): void {
		$this->assertSame( array(), ( new LogReader( $this->path ) )->messages() );
	}

	/**
	 * Only the end of a big log is read, starting at a whole entry.
	 */
	public function test_a_long_log_is_read_from_a_whole_entry(): void {
		for ( $i = 1; $i <= 20; $i++ ) {
			$this->log( Message::to( 'a@example.com' )->from( 'b@example.com' )->subject( 'Message ' . $i )->text( str_repeat( 'Lorem ipsum. ', 40 ) ) );
		}

		$messages = ( new LogReader( $this->path, max_bytes: 4_000 ) )->messages( 100 );

		$this->assertNotEmpty( $messages );
		$this->assertLessThan( 20, count( $messages ) );
		$this->assertSame( 'Message 20', $messages[0]['subject'] );

		foreach ( $messages as $message ) {
			$this->assertMatchesRegularExpression( '/^Message \d+$/', $message['subject'] );
		}
	}
}
