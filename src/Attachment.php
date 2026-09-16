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
	 * @since 1.0.0
	 *
	 * @param string $filename    Name the recipient sees.
	 * @param string $content     Raw bytes.
	 * @param string $mime_type   Content type.
	 * @param string $content_id  Set for an inline image, referenced in
	 *                            HTML as `cid:the-id`.
	 */
	public function __construct(
		public string $filename,
		public string $content,
		public string $mime_type = 'application/octet-stream',
		public string $content_id = '',
	) {}

	/**
	 * Read a file from disk.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path      File path.
	 * @param string $filename  Override the visible name.
	 * @param string $mime_type Override the detected type.
	 *
	 * @return self
	 *
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public static function from_path( string $path, string $filename = '', string $mime_type = '' ): self {
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
