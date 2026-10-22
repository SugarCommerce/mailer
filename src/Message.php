<?php
/**
 * An email message.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer;

/**
 * Class Message
 *
 * What to send, independent of how it gets sent.
 *
 * Keeping this separate from the transport is what lets you render a
 * message and preview it, queue it, log it, or deliver it through a
 * different provider tomorrow — without any of those concerns leaking
 * into the other.
 *
 * Typical use:
 *
 *   $message = Message::to( 'dave@example.com' )
 *       ->from( 'billing@sugarcart.com', 'Sugarcart' )
 *       ->subject( 'Your receipt' )
 *       ->html( $html )
 *       ->text( $plain )
 *       ->attach( Attachment::from_path( '/tmp/receipt.pdf' ) );
 *
 *   $mailer->send( $message );
 *
 * @since 1.0.0
 */
final class Message {

	/**
	 * Primary recipients.
	 *
	 * @var list<Address>
	 */
	private array $to = array();

	/**
	 * Carbon-copy recipients.
	 *
	 * @var list<Address>
	 */
	private array $cc = array();

	/**
	 * Blind-copy recipients: envelope only, never written as a header.
	 *
	 * @var list<Address>
	 */
	private array $bcc = array();

	/**
	 * The sender, or null until one is set.
	 *
	 * @var Address|null
	 */
	private ?Address $from = null;

	/**
	 * Where replies go, or null for the sender.
	 *
	 * @var Address|null
	 */
	private ?Address $reply_to = null;

	/**
	 * The subject line, never containing a line break.
	 *
	 * @var string
	 */
	private string $subject = '';

	/**
	 * The HTML body.
	 *
	 * @var string
	 */
	private string $html = '';

	/**
	 * The plain-text body, or empty to derive one from the HTML.
	 *
	 * @var string
	 */
	private string $text = '';

	/**
	 * Files and inline images.
	 *
	 * @var list<Attachment>
	 */
	private array $attachments = array();

	/**
	 * Custom headers, by name.
	 *
	 * @var array<string, string>
	 */
	private array $headers = array();

	/**
	 * Provider metadata, echoed back on webhooks.
	 *
	 * @var array<string, string>
	 */
	private array $metadata = array();

	/**
	 * Provider reporting tag.
	 *
	 * @var string
	 */
	private string $tag = '';

	/**
	 * Start a message to one or more recipients.
	 *
	 * @since 1.0.0
	 *
	 * @param Address|string|array<int, Address|string> $to Recipients.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When a recipient is not a valid
	 *                                   address.
	 */
	public static function to( Address|string|array $to ): self {
		$message = new self();

		foreach ( is_array( $to ) ? $to : array( $to ) as $recipient ) {
			$message->to[] = Address::from( $recipient );
		}

		return $message;
	}

	/* ─── Recipients ────────────────────────────────────────────────── */

	/**
	 * Add a recipient.
	 *
	 * @since 1.0.0
	 *
	 * @param Address|string $address Recipient.
	 * @param string         $name    Display name.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the address is not valid.
	 */
	public function add_to( Address|string $address, string $name = '' ): self {
		$this->to[] = '' !== $name && is_string( $address ) ? new Address( $address, $name ) : Address::from( $address );

		return $this;
	}

	/**
	 * Add a carbon copy.
	 *
	 * @since 1.0.0
	 *
	 * @param Address|string $address Recipient.
	 * @param string         $name    Display name.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the address is not valid.
	 */
	public function cc( Address|string $address, string $name = '' ): self {
		$this->cc[] = '' !== $name && is_string( $address ) ? new Address( $address, $name ) : Address::from( $address );

		return $this;
	}

	/**
	 * Add a blind carbon copy.
	 *
	 * @since 1.0.0
	 *
	 * @param Address|string $address Recipient.
	 * @param string         $name    Display name.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the address is not valid.
	 */
	public function bcc( Address|string $address, string $name = '' ): self {
		$this->bcc[] = '' !== $name && is_string( $address ) ? new Address( $address, $name ) : Address::from( $address );

		return $this;
	}

	/**
	 * Set the sender.
	 *
	 * @since 1.0.0
	 *
	 * @param Address|string $address Sender.
	 * @param string         $name    Display name.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the address is not valid.
	 */
	public function from( Address|string $address, string $name = '' ): self {
		$this->from = '' !== $name && is_string( $address ) ? new Address( $address, $name ) : Address::from( $address );

		return $this;
	}

	/**
	 * Set the reply-to address.
	 *
	 * Worth setting on transactional mail: the From address is often a
	 * no-reply on a subdomain, and a customer hitting reply should still
	 * reach somebody.
	 *
	 * @since 1.0.0
	 *
	 * @param Address|string $address Reply address.
	 * @param string         $name    Display name.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the address is not valid.
	 */
	public function reply_to( Address|string $address, string $name = '' ): self {
		$this->reply_to = '' !== $name && is_string( $address ) ? new Address( $address, $name ) : Address::from( $address );

		return $this;
	}

	/* ─── Content ───────────────────────────────────────────────────── */

	/**
	 * Set the subject.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subject Subject line.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When it contains a line break.
	 */
	public function subject( string $subject ): self {
		// A newline here is header injection — it would let the caller
		// append arbitrary headers to the message.
		if ( 1 === preg_match( '/[\r\n\x00]/', $subject ) ) {
			throw new \InvalidArgumentException( 'A subject cannot contain line breaks.' );
		}

		$this->subject = $subject;

		return $this;
	}

