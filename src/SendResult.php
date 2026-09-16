<?php
/**
 * The outcome of a delivery attempt.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer;

/**
 * Class SendResult
 *
 * Records the provider's message id on success, which is the only thing
 * that ties a later bounce or complaint webhook back to the message that
 * caused it. Store it.
 *
 * @since 1.0.0
 */
final readonly class SendResult {

	/**
	 * @since 1.0.0
	 *
	 * @param bool   $sent       Whether the provider accepted it.
	 * @param string $message_id Provider message id.
	 * @param string $error      Failure reason.
	 * @param int    $status     HTTP status, or 0 for a transport failure.
	 * @param bool   $retryable  Whether trying again could succeed.
	 */
	public function __construct(
		public bool $sent,
		public string $message_id = '',
		public string $error = '',
		public int $status = 0,
		public bool $retryable = false,
	) {}

	/**
	 * A successful send.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message_id Provider message id.
	 * @param int    $status     HTTP status.
	 *
	 * @return self
	 */
	public static function ok( string $message_id = '', int $status = 200 ): self {
		return new self( true, $message_id, '', $status );
	}

	/**
	 * A failed send.
	 *
	 * The retryable flag matters: a 5xx or a timeout is worth queueing
	 * again, but a rejected address or a bad API key will fail
	 * identically forever, and retrying it just delays the alert.
	 *
	 * @since 1.0.0
	 *
	 * @param string $error     Reason.
	 * @param int    $status    HTTP status.
	 * @param bool   $retryable Whether to try again.
	 *
	 * @return self
	 */
	public static function failed( string $error, int $status = 0, bool $retryable = false ): self {
		return new self( false, '', $error, $status, $retryable );
	}

	/**
	 * Whether the send failed.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function failed_send(): bool {
		return ! $this->sent;
	}
}
