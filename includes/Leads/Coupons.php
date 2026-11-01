<?php
/**
 * Unique single-use WooCommerce coupons for leads.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Leads;

defined( 'ABSPATH' ) || exit;

/**
 * Coupons.
 */
class Coupons {

	/**
	 * Whether WooCommerce coupons are available.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( '\WC_Coupon' ) && function_exists( 'wc_get_coupon_id_by_code' );
	}

	/**
	 * Creates a coupon restricted to the lead's email, usable once.
	 *
	 * @param array  $offer       Offer config.
	 * @param string $email       Email.
	 * @param int    $campaign_id Campaign.
	 * @return string|\WP_Error Code.
	 */
	public static function create( array $offer, $email, $campaign_id ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'dev3pu_no_woo', __( 'WooCommerce is not active.', 'dev3-ai-popup-builder' ) );
		}
		$prefix = '' !== $offer['prefix'] ? $offer['prefix'] : 'LEAD';
		$code   = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$candidate = strtoupper( $prefix . '-' . wp_generate_password( 8, false, false ) );
			if ( ! wc_get_coupon_id_by_code( $candidate ) ) {
				$code = $candidate;
				break;
			}
		}
		if ( '' === $code ) {
			return new \WP_Error( 'dev3pu_coupon', __( 'Could not create a coupon code.', 'dev3-ai-popup-builder' ) );
		}
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' === $offer['discount_type'] ? 'fixed_cart' : 'percent' );
		$coupon->set_amount( (float) $offer['amount'] );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( true );
		$coupon->set_email_restrictions( array( $email ) );
		if ( $offer['expiry_days'] > 0 ) {
			$coupon->set_date_expires( time() + (int) $offer['expiry_days'] * DAY_IN_SECONDS );
		}
		$coupon->set_description( sprintf( /* translators: %d: campaign id */ __( 'Lead capture coupon (campaign #%d)', 'dev3-ai-popup-builder' ), (int) $campaign_id ) );
		$coupon->update_meta_data( '_dev3pu_campaign', (int) $campaign_id );
		$id = $coupon->save();
		return $id ? $code : new \WP_Error( 'dev3pu_coupon', __( 'Could not create a coupon code.', 'dev3-ai-popup-builder' ) );
	}
}
