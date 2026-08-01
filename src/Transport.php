<?php
/**
 * Delivery contract.
 *
 * @package   SugarMill\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\Mailer;

/**
 * Interface Transport
 *
 * How a message actually leaves the building.
 *
 * One method, so a new provider is one small class and switching
 * providers changes one line of configuration. Implementations must not
 * throw for a rejected message — a bounce or a bad API key is a normal
 * outcome for outbound mail, and forcing a try/catch around every send
 * encourages swallowing it.
 *
 * @since 1.0.0
 */
interface Transport {

	/**
	 * Deliver a message.
	 *
	 * @since 1.0.0
	 *
	 * @param Message $message Message to send.
	 *
	 * @return SendResult Never null; check {@see SendResult::$sent}.
	 */
	public function send( Message $message ): SendResult;

	/**
	 * A short name, for logs.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function name(): string;
}
