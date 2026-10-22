<?php
/**
 * Log transport.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer\Transports;

use SugarCommerce\Mailer\{Message, Mime, SendResult, Transport};

/**
 * Class Log
 *
 * Writes the full serialised message to a file instead of sending it.
 *
 * The point of it is local development: you get to read exactly what
 * would have gone out, headers and all, with no provider account and no
 * risk of mailing a real customer from a database dump.
 *
 * The log holds every password-reset link and licence key that "went
 * out", so a file this transport creates is made readable by its owner
 * only. Keep the path outside the web root all the same.
 *
 * @since 1.0.0
 */
final readonly class Log implements Transport {

	/**
	 * Configure the transport.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path File to append to.
	 */
	public function __construct( private string $path ) {}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function send( Message $message ): SendResult {
		$id = bin2hex( random_bytes( 8 ) );

		$entry = str_repeat( '=', 72 ) . "\n"
			. gmdate( 'Y-m-d H:i:s' ) . " UTC · " . $id . "\n"
			. str_repeat( '=', 72 ) . "\n"
			. str_replace( "\r\n", "\n", Mime::build( $message ) ) . "\n\n";

		// Created empty and locked down before anything is written to it,
		// so there is no moment when a message sits in a file the rest of
		// the box can read.
		if ( ! file_exists( $this->path ) && @touch( $this->path ) ) {
			@chmod( $this->path, 0600 );
		}

		if ( false === @file_put_contents( $this->path, $entry, FILE_APPEND | LOCK_EX ) ) {
			return SendResult::failed( 'Could not write to the mail log: ' . $this->path );
		}

		return SendResult::ok( $id );
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function name(): string {
		return 'log';
	}
}
