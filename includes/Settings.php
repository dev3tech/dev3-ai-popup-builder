<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3pu_settings_defaults / dev3pu_settings_sanitize.
 */
class Settings {

	/**
	 * Adds plugin defaults. No __() here: defaults can be read before init.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'mobile_safe'      => true,
				'max_per_view'     => 1,
				'min_gap'          => 30,
				'hide_roles'       => array(),
				'respect_dnt'      => false,
				'tracking'         => true,
				'store_ip'         => false,
				'retention_days'   => 0,
				'consent_text'     => '',
				'min_fill_seconds' => 2,
				'brand_voice'      => '',
				'accent_color'     => '#4f46e5',
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		foreach ( array( 'mobile_safe', 'respect_dnt', 'tracking', 'store_ip' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			}
		}
		$ints = array(
			'max_per_view'     => array( 0, 5 ),
			'min_gap'          => array( 0, 3600 ),
			'retention_days'   => array( 0, 3650 ),
			'min_fill_seconds' => array( 0, 30 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['hide_roles'] ) ) {
			$roles             = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->roles ) : array();
			$out['hide_roles'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $input['hide_roles'] ), $roles ) );
		}
		if ( isset( $input['consent_text'] ) ) {
			$out['consent_text'] = mb_substr( wp_kses( wp_unslash( (string) $input['consent_text'] ), self::inline_html() ), 0, 400 );
		}
		if ( isset( $input['brand_voice'] ) ) {
			$out['brand_voice'] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input['brand_voice'] ) ), 0, 600 );
		}
		if ( isset( $input['accent_color'] ) ) {
			$color = sanitize_hex_color( (string) $input['accent_color'] );
			if ( $color ) {
				$out['accent_color'] = $color;
			}
		}
		return $out;
	}

	/**
	 * Inline HTML allowed in consent labels and body text.
	 *
	 * @return array
	 */
	public static function inline_html() {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'br'     => array(),
		);
	}
}
