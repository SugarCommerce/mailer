<?php
/**
 * A file attached to a message.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer;

/**
 * Class Attachment
 *
 * Held in memory rather than by path, because a transport may be an HTTP
 * API that needs the bytes base64-encoded — and because a path that is
 * valid when the message is built may not be when it is finally sent
 * from a queue worker.
 *
 * @since 1.0.0
 */
final readonly class Attachment {

	/**
	 * A content type: `type/subtype`, optionally with `; key=value`
	 * parameters, each value a token or a quoted string.
	 *
	 * @since 1.3.0
	 */
	private const string MIME_TYPE = '/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+(?:[ \t]*;[ \t]*[A-Za-z0-9!#$&^_.+-]+=(?:[A-Za-z0-9!#$&^_.+-]+|"[^"\\\\\x00-\x1F\x7F]*"))*$/D';

	/**
	 * A content id: printable ASCII with nothing that would close the
	 * `<...>` it is written inside, or end a header.
	 *
	 * @since 1.3.0
	 */
	private const string CONTENT_ID = '/^[\x21-\x7E]*$/D';

	/**
	 * Validate and hold an attachment.
	 *
	 * All three names end up inside MIME part headers, and the filename
	 * very often comes from a user upload, so each is checked here: a
	 * line break in any of them would let the caller append headers, or
	 * a whole forged part, to the message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $filename    Name the recipient sees.
	 * @param string $content     Raw bytes.
	 * @param string $mime_type   Content type.
	 * @param string $content_id  Set for an inline image, referenced in
	 *                            HTML as `cid:the-id`.
	 *
	 * @throws \InvalidArgumentException When the filename contains a
	 *                                   control character, the content type
	 *                                   is malformed, or the content id
	 *                                   contains whitespace, `<`, `>` or a
	 *                                   control character.
	 */
	public function __construct(
		public string $filename,
		public string $content,
		public string $mime_type = 'application/octet-stream',
		public string $content_id = '',
	) {
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $filename ) ) {
			throw new \InvalidArgumentException( 'An attachment filename cannot contain line breaks or control characters.' );
		}

		if ( 1 !== preg_match( self::MIME_TYPE, $mime_type ) ) {
			throw new \InvalidArgumentException( 'Not a valid content type for an attachment.' );
		}

		if ( 1 !== preg_match( self::CONTENT_ID, $content_id ) || str_contains( $content_id, '<' ) || str_contains( $content_id, '>' ) ) {
			throw new \InvalidArgumentException( 'Not a valid content id for an inline attachment.' );
		}
	}

	/**
	 * Read a file from disk.
	 *
	 * Local files only: a path naming a stream wrapper other than
	 * `file://` (`http://`, `ftp://`, `php://`, `phar://` ...) is refused,
	 * so a path that reaches here from user input cannot be turned into
	 * an outbound request or a read of something that is not a file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path      File path.
	 * @param string $filename  Override the visible name.
	 * @param string $mime_type Override the detected type.
	 *
	 * @return self
	 *
	 * @throws \RuntimeException         When the path is not a local file or
	 *                                   cannot be read.
	 * @throws \InvalidArgumentException When the filename or content type is
	 *                                   not valid.
	 */
	public static function from_path( string $path, string $filename = '', string $mime_type = '' ): self {
		if ( 1 === preg_match( '#^([A-Za-z][A-Za-z0-9+.-]*)://#', $path, $scheme ) && 'file' !== strtolower( $scheme[1] ) ) {
			throw new \RuntimeException( 'Attachments can only be read from local files.' );
		}

		$content = @file_get_contents( $path );

		if ( false === $content ) {
			throw new \RuntimeException( 'Could not read the attachment: ' . $path );
		}

		if ( '' === $mime_type && function_exists( 'mime_content_type' ) ) {
			$detected  = @mime_content_type( $path );
			$mime_type = false === $detected ? '' : $detected;
		}

		return new self(
			'' !== $filename ? $filename : basename( $path ),
			$content,
			'' !== $mime_type ? $mime_type : 'application/octet-stream'
		);
	}

	/**
	 * Whether this is an inline image rather than a download.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_inline(): bool {
		return '' !== $this->content_id;
	}

	/**
	 * The content, base64-encoded for an API payload.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function encoded(): string {
		return base64_encode( $this->content );
	}

	/**
	 * Size in bytes.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function size(): int {
		return strlen( $this->content );
	}
}
