<?php
/**
 * RFC 5322 / MIME serialisation.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer;

/**
 * Class Mime
 *
 * Turns a {@see Message} into the bytes an SMTP server expects. The API
 * transports do not need this — they take structured JSON — but SMTP
 * and Amazon's `SendRawEmail` both do.
 *
 * The structure produced depends on what the message carries:
 *
 * - text only            → `text/plain`
 * - text + html          → `multipart/alternative`
 * - the above + inline   → `multipart/related`
 * - the above + files    → `multipart/mixed`
 *
 * Nesting them in that order is what makes an inline logo appear in the
 * body rather than as a second download, and what makes a text client
 * pick the plain part instead of showing markup.
 *
 * @since 1.0.0
 */
final class Mime {

	/**
	 * Longest line before quoted-printable soft-wraps.
	 *
	 * RFC 5322 caps a line at 998 characters, but 76 is the historic
	 * limit every agent handles without complaint.
	 *
	 * @since 1.0.0
	 */
	private const LINE_LENGTH = 76;

	/**
	 * Serialise a message.
	 *
	 * @since 1.0.0
	 *
	 * @param Message  $message  Message.
	 * @param callable $boundary Boundary generator, for deterministic
	 *                           output in tests.
	 *
	 * @return string Headers and body, CRLF-delimited.
	 */
	public static function build( Message $message, ?callable $boundary = null ): string {
		$boundary ??= static fn(): string => '=_' . bin2hex( random_bytes( 16 ) );

		$headers = self::headers( $message );
		$parts   = self::body( $message, $boundary );

		return self::render( array_merge( $headers, $parts['headers'] ) ) . "\r\n" . $parts['body'];
	}

	/**
	 * The top-level headers, excluding content type.
	 *
	 * @since 1.0.0
	 *
	 * @param Message $message Message.
	 *
	 * @return array<string, string>
	 */
	private static function headers( Message $message ): array {
		$headers = array( 'MIME-Version' => '1.0' );

		if ( null !== $message->sender() ) {
			$headers['From'] = self::address( $message->sender() );
		}

		if ( array() !== $message->recipients() ) {
			$headers['To'] = self::addresses( $message->recipients() );
		}

		if ( array() !== $message->cc_recipients() ) {
			$headers['Cc'] = self::addresses( $message->cc_recipients() );
		}

		// Bcc is deliberately absent: those addresses go in the envelope
		// only. Writing them here shows every blind recipient to every
		// other one.

		if ( null !== $message->reply_address() ) {
			$headers['Reply-To'] = self::address( $message->reply_address() );
		}

		$headers['Subject'] = self::encode_header( $message->get_subject() );
		$headers['Date']    = gmdate( 'D, d M Y H:i:s' ) . ' +0000';

		if ( null !== $message->sender() ) {
			$headers['Message-ID'] = '<' . bin2hex( random_bytes( 16 ) ) . '@' . $message->sender()->domain() . '>';
		}

		return array_merge( $headers, $message->custom_headers() );
	}

	/**
	 * Build the body and the content headers that describe it.
	 *
	 * @since 1.0.0
	 *
	 * @param Message  $message  Message.
	 * @param callable $boundary Boundary generator.
	 *
	 * @return array{headers: array<string, string>, body: string}
	 */
	private static function body( Message $message, callable $boundary ): array {
		$text = $message->get_text();
		$html = $message->get_html();

		$inline = array_values( array_filter( $message->attachments(), static fn( Attachment $a ): bool => $a->is_inline() ) );
		$files  = array_values( array_filter( $message->attachments(), static fn( Attachment $a ): bool => ! $a->is_inline() ) );

		// Innermost: the readable content.
		if ( '' !== $html && '' !== $text ) {
			$mark = $boundary();
			$body = self::multipart( $mark, array(
				self::part( 'text/plain; charset=UTF-8', $text ),
				self::part( 'text/html; charset=UTF-8', $html ),
			) );
			$type = 'multipart/alternative; boundary="' . $mark . '"';
		} elseif ( '' !== $html ) {
			$body = self::quoted_printable( $html );
			$type = 'text/html; charset=UTF-8';
		} else {
			$body = self::quoted_printable( $text );
			$type = 'text/plain; charset=UTF-8';
		}

		$encoding = str_starts_with( $type, 'multipart/' ) ? '' : 'quoted-printable';

		// Then wrap for inline images, so `cid:` references resolve.
		if ( array() !== $inline ) {
			$mark  = $boundary();
			$block = array( self::raw_part( $type, $encoding, $body ) );

			foreach ( $inline as $attachment ) {
				$block[] = self::attachment_part( $attachment );
			}

			$body     = self::multipart( $mark, $block );
			$type     = 'multipart/related; boundary="' . $mark . '"';
			$encoding = '';
		}

		// Outermost: real attachments.
		if ( array() !== $files ) {
			$mark  = $boundary();
			$block = array( self::raw_part( $type, $encoding, $body ) );

			foreach ( $files as $attachment ) {
				$block[] = self::attachment_part( $attachment );
			}

			$body     = self::multipart( $mark, $block );
			$type     = 'multipart/mixed; boundary="' . $mark . '"';
			$encoding = '';
		}

		$headers = array( 'Content-Type' => $type );

		if ( '' !== $encoding ) {
			$headers['Content-Transfer-Encoding'] = $encoding;
		}

		return array( 'headers' => $headers, 'body' => $body );
	}

	/**
	 * Join parts with a boundary.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $boundary Boundary token.
	 * @param string[] $parts    Rendered parts.
	 *
	 * @return string
	 */
	private static function multipart( string $boundary, array $parts ): string {
		$out = '';

		foreach ( $parts as $part ) {
			$out .= '--' . $boundary . "\r\n" . $part . "\r\n";
		}

		return $out . '--' . $boundary . "--\r\n";
	}

