<?php
/**
 * Showing what the log transport wrote, in a browser.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer\Transports;

use Closure;

/**
 * Class LogViewer
 *
 * The page half of LogReader. A development outbox needs three things the
 * reader leaves to its caller, and every caller wrote the same three:
 *
 * - Links that work here. An email names the production host, because
 *   that is where a real one must point, and on a development box a
 *   magic link should open the same route on this machine in one click.
 *   That rule is the caller's — a map of hosts to local bases, or a
 *   closure over each URL — and is applied to both bodies before anything
 *   else sees them.
 * - The links themselves, for buttons beside the preview: hrefs from the
 *   HTML with their entities decoded, or URLs from the text when there is
 *   no HTML. Only http and https, so one can go straight into an `<a>`.
 * - A document to preview, which is the part that has to be safe. A
 *   message carries whatever a customer typed into it, so the document
 *   opens with a Content-Security-Policy that runs no script, embeds no
 *   plugin or frame and submits no form, ahead of any of the message's
 *   own markup. A policy can only be tightened by another, so nothing in
 *   the message can loosen it. frame() puts it in a sandboxed iframe
 *   without allow-scripts, so neither guarantee rests on the other.
 *
 * Header values (subject, from, to) are returned as LogReader decodes
 * them, unescaped: escape them wherever they are printed.
 *
 * @since 1.4.0
 */