	/**
	 * Set the HTML body.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html HTML.
	 *
	 * @return self
	 */
	public function html( string $html ): self {
		$this->html = $html;

		return $this;
	}

	/**
	 * Set the plain-text body.
	 *
	 * Always send one. A message with only an HTML part scores worse with
	 * spam filters, and is unreadable in a text client or a screen
	 * reader that has fallen back.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Plain text.
	 *
	 * @return self
	 */
	public function text( string $text ): self {
		$this->text = $text;

		return $this;
	}

	/**
	 * Attach a file.
	 *
	 * @since 1.0.0
	 *
	 * @param Attachment $attachment File.
	 *
	 * @return self
	 */
	public function attach( Attachment $attachment ): self {
		$this->attachments[] = $attachment;

		return $this;
	}

	/**
	 * Set a custom header.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException On an invalid name or a value
	 *                                   containing a line break.
	 */
	public function header( string $name, string $value ): self {
		if ( 1 !== preg_match( '/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/D', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid header name: ' . $name );
		}

		if ( 1 === preg_match( '/[\r\n\x00]/', $value ) ) {
			throw new \InvalidArgumentException( 'Header values cannot contain line breaks.' );
		}

		$this->headers[ $name ] = $value;

		return $this;
	}

	/**
	 * Attach metadata for the provider to echo back on webhooks.
	 *
	 * The practical use: put your order id here, and a bounce or
	 * complaint webhook tells you which order it belongs to without a
	 * lookup by address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 *
	 * @return self
	 */
	public function meta( string $key, string $value ): self {
		$this->metadata[ $key ] = $value;

		return $this;
	}

	/**
	 * Tag the message for provider-side reporting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tag Category, e.g. `receipt` or `password-reset`.
	 *
	 * @return self
	 */
	public function tag( string $tag ): self {
		$this->tag = $tag;

		return $this;
	}

	/* ─── Reading ───────────────────────────────────────────────────── */

	/**
	 * The primary recipients.
	 *
	 * @since 1.0.0
	 *
	 * @return list<Address>
	 */
	public function recipients(): array {
		return $this->to;
	}

	/**
	 * The carbon-copy recipients.
	 *
	 * @since 1.0.0
	 *
	 * @return list<Address>
	 */
	public function cc_recipients(): array {
		return $this->cc;
	}

	/**
	 * The blind-copy recipients.
	 *
	 * @since 1.0.0
	 *
	 * @return list<Address>
	 */
	public function bcc_recipients(): array {
		return $this->bcc;
	}

	/**
	 * The sender.
	 *
	 * @since 1.0.0
	 *
	 * @return Address|null Null until one is set.
	 */
	public function sender(): ?Address {
		return $this->from;
	}

	/**
	 * The reply-to address.
	 *
	 * @since 1.0.0
	 *
	 * @return Address|null Null when replies go to the sender.
	 */
	public function reply_address(): ?Address {
		return $this->reply_to;
	}

	/**
	 * The subject line.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_subject(): string {
		return $this->subject;
	}

	/**
	 * The HTML body.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_html(): string {
		return $this->html;
	}

	/**
	 * The plain-text body, derived from the HTML when not set.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_text(): string {
		if ( '' !== $this->text ) {
			return $this->text;
		}

		if ( '' === $this->html ) {
			return '';
		}

		// A crude fallback, and better than sending nothing: links become
		// "text (url)", which is the part a stripped-tags conversion
		// loses entirely — and losing it guts a message whose whole
		// purpose is a download link.
		//
		// Parentheses, not angle brackets: strip_tags would eat "<url>"
		// as a tag on the very next line.
		$text = preg_replace( '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $this->html ) ?? $this->html;
		$text = preg_replace( '/<(br|\/p|\/div|\/tr|\/h[1-6])[^>]*>/i', "\n", $text ) ?? $text;
		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[ \t]+/', ' ', $text ) ?? $text;
		$text = preg_replace( '/\n{3,}/', "\n\n", $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * The attachments, files and inline images alike.
	 *
	 * @since 1.0.0
	 *
	 * @return list<Attachment>
	 */
	public function attachments(): array {
		return $this->attachments;
	}

	/**
	 * The custom headers.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Name => value.
	 */
	public function custom_headers(): array {
		return $this->headers;
	}

	/**
	 * The provider metadata.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Key => value.
	 */
	public function get_metadata(): array {
		return $this->metadata;
	}

	/**
	 * The reporting tag.
	 *
	 * @since 1.0.0
	 *
	 * @return string Empty when untagged.
	 */
	public function get_tag(): string {
		return $this->tag;
	}

	/**
	 * Everything the message is missing before it can be sent.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string> Empty when ready.
	 */
	public function problems(): array {
		$problems = array();

		if ( array() === $this->to ) {
			$problems[] = 'The message has no recipient.';
		}

		if ( null === $this->from ) {
			$problems[] = 'The message has no sender.';
		}

		if ( '' === trim( $this->subject ) ) {
			$problems[] = 'The message has no subject.';
		}

		if ( '' === $this->html && '' === $this->text ) {
			$problems[] = 'The message has no body.';
		}

		return $problems;
	}
}
