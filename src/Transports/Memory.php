<?php
/**
 * In-memory transport.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer\Transports;

use SugarCommerce\Mailer\{Message, SendResult, Transport};

/**
 * Class Memory
 *
 * Collects messages instead of sending them.
 *
 * This is the transport your test suite uses, and the reason it exists
 * is not convenience: a suite wired to a real provider will eventually
 * email a real customer, usually from a fixture that looked synthetic.
 * Assert against what was collected instead.
 *
 * @since 1.0.0
 */
final class Memory implements Transport {

	/**
	 * Messages accepted so far.
	 *
	 * @var Message[]
	 */
	private array $sent = array();

	/**
	 * @since 1.0.0
	 *
	 * @param bool   $succeed Whether sends report success. Set false to
	 *                        exercise your failure handling.
	 * @param string $error   Failure reason when not succeeding.
	 */
	public function __construct(
		private readonly bool $succeed = true,
		private readonly string $error = 'Simulated failure',
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function send( Message $message ): SendResult {
		if ( ! $this->succeed ) {
			return SendResult::failed( $this->error, 500, true );
		}

		$this->sent[] = $message;

		return SendResult::ok( 'memory-' . count( $this->sent ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'memory';
	}

	/**
	 * Everything collected.
	 *
	 * @since 1.0.0
	 *
	 * @return Message[]
	 */
	public function messages(): array {
		return $this->sent;
	}

	/**
	 * The most recent message.
	 *
	 * @since 1.0.0
	 *
	 * @return Message|null
	 */
	public function last(): ?Message {
		return array() === $this->sent ? null : $this->sent[ count( $this->sent ) - 1 ];
	}

	/**
	 * How many were accepted.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->sent );
	}

	/**
	 * Whether anything was sent to an address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Address.
	 *
	 * @return bool
	 */
	public function sent_to( string $email ): bool {
		foreach ( $this->sent as $message ) {
			foreach ( $message->recipients() as $recipient ) {
				if ( 0 === strcasecmp( $recipient->email, $email ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Forget everything.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->sent = array();
	}
}
