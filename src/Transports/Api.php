<?php
/**
 * Shared plumbing for HTTP API transports.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer\Transports;

use SugarCommerce\Mailer\SendResult;

/**
 * Trait Api
 *
 * One JSON POST, and a consistent reading of what came back.
 *
 * The classification matters more than the request. A 5xx or a timeout
 * is worth queueing again; a 401 or a rejected recipient will fail
 * identically forever, and retrying it only delays the alert that
 * something is misconfigured.
 *
 * @since 1.0.0
 */
trait Api {

	/**
	 * POST JSON and classify the response.
	 *
	 * @since 1.0.0
	 *
	 * @param string                $url     Endpoint.
	 * @param array<string, mixed>  $payload Request body.
	 * @param array<string, string> $headers Extra headers.
	 * @param int                   $timeout Seconds.
	 *
	 * @return array{status: int, body: string, error: string}
	 */
	private function post( string $url, array $payload, array $headers = array(), int $timeout = 15 ): array {
		$lines = array( 'Content-Type: application/json', 'Accept: application/json' );

		foreach ( $headers as $name => $value ) {
			// The values are API keys from configuration. A stray newline
			// pasted in with one would start a second header — refused
			// rather than sent, and without echoing the key.
			if ( 1 === preg_match( '/[\r\n\x00]/', $name . $value ) ) {
				return array( 'status' => 0, 'body' => '', 'error' => 'A request header for the mail provider contains a line break; check the configured credentials.' );
			}

			$lines[] = $name . ': ' . $value;
		}

		$json = self::json( $payload );

		if ( false === $json ) {
			return array( 'status' => 0, 'body' => '', 'error' => 'The message could not be encoded for the mail provider.' );
		}

		$handle = curl_init( $url );

		curl_setopt_array( $handle, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $json,
			CURLOPT_HTTPHEADER     => $lines,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			// A fixed provider endpoint, so no SSRF surface — but the
			// protocol allowlist costs nothing and stops a redirect
			// wandering somewhere unexpected.
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_FOLLOWLOCATION => false,
		) );

		$body   = curl_exec( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
		$error  = curl_error( $handle );

		unset( $handle );

		return array(
			'status' => $status,
			'body'   => is_string( $body ) ? $body : '',
			'error'  => $error,
		);
	}

	/**
	 * A payload as the JSON that is sent.
	 *
	 * Invalid UTF-8 (a name pasted from a Latin-1 source, say) is replaced
	 * rather than allowed to fail the whole encode. SES signs this exact
	 * string, so it and post() must encode identically.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $payload Request body.
	 *
	 * @return string|false False only when the payload cannot be encoded
	 *                      at all.
	 */
	private static function json( array $payload ): string|false {
		return json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
	}

	/**
	 * A provider's value as text.
	 *
	 * Providers disagree on shape: an id is a string or a number, and an
	 * error is a string, an object with a `message`, or (Cloudflare) a
	 * list of those. Anything else reads as empty.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $value Decoded JSON value.
	 *
	 * @return string
	 */
	private static function text( mixed $value ): string {
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		if ( array_key_exists( 'message', $value ) ) {
			return self::text( $value['message'] );
		}

		return implode( '; ', array_filter( array_map( self::text( ... ), array_values( $value ) ), static fn( string $text ): bool => '' !== $text ) );
	}

	/**
	 * Turn a response into a result.
	 *
	 * @since 1.0.0
	 *
	 * @param array{status: int, body: string, error: string} $response Raw response.
	 * @param string                                          $id_key   JSON key holding the message id.
	 * @param string                                          $error_key JSON key holding the error text.
	 *
	 * @return SendResult
	 */
	private function classify( array $response, string $id_key, string $error_key = 'message' ): SendResult {
		// Nothing was served, so nothing was refused — worth retrying.
		if ( 0 === $response['status'] ) {
			return SendResult::failed(
				'' !== $response['error'] ? $response['error'] : 'Could not reach the mail provider.',
				0,
				true
			);
		}

		$decoded = json_decode( $response['body'], true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $response['status'] >= 200 && $response['status'] < 300 ) {
			return SendResult::ok( self::text( $decoded[ $id_key ] ?? $decoded['id'] ?? '' ), $response['status'] );
		}

		$message = self::text( $decoded[ $error_key ] ?? $decoded['message'] ?? $decoded['error'] ?? '' );

		if ( '' === $message ) {
			$message = 'HTTP ' . $response['status'];
		}

		// 429 and 5xx are the provider's problem; 4xx is ours and will
		// not improve on a second attempt.
		$retryable = 429 === $response['status'] || $response['status'] >= 500;

		return SendResult::failed( $message, $response['status'], $retryable );
	}
}
