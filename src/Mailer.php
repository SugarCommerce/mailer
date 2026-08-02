<?php
/**
 * The sending entry point.
 *
 * @package   Sugar\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace Sugar\Mailer;

/**
 * Class Mailer
 *
 * Wraps a transport with the things every application ends up needing:
 * a default sender, a global reply-to, and a safety catch for
 * non-production environments.
 *
 * Typical use:
 *
 *   $mailer = new Mailer(
 *       new Transports\Postmark( getenv( 'POSTMARK_TOKEN' ) ),
 *       from: new Address( 'billing@sugarcart.com', 'Sugarcart' ),
 *   );
 *
 *   $result = $mailer->send( $message );
 *
 *   if ( $result->failed_send() ) {
 *       log_it( $result->error, retryable: $result->retryable );
 *   }
 *
 * @since 1.0.0
 */
final readonly class Mailer {

	/**
	 * @since 1.0.0
	 *
	 * @param Transport    $transport Delivery mechanism.
	 * @param Address|null $from      Default sender, applied when a
	 *                                message has none.
	 * @param Address|null $reply_to  Default reply-to.
	 * @param string       $redirect  Redirect every message to this
	 *                                address instead of its real
	 *                                recipients. For staging: the usual
	 *                                way a test database of real
	 *                                customers gets emailed is that
	 *                                nobody set this.
	 */
	public function __construct(
		private Transport $transport,
		private ?Address $from = null,
		private ?Address $reply_to = null,
		private string $redirect = '',
	) {}

	/**
	 * Send a message.
	 *
	 * @since 1.0.0
	 *
	 * @param Message $message Message.
	 *
	 * @return SendResult
	 */
	public function send( Message $message ): SendResult {
		if ( null !== $this->from && null === $message->sender() ) {
			$message->from( $this->from );
		}

		if ( null !== $this->reply_to && null === $message->reply_address() ) {
			$message->reply_to( $this->reply_to );
		}

		$problems = $message->problems();

		if ( array() !== $problems ) {
			// A malformed message will fail identically every time, so it
			// is never retryable.
			return SendResult::failed( implode( ' ', $problems ) );
		}

		if ( '' !== $this->redirect ) {
			$message = $this->redirected( $message );
		}

		return $this->transport->send( $message );
	}

	/**
	 * The transport in use.
	 *
	 * @since 1.0.0
	 *
	 * @return Transport
	 */
	public function transport(): Transport {
		return $this->transport;
	}

	/**
	 * Rewrite a message to the redirect address.
	 *
	 * The original recipients are preserved in headers so a staging
	 * inbox still shows who it would have gone to.
	 *
	 * @since 1.0.0
	 *
	 * @param Message $message Original.
	 *
	 * @return Message
	 */
	private function redirected( Message $message ): Message {
		$original = implode( ', ', array_map( static fn( Address $a ): string => $a->email, $message->recipients() ) );

		$copy = Message::to( $this->redirect )
			->subject( '[' . $original . '] ' . $message->get_subject() )
			->html( $message->get_html() )
			->text( $message->get_text() )
			->header( 'X-Original-To', $original )
			->tag( $message->get_tag() );

		if ( null !== $message->sender() ) {
			$copy->from( $message->sender() );
		}

		foreach ( $message->attachments() as $attachment ) {
			$copy->attach( $attachment );
		}

		foreach ( $message->get_metadata() as $key => $value ) {
			$copy->meta( $key, $value );
		}

		return $copy;
	}
}
