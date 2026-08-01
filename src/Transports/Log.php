<?php
/**
 * Log transport.
 *
 * @package   SugarMill\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\Mailer\Transports;

use SugarMill\Mailer\{Message, Mime, SendResult, Transport};

/**
 * Class Log
 *
 * Writes the full serialised message to a file instead of sending it.
 *
 * The point of it is local development: you get to read exactly what
 * would have gone out, headers and all, with no provider account and no
 * risk of mailing a real customer from a database dump.
 *
 * @since 1.0.0
 */
final readonly class Log implements Transport {

	/**
	 * @since 1.0.0
	 *
	 * @param string $path File to append to.
	 */
	public function __construct( private string $path ) {}

	/**
	 * {@inheritDoc}
	 */
	public function send( Message $message ): SendResult {
		$id = bin2hex( random_bytes( 8 ) );

		$entry = str_repeat( '=', 72 ) . "\n"
			. gmdate( 'Y-m-d H:i:s' ) . " UTC · " . $id . "\n"
			. str_repeat( '=', 72 ) . "\n"
			. str_replace( "\r\n", "\n", Mime::build( $message ) ) . "\n\n";

		if ( false === @file_put_contents( $this->path, $entry, FILE_APPEND | LOCK_EX ) ) {
			return SendResult::failed( 'Could not write to the mail log: ' . $this->path );
		}

		return SendResult::ok( $id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'log';
	}
}