	/**
	 * A quoted-printable text part.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type    Content type.
	 * @param string $content Content.
	 *
	 * @return string
	 */
	private static function part( string $type, string $content ): string {
		return self::raw_part( $type, 'quoted-printable', self::quoted_printable( $content ) );
	}

	/**
	 * A part from an already-encoded body.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type     Content type.
	 * @param string $encoding Transfer encoding, or empty for none.
	 * @param string $body     Encoded body.
	 *
	 * @return string
	 */
	private static function raw_part( string $type, string $encoding, string $body ): string {
		$headers = array( 'Content-Type' => $type );

		if ( '' !== $encoding ) {
			$headers['Content-Transfer-Encoding'] = $encoding;
		}

		return self::render( $headers ) . "\r\n" . $body;
	}

	/**
	 * An attachment part.
	 *
	 * @since 1.0.0
	 *
	 * @param Attachment $attachment Attachment.
	 *
	 * @return string
	 */
	private static function attachment_part( Attachment $attachment ): string {
		$disposition = $attachment->is_inline() ? 'inline' : 'attachment';

		$headers = array(
			'Content-Type'              => $attachment->mime_type . '; name="' . self::encode_header( $attachment->filename ) . '"',
			'Content-Transfer-Encoding' => 'base64',
			'Content-Disposition'       => $disposition . '; filename="' . self::encode_header( $attachment->filename ) . '"',
		);

		if ( $attachment->is_inline() ) {
			$headers['Content-ID'] = '<' . $attachment->content_id . '>';
		}

		return self::render( $headers ) . "\r\n" . chunk_split( $attachment->encoded(), self::LINE_LENGTH, "\r\n" );
	}

	/**
	 * Render a header map.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $headers Headers.
	 *
	 * @return string
	 */
	private static function render( array $headers ): string {
		$out = '';

		foreach ( $headers as $name => $value ) {
			$out .= $name . ': ' . $value . "\r\n";
		}

		return $out;
	}

	/**
	 * One address in header form.
	 *
	 * @since 1.0.0
	 *
	 * @param Address $address Address.
	 *
	 * @return string
	 */
	private static function address( Address $address ): string {
		if ( '' === $address->name ) {
			return $address->email;
		}

		// An encoded-word must not be quoted — the quotes would be taken
		// as part of the name and displayed. An ASCII name must be, or a
		// comma in it splits the header into two recipients.
		if ( 1 === preg_match( '/[\x80-\xFF]/', $address->name ) ) {
			return self::encode_header( $address->name ) . ' <' . $address->email . '>';
		}

		return $address->header();
	}

	/**
	 * A list of addresses in header form.
	 *
	 * @since 1.0.0
	 *
	 * @param Address[] $addresses Addresses.
	 *
	 * @return string
	 */
	private static function addresses( array $addresses ): string {
		return implode( ', ', array_map( static fn( Address $a ): string => self::address( $a ), $addresses ) );
	}

	/**
	 * Encode a header value for non-ASCII content.
	 *
	 * Headers are ASCII-only on the wire, so anything else has to be
	 * wrapped in RFC 2047 encoded-words — otherwise an accented sender
	 * name or a subject with an em dash arrives as mojibake.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	public static function encode_header( string $value ): string {
		if ( 1 !== preg_match( '/[\x80-\xFF]/', $value ) ) {
			return $value;
		}

		// Base64 rather than Q-encoding: it is shorter for text that is
		// mostly non-ASCII, and it cannot produce a stray "?=" mid-word.
		$chunks = array();
		$limit  = 45; // Keeps each encoded-word inside the 75-char cap.

		foreach ( self::split_utf8( $value, $limit ) as $chunk ) {
			$chunks[] = '=?UTF-8?B?' . base64_encode( $chunk ) . '?=';
		}

		return implode( "\r\n ", $chunks );
	}

	/**
	 * Split a UTF-8 string without cutting a multi-byte sequence.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Value.
	 * @param int    $bytes Maximum bytes per chunk.
	 *
	 * @return string[]
	 */
	private static function split_utf8( string $value, int $bytes ): array {
		$chunks  = array();
		$current = '';

		foreach ( preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $char ) {
			if ( strlen( $current ) + strlen( $char ) > $bytes ) {
				$chunks[] = $current;
				$current  = '';
			}

			$current .= $char;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return array() === $chunks ? array( '' ) : $chunks;
	}

	/**
	 * Quoted-printable encode with CRLF line endings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw text.
	 *
	 * @return string
	 */
	public static function quoted_printable( string $value ): string {
		// Normalise to CRLF *before* encoding. PHP encodes a literal
		// newline as `=0A`, so feeding it LF yields a body that decodes
		// to bare newlines; feeding it CRLF yields `=0D=0A`, which is
		// what a text part is meant to contain.
		//
		// Encoding first and fixing newlines after is not an option: the
		// output already contains `=\r\n` soft breaks, and rewriting
		// newlines in it corrupts every one of them.
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

		return quoted_printable_encode( str_replace( "\n", "\r\n", $value ) );
	}

	/**
	 * Dot-stuff a body for the SMTP DATA command.
	 *
	 * A line consisting of a single dot ends DATA, so any line that
	 * legitimately starts with one has to be doubled — otherwise a
	 * message can truncate itself mid-body.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data Serialised message.
	 *
	 * @return string
	 */
	public static function dot_stuff( string $data ): string {
		if ( str_starts_with( $data, '.' ) ) {
			$data = '.' . $data;
		}

		return str_replace( "\r\n.", "\r\n..", $data );
	}
}