final readonly class LogViewer {

	/**
	 * What the preview document may do: draw itself, and nothing else.
	 *
	 * Images, styles and fonts load from anywhere, because an email's logo
	 * and web fonts are remote. Scripts, plugins, frames and form posts do
	 * not. Inline event handlers are scripts, so `onerror=` is covered too.
	 *
	 * @since 1.4.0
	 */
	public const string POLICY = "script-src 'none'; object-src 'none'; frame-src 'none'; child-src 'none'; worker-src 'none'; form-action 'none'";

	/**
	 * The sandbox frame() gives the iframe.
	 *
	 * No allow-scripts, and no allow-same-origin either, so adding scripts
	 * later can never also hand the frame the page's origin. Popups are
	 * allowed, and escape the sandbox, so a link opens as a normal tab.
	 *
	 * @since 1.4.0
	 */
	public const string SANDBOX = 'allow-popups allow-popups-to-escape-sandbox';

	/**
	 * A URL in a body: http or https, up to whitespace, a quote, an angle
	 * bracket or a parenthesis (a derived text part puts each link in
	 * parentheses after its label).
	 *
	 * @since 1.4.0
	 */
	private const string URL = '#https?://[^\s"\'<>()]+#i';

	/**
	 * Turns each URL in a body into the one to show.
	 *
	 * @var Closure(string): string
	 */
	private Closure $rewrite;

	/**
	 * Point the viewer at a log.
	 *
	 * @since 1.4.0
	 *
	 * @param LogReader                                     $reader  The log.
	 * @param array<string, string>|(Closure(string): string) $rewrite Each
	 *        production host => the base URL it is served from here, e.g.
	 *        `'shop.example.com' => 'http://127.0.0.1:8080'`. A host is
	 *        matched whole — followed by a path, a query, a fragment or the
	 *        end — so `shop.example.com.evil.test` is left alone. Or a
	 *        closure given each URL as it appears in the body (in HTML,
	 *        still entity-encoded) and returning its replacement. Empty
	 *        leaves every URL as it was.
	 */
	public function __construct(
		private LogReader $reader,
		array|Closure $rewrite = array(),
	) {
		$this->rewrite = $rewrite instanceof Closure ? $rewrite : self::hosts( $rewrite );
	}

	/**
	 * The logged messages, newest first, ready to show.
	 *
	 * @since 1.4.0
	 *
	 * @param int $limit Most messages to return.
	 *
	 * @return array<int, array{id: string, logged: string, subject: string, from: string, to: string, date: string, headers: array<string, string>, html: string, text: string, document: string, links: list<string>}>
	 *         As LogReader returns them, with both bodies rewritten, the
	 *         document() to frame and the links() to offer.
	 */
	public function messages( int $limit = 50 ): array {
		return array_map( $this->view( ... ), $this->reader->messages( $limit ) );
	}

	/**
	 * One message by its log id, ready to show, or null.
	 *
	 * @since 1.4.0
	 *
	 * @param string $id The id Log returned as the message id.
	 *
	 * @return array{id: string, logged: string, subject: string, from: string, to: string, date: string, headers: array<string, string>, html: string, text: string, document: string, links: list<string>}|null
	 *         As messages() returns them.
	 */
	public function find( string $id ): ?array {
		$message = $this->reader->find( $id );

		return null === $message ? null : $this->view( $message );
	}

	/**
	 * A body with every URL in it rewritten by this viewer's rule.
	 *
	 * @since 1.4.0
	 *
	 * @param string $content HTML or text.
	 *
	 * @return string
	 */
	public function rewrite( string $content ): string {
		return (string) preg_replace_callback( self::URL, fn ( array $url ): string => ( $this->rewrite )( $url[0] ), $content );
	}

	/**
	 * The links in a message, for one-click buttons.
	 *
	 * From the HTML's hrefs when there is HTML, entities decoded — the
	 * derived text part repeats each of them anyway — and from the text
	 * otherwise, without the sentence's closing punctuation. Only http and
	 * https: a `javascript:` href, however it is encoded, is not a link to
	 * offer. In order, each once.
	 *
	 * @since 1.4.0
	 *
	 * @param string $html HTML body.
	 * @param string $text Text body.
	 *
	 * @return list<string>
	 */
	public static function links( string $html, string $text ): array {
		$links = array();

		if ( '' !== trim( $html ) ) {
			preg_match_all( '#[\s"\'/]href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i', $html, $matches, PREG_SET_ORDER );

			foreach ( $matches as $match ) {
				// Browsers drop tabs and newlines from a URL, and so does this.
				$href = html_entity_decode( implode( '', array_slice( $match, 1 ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$href = trim( str_replace( array( "\t", "\n", "\r" ), '', $href ) );

				if ( 1 === preg_match( '#^https?://.#i', $href ) ) {
					$links[] = $href;
				}
			}
		} else {
			preg_match_all( self::URL, $text, $matches );

			$links = array_map( static fn ( string $link ): string => rtrim( $link, '.,;:!?' ), $matches[0] );
		}

		return array_values( array_unique( $links ) );
	}

	/**
	 * The document to preview a message in.
	 *
	 * The policy and a `<base target="_blank">` come first, before a byte
	 * of the message: a policy only governs what follows it, and the first
	 * base with a target wins, so a message's own cannot keep a link inside
	 * the frame. A text-only message is shown as text, escaped.
	 *
	 * @since 1.4.0
	 *
	 * @param string $html HTML body.
	 * @param string $text Text body, shown when there is no HTML.
	 *
	 * @return string A whole document, for an iframe's srcdoc (see frame()).
	 */
	public static function document( string $html, string $text ): string {
		// POLICY has no quote, ampersand or angle bracket to escape.
		$head = '<meta http-equiv="Content-Security-Policy" content="' . self::POLICY . '">'
			. '<base target="_blank">';

		if ( '' === trim( $html ) ) {
			return '<!doctype html><meta charset="utf-8">' . $head
				. '<pre style="font:13px/1.5 ui-monospace,monospace;white-space:pre-wrap;margin:16px">'
				. htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</pre>';
		}

		// After the message's doctype if it opens with one, so it keeps its
		// rendering mode; otherwise at the very start. Before an <html> or
		// a <head> is fine: the parser opens the head for it.
		if ( 1 === preg_match( '/^(?:\s|<!--.*?-->)*<!doctype[^>]*>/is', $html, $doctype ) ) {
			return $doctype[0] . $head . substr( $html, strlen( $doctype[0] ) );
		}

		return $head . $html;
	}

	/**
	 * An iframe that shows a document, sandboxed.
	 *
	 * The document is escaped for the attribute, so nothing in it can close
	 * the attribute or the tag. Size and style it from outside with
	 * `$attributes`.
	 *
	 * @since 1.4.0
	 *
	 * @param string                $document   From document().
	 * @param string                $title      The frame's accessible name,
	 *                                          e.g. the subject.
	 * @param array<string, string> $attributes More attributes, e.g. class.
	 *                                          `sandbox` and `srcdoc` are
	 *                                          this method's and are ignored.
	 *
	 * @return string
	 */
	public static function frame( string $document, string $title = '', array $attributes = array() ): string {
		$attributes = array( 'title' => $title, 'srcdoc' => $document, 'sandbox' => self::SANDBOX )
			+ array_diff_key( $attributes, array( 'srcdoc' => true, 'sandbox' => true ) )
			+ array( 'loading' => 'lazy' );

		$html = '<iframe';

		foreach ( $attributes as $name => $value ) {
			// A name that is not one cannot be escaped into one: drop it.
			if ( 1 === preg_match( '/^[a-z][a-z0-9-]*$/i', $name ) ) {
				$html .= ' ' . $name . '="' . htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
			}
		}

		return $html . '></iframe>';
	}

	/**
	 * One read message, rewritten, with its document and links.
	 *
	 * @since 1.4.0
	 *
	 * @param array{id: string, logged: string, subject: string, from: string, to: string, date: string, headers: array<string, string>, html: string, text: string} $message From LogReader.
	 *
	 * @return array{id: string, logged: string, subject: string, from: string, to: string, date: string, headers: array<string, string>, html: string, text: string, document: string, links: list<string>}
	 */
	private function view( array $message ): array {
		$html = $this->rewrite( $message['html'] );
		$text = $this->rewrite( $message['text'] );

		return array_merge( $message, array(
			'html'     => $html,
			'text'     => $text,
			'document' => self::document( $html, $text ),
			'links'    => self::links( $html, $text ),
		) );
	}

	/**
	 * The rule for a map of hosts.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, string> $hosts Host => base URL.
	 *
	 * @return Closure(string): string
	 */
	private static function hosts( array $hosts ): Closure {
		$patterns = array();

		foreach ( $hosts as $host => $base ) {
			// A host that is all digits arrives as an int key.
			$host = (string) $host;

			if ( '' !== $host ) {
				$patterns[ '#^https?://' . preg_quote( $host, '#' ) . '(?=[/?\#]|$)#i' ] = rtrim( $base, '/' );
			}
		}

		return static function ( string $url ) use ( $patterns ): string {
			foreach ( $patterns as $pattern => $base ) {
				$rewritten = preg_replace_callback( $pattern, static fn (): string => $base, $url, 1, $count );

				if ( null !== $rewritten && $count > 0 ) {
					return $rewritten;
				}
			}

			return $url;
		};
	}
}
