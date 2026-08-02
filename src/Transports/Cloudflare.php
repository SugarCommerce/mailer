<?php
/**
 * Cloudflare Email Service transport.
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
 * Class Cloudflare
 *
 * Cloudflare Email Service, which added outbound transactional sending
 * in April 2026.
 *
 * There are three ways in: a Workers binding, this REST API, and
 * authenticated SMTP on `smtp.mx.cloudflare.net:465`. Only the last two
 * are reachable from PHP — the binding exists inside a Worker.
 *
 * **It is a public beta on the Workers Paid plan.** That is fine for
 * notifications; for the emails that carry a licence key or a receipt, a
 * provider with a longer track record is the safer default until this
 * reaches general availability. {@see Smtp} also works against it if you
 * would rather not depend on the REST shape while it settles.
 *
 * @since 1.0.0
 */
final readonly class Cloudflare implements Transport {

	use Api;

	/**
	 * @since 1.0.0
	 *
	 * @param string $api_token  API token with email send permission.
	 * @param string $account_id Cloudflare account id.
	 * @param int    $timeout    Seconds.
	 */
	public function __construct(
		#[\SensitiveParameter] private string $api_token,
		private string $account_id,
		private int $timeout = 15,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function send( Message $message ): SendResult {
		$payload = array(
			'from'    => array( 'email' => $message->sender()?->email, 'name' => $message->sender()?->name ),
			'to'      => array_map(
				static fn( Address $a ): array => array( 'email' => $a->email, 'name' => $a->name ),
				$message->recipients()
			),
			'subject' => $message->get_subject(),
			'html'    => $message->get_html(),
			'text'    => $message->get_text(),
		);

		if ( null !== $message->reply_address() ) {
			$payload['reply_to'] = array( 'email' => $message->reply_address()->email );
		}

		if ( array() !== $message->custom_headers() ) {
			$payload['headers'] = $message->custom_headers();
		}

		foreach ( $message->attachments() as $attachment ) {
			$payload['attachments'][] = array(
				'filename'     => $attachment->filename,
				'content'      => $attachment->encoded(),
				'content_type' => $attachment->mime_type,
			);
		}

		$url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $this->account_id ) . '/email/routing/send';

		return $this->classify(
			$this->post( $url, $payload, array( 'Authorization' => 'Bearer ' . $this->api_token ), $this->timeout ),
			'id',
			'errors'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'cloudflare';
	}
}
