<?php
/**
 * Resend transport.
 *
 * @package   Sugar\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace Sugar\Mailer\Transports;

use Sugar\Mailer\{Address, Message, SendResult, Transport};

/**
 * Class Resend
 *
 * @since 1.0.0
 */
final readonly class Resend implements Transport {

	use Api;

	private const ENDPOINT = 'https://api.resend.com/emails';

	/**
	 * @since 1.0.0
	 *
	 * @param string $api_key API key.
	 * @param int    $timeout Seconds.
	 */
	public function __construct(
		#[\SensitiveParameter] private string $api_key,
		private int $timeout = 15,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function send( Message $message ): SendResult {
		$payload = array(
			'from'    => (string) $message->sender(),
			'to'      => self::emails( $message->recipients() ),
			'subject' => $message->get_subject(),
			'html'    => $message->get_html(),
			'text'    => $message->get_text(),
		);

		if ( array() !== $message->cc_recipients() ) {
			$payload['cc'] = self::emails( $message->cc_recipients() );
		}

		if ( array() !== $message->bcc_recipients() ) {
			$payload['bcc'] = self::emails( $message->bcc_recipients() );
		}

		if ( null !== $message->reply_address() ) {
			$payload['reply_to'] = $message->reply_address()->email;
		}

		if ( array() !== $message->custom_headers() ) {
			$payload['headers'] = $message->custom_headers();
		}

		if ( array() !== $message->get_metadata() ) {
			// Resend requires tag values to be alphanumeric with dashes
			// and underscores; anything else is rejected outright.
			foreach ( $message->get_metadata() as $key => $value ) {
				$payload['tags'][] = array(
					'name'  => (string) preg_replace( '/[^A-Za-z0-9_-]/', '_', $key ),
					'value' => (string) preg_replace( '/[^A-Za-z0-9_-]/', '_', $value ),
				);
			}
		}

		foreach ( $message->attachments() as $attachment ) {
			$payload['attachments'][] = array(
				'filename' => $attachment->filename,
				'content'  => $attachment->encoded(),
			);
		}

		return $this->classify(
			$this->post( self::ENDPOINT, $payload, array( 'Authorization' => 'Bearer ' . $this->api_key ), $this->timeout ),
			'id'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'resend';
	}

	/**
	 * Addresses as header strings.
	 *
	 * @since 1.0.0
	 *
	 * @param Address[] $addresses Addresses.
	 *
	 * @return string[]
	 */
	private static function emails( array $addresses ): array {
		return array_map( static fn( Address $a ): string => $a->header(), $addresses );
	}
}
