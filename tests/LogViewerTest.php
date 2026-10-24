<?php
/**
 * Showing the log transport in a browser.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Mailer\Message;
use SugarCommerce\Mailer\Transports\{Log, LogReader, LogViewer};

/**
 * Class LogViewerTest
 *
 * What Log wrote, through LogReader, to a page: links pointed here, links
 * offered as buttons, and a preview that runs nothing a message brought.
 */
#[CoversClass( LogViewer::class )]
final class LogViewerTest extends TestCase {

	private const string HERE = 'http://127.0.0.1:8080';

	private string $path = '';

	protected function setUp(): void {
		$this->path = sys_get_temp_dir() . '/mailer-view-' . bin2hex( random_bytes( 6 ) ) . '.log';
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

	/**
	 * A viewer over this test's log, with the shop's two hosts mapped here.
	 *
	 * @return LogViewer
	 */
	private function viewer(): LogViewer {
		return new LogViewer(
			new LogReader( $this->path ),
			array(
				'shop.example.com' => self::HERE,
				'app.example.com'  => self::HERE . '/app/',
			)
		);
	}

	/**
	 * The first part of a document, where the message's markup must not be.
	 *
	 * @param string $document From document().
	 *
	 * @return string Everything before the policy and base end.
	 */
	private static function head( string $document ): string {
		$end = strpos( $document, '<base target="_blank">' );

		self::assertNotFalse( $end );

		return substr( $document, 0, $end );
	}

	/* ─── Messages ──────────────────────────────────────────────────── */

	/**
	 * HTML and its derived text part: both rewritten, links from the HTML.
	 */
	public function test_a_multipart_message_is_rewritten_and_its_links_offered(): void {
		$id = $this->log(
			Message::to( 'buyer@example.com' )
				->from( 'shop@example.com', 'Café Records' )
				->subject( 'Sign in — your orders' )
				->html( '<p><a href="https://shop.example.com/purchases/abc123">Your orders</a> <a href="https://app.example.com/orders/7">Library</a> <a href="https://elsewhere.example/x">Theirs</a></p>' )
		);

		$messages = $this->viewer()->messages();

		$this->assertCount( 1, $messages );
		$this->assertSame( $id, $messages[0]['id'] );
		$this->assertSame( 'Sign in — your orders', $messages[0]['subject'] );
		$this->assertSame( 'buyer@example.com', $messages[0]['to'] );
		$this->assertStringContainsString( 'href="' . self::HERE . '/purchases/abc123"', $messages[0]['html'] );
		$this->assertStringContainsString( '(' . self::HERE . '/app/orders/7)', $messages[0]['text'] );
		$this->assertStringNotContainsString( 'shop.example.com', $messages[0]['html'] . $messages[0]['text'] . $messages[0]['document'] );
		$this->assertSame(
			array( self::HERE . '/purchases/abc123', self::HERE . '/app/orders/7', 'https://elsewhere.example/x' ),
			$messages[0]['links']
		);
		$this->assertStringContainsString( 'href="' . self::HERE . '/purchases/abc123"', $messages[0]['document'] );
	}

	public function test_an_html_only_message_is_framed_as_html(): void {
		$this->log( Message::to( 'a@example.com' )->from( 'b@example.com' )->subject( 'HTML' )->html( '<h1>Hello</h1>' ) );

		$message = $this->viewer()->messages()[0];

		$this->assertStringEndsWith( '<h1>Hello</h1>', $message['document'] );
		$this->assertStringStartsWith( '<meta http-equiv="Content-Security-Policy"', $message['document'] );
		$this->assertSame( array(), $message['links'] );
	}

	/**
	 * No HTML: the text is shown as text, escaped, and its links found.
	 */
	public function test_a_text_only_message_is_shown_as_text(): void {
		$this->log(
			Message::to( 'a@example.com' )
				->from( 'b@example.com' )
				->subject( 'Text' )
				->text( "Sign in: https://shop.example.com/enter/xyz.\nOr <b>not</b> (https://elsewhere.example/a?b=1&c=2), thanks!" )
		);

		$message = $this->viewer()->messages()[0];

		$this->assertSame( '', $message['html'] );
		$this->assertStringContainsString( self::HERE . '/enter/xyz.', $message['text'] );
		$this->assertStringContainsString( '<pre', $message['document'] );
		$this->assertStringContainsString( 'Or &lt;b&gt;not&lt;/b&gt;', $message['document'] );
		$this->assertStringNotContainsString( '<b>', $message['document'] );
		$this->assertSame( array( self::HERE . '/enter/xyz', 'https://elsewhere.example/a?b=1&c=2' ), $message['links'] );
	}

	public function test_newest_first_limited_and_found_by_id(): void {
		$first  = $this->log( Message::to( 'a@example.com' )->from( 'b@example.com' )->subject( 'One' )->text( 'https://shop.example.com/1' ) );
		$second = $this->log( Message::to( 'a@example.com' )->from( 'b@example.com' )->subject( 'Two' )->text( 'x' ) );

		$viewer = $this->viewer();

		$this->assertSame( array( 'Two', 'One' ), array_column( $viewer->messages(), 'subject' ) );
		$this->assertSame( array( 'Two' ), array_column( $viewer->messages( 1 ), 'subject' ) );
		$this->assertSame( $second, $viewer->find( $second )['id'] ?? null );
		$this->assertSame( array( self::HERE . '/1' ), $viewer->find( $first )['links'] ?? null );
		$this->assertNull( $viewer->find( 'nope' ) );
	}

	public function test_no_log_is_no_messages(): void {
		$this->assertSame( array(), $this->viewer()->messages() );
	}

	/* ─── Rewriting ─────────────────────────────────────────────────── */

	/**
	 * Only a mapped host, and only whole.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function urls(): array {
		return array(
			'path'                 => array( 'https://shop.example.com/d/x', self::HERE . '/d/x' ),
			'bare host'            => array( 'https://shop.example.com', self::HERE ),
			'query'                => array( 'http://shop.example.com?a=1', self::HERE . '?a=1' ),
			'fragment'             => array( 'https://shop.example.com#top', self::HERE . '#top' ),
			'any case'             => array( 'HTTPS://Shop.Example.com/x', self::HERE . '/x' ),
			'mounted at a path'    => array( 'https://app.example.com/orders', self::HERE . '/app/orders' ),
			'in parentheses'       => array( 'Orders (https://shop.example.com)', 'Orders (' . self::HERE . ')' ),
			'lookalike suffix'     => array( 'https://shop.example.com.evil.test/x', 'https://shop.example.com.evil.test/x' ),
			'lookalike prefix'     => array( 'https://myshop.example.com/x', 'https://myshop.example.com/x' ),
			'a port is not ours'   => array( 'https://shop.example.com:8443/x', 'https://shop.example.com:8443/x' ),
			'another host'         => array( 'https://elsewhere.example/x', 'https://elsewhere.example/x' ),
			'not a link'           => array( 'shop.example.com/x', 'shop.example.com/x' ),
			'in an attribute'      => array( '<a href=\'https://shop.example.com/a?b=1&amp;c=2\'>', '<a href=\'' . self::HERE . '/a?b=1&amp;c=2\'>' ),
		);
	}

	#[DataProvider( 'urls' )]
	public function test_a_host_map_rewrites_only_its_hosts( string $content, string $expected ): void {
		$this->assertSame( $expected, $this->viewer()->rewrite( $content ) );
	}

	public function test_a_closure_sees_each_url(): void {
		$seen   = array();
		$viewer = new LogViewer(
			new LogReader( $this->path ),
			static function ( string $url ) use ( &$seen ): string {
				$seen[] = $url;

				return strtoupper( $url );
			}
		);

		$this->assertSame( 'A HTTPS://X.TEST/A?B=1&AMP;C=2 b <i>HTTP://Y.TEST</i>', $viewer->rewrite( 'A https://x.test/a?b=1&amp;c=2 b <i>http://y.test</i>' ) );
		$this->assertSame( array( 'https://x.test/a?b=1&amp;c=2', 'http://y.test' ), $seen );
	}

	public function test_no_rule_leaves_urls_alone(): void {
		$this->assertSame( 'See https://shop.example.com/x', ( new LogViewer( new LogReader( $this->path ) ) )->rewrite( 'See https://shop.example.com/x' ) );
	}

	/* ─── Links ─────────────────────────────────────────────────────── */

	/**
	 * Entities decoded, each link once, in order, whatever the quoting.
	 */
	public function test_links_with_entities_are_decoded(): void {
		$html = '<a href="https://x.test/a?b=1&amp;c=2">A</a>'
			. "<a href='https://x.test/&#x62;?q=&quot;hi&quot;'>B</a>"
			. '<a href=https://x.test/c>C</a>'
			. '<A HREF = "https://x.test/a?b=1&amp;c=2">Again</A>'
			. '<a href="https://x.test/d?e=&lt;f&gt;">D</a>';

		$this->assertSame(
			array( 'https://x.test/a?b=1&c=2', 'https://x.test/b?q="hi"', 'https://x.test/c', 'https://x.test/d?e=<f>' ),
			LogViewer::links( $html, 'https://x.test/text-is-ignored-when-there-is-html' )
		);
	}

	/**
	 * Nothing but http and https, however it is spelled.
	 */
	public function test_links_are_only_web_links(): void {
		$html = '<a href="javascript:alert(1)">1</a>'
			. '<a href="jav&#x61;script:alert(1)">2</a>'
			. "<a href=\"java\nscript:alert(1)\">3</a>"
			. '<a href="data:text/html,<script>alert(1)</script>">4</a>'
			. '<a href="mailto:a@example.com">5</a>'
			. '<a href="/relative">6</a>'
			. '<a data-href="https://x.test/not-an-href">7</a>'
			. "<a href=\" https://x.test/o\nk \">8</a>";

		$this->assertSame( array( 'https://x.test/ok' ), LogViewer::links( $html, '' ) );
	}

	public function test_links_from_text_lose_closing_punctuation(): void {
		$this->assertSame(
			array( 'https://x.test/a', 'https://x.test/b?c=d', 'http://x.test/e' ),
			LogViewer::links( '', "Go to https://x.test/a. Or https://x.test/b?c=d! See \"http://x.test/e\", or https://x.test/a again." )
		);
	}

	/* ─── The preview document ──────────────────────────────────────── */

	/**
	 * The policy forbids script, plugins, frames and forms.
	 */
	public function test_the_policy_runs_nothing(): void {
		foreach ( array( "script-src 'none'", "object-src 'none'", "frame-src 'none'", "form-action 'none'" ) as $directive ) {
			$this->assertStringContainsString( $directive, LogViewer::POLICY );
		}

		$document = LogViewer::document( '<p>Hi</p>', '' );

		$this->assertStringContainsString( 'content="' . LogViewer::POLICY . '"', $document );
	}

	/**
	 * A message's own doctype is kept first, so it keeps its mode; the
	 * policy follows it at once, before the message's <html> and <head>.
	 */
	public function test_the_policy_follows_a_doctype_and_precedes_the_head(): void {
		$html     = "<!-- builder -->\n<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\">\n<html><head><script>alert(1)</script><title>T</title></head><body>B</body></html>";
		$document = LogViewer::document( $html, '' );

		$this->assertStringStartsWith( "<!-- builder -->\n<!DOCTYPE html PUBLIC", $document );
		$this->assertSame( strpos( $document, '>' . "\n<html>" ) + 1, strpos( $document, "\n<html>" ) );
		$this->assertLessThan( strpos( $document, '<html>' ), strpos( $document, 'Content-Security-Policy' ) );
		$this->assertStringEndsWith( substr( $html, (int) strpos( $html, "\n<html>" ) ), $document );
	}

	/**
	 * A `<header>` is not a `<head>`, and a script before the head is
	 * still after the policy.
	 */
	public function test_markup_before_the_head_is_still_after_the_policy(): void {
		$document = LogViewer::document( '<header>Top</header><script>alert(1)</script><head><base target="_self"></head>', '' );

		$this->assertStringStartsWith( '<meta http-equiv="Content-Security-Policy"', $document );
		$this->assertStringNotContainsString( '<script', self::head( $document ) );
		$this->assertLessThan( strpos( $document, '<base target="_self">' ), strpos( $document, '<base target="_blank">' ), 'The first base with a target wins.' );
	}

	/**
	 * Hostile markup in a message: it may stay in the document — the
	 * policy and the sandbox are what stop it — but none of it comes
	 * before them, none of it reaches a link, and none of it gets out of
	 * the frame's attribute.
	 */
	public function test_malicious_html_in_a_message(): void {
		$evil = '<script>alert(document.cookie)</script>'
			. '<img src=x onerror="alert(1)">'
			. '<meta http-equiv="Content-Security-Policy" content="script-src *">'
			. '<a href="javascript:alert(1)">Click</a>'
			. '<iframe src="https://evil.test"></iframe>'
			. '"></iframe><script>alert(2)</script>';

		$this->log( Message::to( 'a@example.com' )->from( 'b@example.com' )->subject( '"><script>alert(3)</script>' )->html( $evil ) );

		$message = $this->viewer()->messages()[0];

		$this->assertStringStartsWith( '<meta http-equiv="Content-Security-Policy" content="' . LogViewer::POLICY . '">', $message['document'] );
		$this->assertStringNotContainsString( '<script', self::head( $message['document'] ) );
		$this->assertSame( array(), $message['links'] );

		$frame = LogViewer::frame( $message['document'], $message['subject'] );

		$this->assertSame( 1, preg_match_all( '/<iframe\b/i', $frame ), 'The message cannot open a frame of its own on the page.' );
		$this->assertSame( 1, substr_count( $frame, '</iframe>' ) );
		$this->assertStringNotContainsString( '<script', $frame );
		$this->assertMatchesRegularExpression( '/^<iframe(?: [a-z]+="[^"<>]*")+><\/iframe>$/', $frame, 'One tag, every value quoted and nothing in one that can end it.' );
		$this->assertStringContainsString( 'sandbox="' . LogViewer::SANDBOX . '"', $frame );
		$this->assertStringNotContainsString( 'allow-scripts', $frame );
		$this->assertStringNotContainsString( 'allow-same-origin', $frame );

		// What the browser gets back from the attribute is the document.
		$this->assertSame( 1, preg_match( '/srcdoc="([^"]*)"/', $frame, $srcdoc ) );
		$this->assertSame( $message['document'], html_entity_decode( $srcdoc[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	public function test_text_that_looks_like_markup_is_text(): void {
		$document = LogViewer::document( '', "</pre><script>alert(1)</script>\xFF" );

		$this->assertStringNotContainsString( '<script', $document );
		$this->assertSame( 1, substr_count( $document, '</pre>' ) );
		$this->assertStringContainsString( '&lt;/pre&gt;&lt;script&gt;', $document );
		$this->assertTrue( mb_check_encoding( $document, 'UTF-8' ), 'An invalid byte is replaced, not a reason to show nothing.' );
	}

	/* ─── The frame ─────────────────────────────────────────────────── */

	/**
	 * The sandbox and srcdoc are the frame's own; a bad attribute name is
	 * dropped rather than escaped into a working one.
	 */
	public function test_the_frame_keeps_its_sandbox(): void {
		$frame = LogViewer::frame( '<p>x</p>', 'Re: "you"', array(
			'class'          => 'h-96 w-full',
			'sandbox'        => 'allow-scripts allow-same-origin',
			'srcdoc'         => '<script>alert(1)</script>',
			'x onload=alert' => '1',
		) );

		$this->assertStringContainsString( 'title="Re: &quot;you&quot;"', $frame );
		$this->assertStringContainsString( 'srcdoc="&lt;p&gt;x&lt;/p&gt;"', $frame );
		$this->assertStringContainsString( 'sandbox="' . LogViewer::SANDBOX . '"', $frame );
		$this->assertStringContainsString( 'class="h-96 w-full"', $frame );
		$this->assertStringContainsString( 'loading="lazy"', $frame );
		$this->assertStringNotContainsString( 'allow-scripts', $frame );
		$this->assertStringNotContainsString( 'x onload', $frame );
		$this->assertSame( 1, substr_count( $frame, 'sandbox=' ) );
		$this->assertSame( 1, substr_count( $frame, 'srcdoc=' ) );
	}
}
