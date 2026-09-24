<?php
/**
 * An email address with an optional display name.
 *
 * @package   SugarCommerce\Mailer
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Mailer;

/**
 * Class Address
 *
 * @since 1.0.0
 */
final readonly class Address {

	/**
	 * @since 1.0.0
	 *
	 * @param string $email Address.
	 * @param string $name  Display name.
	 *
	 * @throws \InvalidArgumentException When the address is not valid or
	 *                                   contains a line break.
	 */
	public function __construct(
		public string $email,
		public string $name = '',
	) {
		// A newline in an address is header injection: it lets the caller
		// append Bcc or rewrite Reply-To. Rejected outright rather than
		// stripped, so a mangled address never reaches a transport.
		if ( 1 === preg_match( '/[\r\n\x00]/', $email . $name ) ) {
			throw new \InvalidArgumentException( 'Email addresses and names cannot contain line breaks.' );
		}

		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new \InvalidArgumentException( 'Not a valid email address: ' . $email );
		}
	}

	/**
	 * Parse `Name <email@example.com>` or a bare address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Address string.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When unparseable.
	 */
	public static function parse( string $value ): self {
		$value = trim( $value );

		if ( 1 === preg_match( '/^(.*?)\s*<([^>]+)>$/', $value, $m ) ) {
			return new self( trim( $m[2] ), trim( $m[1], " \t\"" ) );
		}

		return new self( $value );
	}

	/**
	 * Build from whatever shape the caller has.
	 *
	 * @since 1.0.0
	 *
	 * @param self|string|array{0: string, 1?: string} $value Address.
	 *
	 * @return self
	 */
	public static function from( self|string|array $value ): self {
		return match ( true ) {
			$value instanceof self => $value,
			is_array( $value )     => new self( (string) ( $value[0] ?? '' ), (string) ( $value[1] ?? '' ) ),
			default                => self::parse( $value ),
		};
	}

	/**
	 * The RFC 5322 header form.
	 *
	 * Display names are quoted and escaped, because an unquoted name
	 * containing a comma splits into two recipients.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function header(): string {
		if ( '' === $this->name ) {
			return $this->email;
		}

		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $this->name ) . '" <' . $this->email . '>';
	}

	/**
	 * The domain part.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function domain(): string {
		return substr( strrchr( $this->email, '@' ) ?: '@', 1 );
	}

	/**
	 * The header form.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	#[\Override]
	public function __toString(): string {
		return $this->header();
	}
}
