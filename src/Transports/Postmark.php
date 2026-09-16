<?php
/**
 * Postmark transport.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer\Transports;

use SugarCommerce\Mailer\{Address, Message, SendResult, Transport};

/**
 * Class Postmark
 *
 * Postmark is transactional-only by design — they will close an account
 * that sends marketing through it — and that is exactly why deliverability
 * on receipts and password resets is as good as it is.
 *
 * @since 1.0.0
 */
final readonly class Postmark implements Transport {

	use Api;

	private const ENDPOINT = 'https://api.postmarkapp.com/email';

	/**
	 * @since 1.0.0
	 *
	 * @param string $token   Server API token.
	 * @param string $stream  Message stream. Transactional mail must not
	 *                        go through a broadcast stream, or bounce
	 *                        handling and reputation are pooled with
	 *                        marketing.
	 * @param int    $timeout Seconds.
	 */
	public function __construct(
		#[\SensitiveParameter] private string $token,
		private string $stream = 'outbound',
		private int $timeout = 15,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function send( Message $message ): SendResult {
		$payload = array(
			'From'          => (string) $message->sender(),
			'To'            => self::join( $message->recipients() ),
			'Subject'       => $message->get_subject(),
			'HtmlBody'      => $message->get_html(),
			'TextBody'      => $message->get_text(),
			'MessageStream' => $this->stream,
			'TrackOpens'    => false,
		);

		if ( array() !== $message->cc_recipients() ) {
			$payload['Cc'] = self::join( $message->cc_recipients() );
		}

		if ( array() !== $message->bcc_recipients() ) {
			$payload['Bcc'] = self::join( $message->bcc_recipients() );
		}

		if ( null !== $message->reply_address() ) {
			$payload['ReplyTo'] = (string) $message->reply_address();
		}

		if ( '' !== $message->get_tag() ) {
			$payload['Tag'] = $message->get_tag();
		}

		if ( array() !== $message->get_metadata() ) {
			$payload['Metadata'] = $message->get_metadata();
		}

		if ( array() !== $message->custom_headers() ) {
			$payload['Headers'] = array_map(
				static fn( string $name, string $value ): array => array( 'Name' => $name, 'Value' => $value ),
				array_keys( $message->custom_headers() ),
				array_values( $message->custom_headers() )
			);
		}

		foreach ( $message->attachments() as $attachment ) {
			$entry = array(
				'Name'        => $attachment->filename,
				'Content'     => $attachment->encoded(),
				'ContentType' => $attachment->mime_type,
			);

			if ( $attachment->is_inline() ) {
				$entry['ContentID'] = 'cid:' . $attachment->content_id;
			}

			$payload['Attachments'][] = $entry;
		}

		return $this->classify(
			$this->post( self::ENDPOINT, $payload, array( 'X-Postmark-Server-Token' => $this->token ), $this->timeout ),
			'MessageID',
			'Message'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'postmark';
	}

	/**
	 * Join addresses into a header list.
	 *
	 * @since 1.0.0
	 *
	 * @param Address[] $addresses Addresses.
	 *
	 * @return string
	 */
	private static function join( array $addresses ): string {
		return implode( ', ', array_map( static fn( Address $a ): string => $a->header(), $addresses ) );
	}
}
