<?php
/**
 * Amazon SES transport.
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
 * Class Ses
 *
 * SES v2 `SendEmail`, signed with SigV4 directly — no AWS SDK, which
 * would drag in a hundred packages to sign one request.
 *
 * The message is sent raw (a serialised MIME document) rather than as
 * structured fields, so attachments, inline images and custom headers
 * all pass through unchanged, and the body SES delivers is exactly the
 * one {@see Mime} produced.
 *
 * Two things bite operators here, neither of them the library's doing:
 * a new account is in the **sandbox** and can only send to verified
 * addresses, and the sending domain must be verified in the region
 * you're calling. A perfectly-formed request still fails until both are
 * sorted.
 *
 * @since 1.0.0
 */
final readonly class Ses implements Transport {

	use Api;

	private const SERVICE = 'ses';

	/**
	 * @since 1.0.0
	 *
	 * @param string $access_key    AWS access key id.
	 * @param string $secret_key    AWS secret access key.
	 * @param string $region        Region, e.g. `eu-west-1`.
	 * @param string $configuration_set Optional configuration set, which
	 *                                  is how you get open/bounce events
	 *                                  routed to SNS.
	 * @param int    $timeout       Seconds.
	 */
	public function __construct(
		#[\SensitiveParameter] private string $access_key,
		#[\SensitiveParameter] private string $secret_key,
		private string $region = 'us-east-1',
		private string $configuration_set = '',
		private int $timeout = 15,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function send( Message $message ): SendResult {
		$payload = array(
			'Content' => array(
				'Raw' => array( 'Data' => base64_encode( Mime::build( $message ) ) ),
			),
		);

		// Bcc lives in the envelope only, so it has to be named here —
		// SES reads the destination from this list, not from the headers.
		$destinations = array_map(
			static fn( $a ): string => $a->email,
			array_merge( $message->recipients(), $message->cc_recipients(), $message->bcc_recipients() )
		);

		$payload['Destination'] = array( 'ToAddresses' => array_values( $destinations ) );

		if ( null !== $message->sender() ) {
			$payload['FromEmailAddress'] = $message->sender()->header();
		}

		if ( '' !== $this->configuration_set ) {
			$payload['ConfigurationSetName'] = $this->configuration_set;
		}

		if ( array() !== $message->get_metadata() ) {
			foreach ( $message->get_metadata() as $key => $value ) {
				$payload['EmailTags'][] = array( 'Name' => $key, 'Value' => $value );
			}
		}

		$host = 'email.' . $this->region . '.amazonaws.com';
		$path = '/v2/email/outbound-emails';
		$body = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $body ) {
			return SendResult::failed( 'The message could not be encoded for SES.' );
		}

		return $this->classify(
			$this->post(
				'https://' . $host . $path,
				$payload,
				$this->sign( $host, $path, $body ),
				$this->timeout
			),
			'MessageId',
			'message'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ses';
	}

	/**
	 * Build the SigV4 authorization headers.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host Endpoint host.
	 * @param string $path Request path.
	 * @param string $body Request body.
	 *
	 * @return array<string, string>
	 */
	private function sign( string $host, string $path, string $body ): array {
		$now       = gmdate( 'Ymd\THis\Z' );
		$date      = substr( $now, 0, 8 );
		$scope     = $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';
		$hash      = hash( 'sha256', $body );
		$signed    = 'content-type;host;x-amz-content-sha256;x-amz-date';

		$canonical = implode( "\n", array(
			'POST',
			$path,
			'',
			'content-type:application/json',
			'host:' . $host,
			'x-amz-content-sha256:' . $hash,
			'x-amz-date:' . $now,
			'',
			$signed,
			$hash,
		) );

		$to_sign = implode( "\n", array(
			'AWS4-HMAC-SHA256',
			$now,
			$scope,
			hash( 'sha256', $canonical ),
		) );

		// The signing key is derived down the scope chain, so a leaked
		// daily key is useless outside its date, region and service.
		$key = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
		$key = hash_hmac( 'sha256', $this->region, $key, true );
		$key = hash_hmac( 'sha256', self::SERVICE, $key, true );
		$key = hash_hmac( 'sha256', 'aws4_request', $key, true );

		return array(
			'X-Amz-Date'           => $now,
			'X-Amz-Content-Sha256' => $hash,
			'Authorization'        => 'AWS4-HMAC-SHA256 '
				. 'Credential=' . $this->access_key . '/' . $scope . ', '
				. 'SignedHeaders=' . $signed . ', '
				. 'Signature=' . hash_hmac( 'sha256', $to_sign, $key ),
		);
	}
}
