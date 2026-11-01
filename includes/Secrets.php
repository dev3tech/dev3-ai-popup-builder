<?php
/**
 * Encrypted credentials for integrations (Mailchimp, Brevo, webhook signing) (option dev3pu_secrets).
 * Values are never sent to the browser; the admin UI only sees whether each one is set.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder;

use Dev3\AiPopupBuilder\Core3\Core\Encryption;

defined( 'ABSPATH' ) || exit;

/**
 * Secrets.
 */
class Secrets {

	const OPTION = 'dev3pu_secrets';

	const KEYS = array( 'mailchimp_key', 'brevo_key', 'webhook_secret' );

	/**
	 * Decrypted value ('' when unset). Constants DEV3PU_<KEY> win (e.g. DEV3PU_STRIPE_SECRET).
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function get( $key ) {
		$const = 'DEV3PU_' . strtoupper( $key );
		if ( defined( $const ) ) {
			return (string) constant( $const );
		}
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && ! empty( $all[ $key ] ) ? Encryption::decrypt( $all[ $key ] ) : '';
	}

	/**
	 * Saves values ('' clears; masked values are ignored).
	 *
	 * @param array $input Key => plaintext.
	 */
	public static function update( array $input ) {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		foreach ( self::KEYS as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = trim( sanitize_text_field( (string) $input[ $key ] ) );
			if ( false !== strpos( $value, '•' ) ) {
				continue;
			}
			if ( '' === $value ) {
				unset( $all[ $key ] );
			} else {
				$all[ $key ] = Encryption::encrypt( $value );
			}
		}
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Status for the UI: key => { set, hint }.
	 *
	 * @return array
	 */
	public static function status() {
		$out = array();
		foreach ( self::KEYS as $key ) {
			$value       = self::get( $key );
			$out[ $key ] = array(
				'set'      => '' !== $value,
				'hint'     => '' !== $value ? str_repeat( '•', 8 ) . substr( $value, -4 ) : '',
				'constant' => defined( 'DEV3PU_' . strtoupper( $key ) ),
			);
		}
		return $out;
	}
}
