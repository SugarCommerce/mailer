<?php
/**
 * Reading back what the log transport wrote.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer\Transports;

/**
 * Class LogReader
 *
 * The other half of Log. A log of raw MIME is exactly what went out, which
 * is its point, and exactly what nobody wants to read: quoted-printable
 * soft breaks through every link, `=3D` in every attribute, the subject in
 * base64. This turns each entry back into what a mail client would show —
 * the subject, who it was for, and the HTML and text bodies, decoded — so a
 * development box can show its outbox as a page and its links can be
 * clicked.
 *
 * Read-only and forgiving. An entry it cannot make sense of is returned with
 * whatever it could decode rather than dropped: a log is a development
 * tool, and a message that silently vanished from it would be the one
 * worth looking at.
 *
 * Only the tail of a large log is read (see $max_bytes), because the file is
 * appended to for as long as a box runs and the newest messages are the
 * ones anybody wants.
 *
 * @since 1.2.0
 */
final readonly class LogReader {

	/**
	 * The rule Log draws above and below each entry's stamp.
	 *
	 * @since 1.2.0
	 */
	private const string RULE = '========================================================================';

	/**
	 * @since 1.2.0
	 *
	 * @param string $path      The file Log appends to.
	 * @param int    $max_bytes Most of the file's end to read.
	 */
	public function __construct(
		private string $path,
		private int $max_bytes = 4_194_304,
	) {}

	/**
	 * The logged messages, newest first.
	 *
	 * @since 1.2.0
	 *
	 * @param int $limit Most messages to return.
	 *
	 * @return array<int, array{id: string, logged: string, subject: string, from: string, to: string, date: string, headers: array<string, string>, html: string, text: string}>
	 */
	public function messages( int $limit = 50 ): array {
		return array_slice( array_reverse( self::parse( $this->tail() ) ), 0, max( 0, $limit ) );
	}

	/**
	 * One message by its log id, or null.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id The id Log returned as the message id.
	 *
	 * @return array|null As messages() returns them.
	 */
	public function find( string $id ): ?array {
		foreach ( self::parse( $this->tail() ) as $message ) {
			if ( '' !== $id && $message['id'] === $id ) {
				return $message;
			}
		}

		return null;
	}

	/**
	 * Every entry in a log's text, oldest first.
	 *
	 * @since 1.2.0
	 *
	 * @param string $log What Log wrote, or the end of it.
	 *
	 * @return array<int, array{id: string, logged: string, subject: string, from: string, to: string, date: string, headers: array<string, string>, html: string, text: string}>
	 */
	public static function parse( string $log ): array {
		$log   = str_replace( "\r\n", "\n", $log );
		$rule  = preg_quote( self::RULE, '/' );
		$parts = preg_split( '/^' . $rule . '\n(.*)\n' . $rule . '\n/m', $log, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		$messages = array();

		// [ before the first entry, stamp, body, stamp, body, … ]
		for ( $i = 1; $i + 1 < count( $parts ); $i += 2 ) {
			[ $logged, $id ] = array_map( 'trim', explode( '·', $parts[ $i ], 2 ) + array( '', '' ) );

			$messages[] = array( 'id' => $id, 'logged' => $logged ) + self::message( rtrim( $parts[ $i + 1 ], "\n" ) );
		}

		return $messages;
	}

	/**
	 * One serialised message, decoded.
	 *
	 * @since 1.2.0
	 *
	 * @param string $raw Headers and body, as Mime::build() makes them.
	 *
	 * @return array{subject: string, from: string, to: string, date: string, headers: array<string, string>, html: string, text: string}
	 */
	public static function message( string $raw ): array {
		[ $headers, $body ] = self::split( str_replace( "\r\n", "\n", $raw ) );

		$bodies = array( 'html' => '', 'text' => '' );
		self::walk( $headers, $body, $bodies );

		return array(
			'subject' => $headers['subject'] ?? '',
			'from'    => $headers['from'] ?? '',
			'to'      => $headers['to'] ?? '',
			'date'    => $headers['date'] ?? '',
			'headers' => $headers,
		) + $bodies;
	}

	/**
	 * The end of the log, starting at an entry.
	 *
	 * @since 1.2.0
	 *
	 * @return string '' when there is no log yet.
	 */
	private function tail(): string {
		$size = is_file( $this->path ) ? (int) filesize( $this->path ) : 0;

		if ( $size <= 0 ) {
			return '';
		}

		$from = max( 0, $size - $this->max_bytes );
		$text = (string) @file_get_contents( $this->path, false, null, $from );

		if ( 0 === $from ) {
			return $text;
		}

		// Cut mid-entry: start at the first whole one. Its rule is the
		// first of two, so look for a rule that a stamp line follows.
		$start = preg_match( '/^' . preg_quote( self::RULE, '/' ) . '\r?\n[^=\r\n]/m', $text, $match, PREG_OFFSET_CAPTURE ) ? (int) $match[0][1] : strlen( $text );

		return substr( $text, $start );
	}

	/**
	 * Collect the first HTML and plain-text bodies, depth first.
	 *
	 * Attachments are skipped, including an attached .html file: what the
	 * reader wants is the message, not what came with it.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, string>    $headers Lower-cased headers of this part.
	 * @param string                   $body    Its body, still encoded.
	 * @param array{html: string, text: string} $bodies What has been found so far.
	 *
	 * @return void
	 */
	private static function walk( array $headers, string $body, array &$bodies ): void {
		[ $type, $params ] = self::content_type( $headers['content-type'] ?? 'text/plain' );

		if ( str_starts_with( $type, 'multipart/' ) && '' !== ( $params['boundary'] ?? '' ) ) {
			foreach ( self::parts( $body, $params['boundary'] ) as $part ) {
				[ $part_headers, $part_body ] = self::split( $part );
				self::walk( $part_headers, $part_body, $bodies );
			}

			return;
		}

		if ( str_starts_with( strtolower( $headers['content-disposition'] ?? '' ), 'attachment' ) ) {
			return;
		}

		$key = match ( $type ) {
			'text/html'  => 'html',
			'text/plain' => 'text',
			default      => '',
		};

		if ( '' !== $key && '' === $bodies[ $key ] ) {
			$bodies[ $key ] = self::utf8( self::decode( $body, $headers['content-transfer-encoding'] ?? '' ), $params['charset'] ?? 'UTF-8' );
		}
	}

	/**
	 * Headers and body, split at the first blank line.
	 *
	 * Folded header lines are unfolded and encoded-words decoded, so a value
	 * reads as it did before it was put on the wire.
	 *
	 * @since 1.2.0
	 *
	 * @param string $raw A message or a part, LF-delimited.
	 *
	 * @return array{0: array<string, string>, 1: string} Lower-cased header
	 *                                                    names, and the body.
	 */
	private static function split( string $raw ): array {
		$raw = ltrim( $raw, "\n" );
		$at  = strpos( $raw, "\n\n" );
		$top = false === $at ? $raw : substr( $raw, 0, $at );

		$headers = array();
		$name    = '';

		foreach ( explode( "\n", $top ) as $line ) {
			if ( '' !== $name && ( str_starts_with( $line, ' ' ) || str_starts_with( $line, "\t" ) ) ) {
				// A fold between two encoded-words is not a space in the
				// value; any other fold is.
				$headers[ $name ] .= str_ends_with( $headers[ $name ], '?=' ) && str_starts_with( ltrim( $line ), '=?' ) ? ltrim( $line ) : ' ' . ltrim( $line );

				continue;
			}

			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}

			[ $key, $value ] = explode( ':', $line, 2 );
			$name            = strtolower( trim( $key ) );
			$headers[ $name ] = trim( $value );
		}

		return array(
			array_map( self::words( ... ), $headers ),
			false === $at ? '' : substr( $raw, $at + 2 ),
		);
	}

	/**
	 * A content type and its parameters.
	 *
	 * @since 1.2.0
	 *
	 * @param string $value Content-Type header value.
	 *
	 * @return array{0: string, 1: array<string, string>}
	 */
	private static function content_type( string $value ): array {
		$pieces = explode( ';', $value );
		$type   = strtolower( trim( (string) array_shift( $pieces ) ) );
		$params = array();

		foreach ( $pieces as $piece ) {
			if ( str_contains( $piece, '=' ) ) {
				[ $key, $param ] = explode( '=', $piece, 2 );
				$params[ strtolower( trim( $key ) ) ] = trim( trim( $param ), '"' );
			}
		}

		return array( $type, $params );
	}

	/**
	 * The parts of a multipart body.
	 *
	 * @since 1.2.0
	 *
	 * @param string $body     The body.
	 * @param string $boundary Its boundary, without the dashes.
	 *
	 * @return string[]
	 */
	private static function parts( string $body, string $boundary ): array {
		$parts = array();
		$open  = false;
		$part  = array();

		foreach ( explode( "\n", $body ) as $line ) {
			$trimmed = rtrim( $line );

			if ( '--' . $boundary . '--' === $trimmed ) {
				break;
			}

			if ( '--' . $boundary === $trimmed ) {
				if ( $open ) {
					$parts[] = implode( "\n", $part );
				}

				$open = true;
				$part = array();

				continue;
			}

			if ( $open ) {
				$part[] = $line;
			}
		}

		if ( $open && array() !== $part ) {
			$parts[] = implode( "\n", $part );
		}

		return $parts;
	}

	/**
	 * A body without its transfer encoding.
	 *
	 * @since 1.2.0
	 *
	 * @param string $body     Encoded body.
	 * @param string $encoding Content-Transfer-Encoding.
	 *
	 * @return string
	 */
	private static function decode( string $body, string $encoding ): string {
		return match ( strtolower( trim( $encoding ) ) ) {
			// Back to CRLF first: a soft break is `=` then CRLF, and the log
			// stores LF.
			'quoted-printable' => str_replace( "\r\n", "\n", quoted_printable_decode( str_replace( "\n", "\r\n", $body ) ) ),
			'base64'           => (string) base64_decode( preg_replace( '/\s+/', '', $body ) ?? '', false ),
			default            => $body,
		};
	}

	/**
	 * Text in UTF-8, from whatever charset its part declared.
	 *
	 * @since 1.2.0
	 *
	 * @param string $text    Decoded text.
	 * @param string $charset Declared charset.
	 *
	 * @return string
	 */
	private static function utf8( string $text, string $charset ): string {
		$charset = strtoupper( trim( $charset ) );

		if ( in_array( $charset, array( '', 'UTF-8', 'UTF8', 'US-ASCII' ), true ) || ! function_exists( 'mb_convert_encoding' ) ) {
			return $text;
		}

		try {
			return (string) mb_convert_encoding( $text, 'UTF-8', $charset );
		} catch ( \ValueError ) {
			return $text;
		}
	}

	/**
	 * A header value with its RFC 2047 encoded-words decoded.
	 *
	 * Both encodings, though Mime only writes B: a log may hold a message
	 * built by something else.
	 *
	 * @since 1.2.0
	 *
	 * @param string $value Header value.
	 *
	 * @return string
	 */
	private static function words( string $value ): string {
		// Whitespace between two adjacent encoded-words is not part of the text.
		$value = (string) preg_replace( '/(\?=)\s+(=\?)/', '$1$2', $value );

		return (string) preg_replace_callback(
			'/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
			static function ( array $word ): string {
				$text = 'B' === strtoupper( $word[2] )
					? (string) base64_decode( $word[3], false )
					: quoted_printable_decode( str_replace( '_', ' ', $word[3] ) );

				return self::utf8( $text, $word[1] );
			},
			$value
		);
	}
}
