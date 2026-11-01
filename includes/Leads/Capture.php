<?php
/**
 * Front-end lead capture: validation, spam checks, dedupe, offers, integrations.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Leads;

use Dev3\AiPopupBuilder\Core3\Core\Rate_Limiter;
use Dev3\AiPopupBuilder\Core3\Core\Settings_Store;
use Dev3\AiPopupBuilder\Data\Campaigns;
use Dev3\AiPopupBuilder\Data\Leads;
use Dev3\AiPopupBuilder\Data\Stats;
use Dev3\AiPopupBuilder\Integrations\Services;
use Dev3\AiPopupBuilder\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Capture.
 */
class Capture {

	/**
	 * Handles one submission.
	 *
	 * @param array $input { campaign_id, variant, fields{}, consent, hp, elapsed, page_url, referrer, utm{}, device }.
	 * @return array|\WP_Error Success state for the visitor.
	 */
	public static function submit( array $input ) {
		$campaign = Campaigns::get( isset( $input['campaign_id'] ) ? (int) $input['campaign_id'] : 0 );
		$preview  = ! empty( $input['preview'] ) && current_user_can( 'manage_options' );
		if ( ! $campaign || ( 'active' !== $campaign['status'] && ! $preview ) || 'form' !== $campaign['config']['form']['mode'] ) {
			return new \WP_Error( 'dev3pu_not_found', __( 'This form is no longer available.', 'dev3-ai-popup-builder' ), array( 'status' => 404 ) );
		}
		$config  = $campaign['config'];
		$variant = self::variant( $campaign, isset( $input['variant'] ) ? (string) $input['variant'] : 'a' );

		// Spam: honeypot (fake success, nothing stored) + minimum fill time.
		if ( ! empty( $input['hp'] ) ) {
			return self::success_state( $variant, $config, '' );
		}
		$min = (int) Settings_Store::get( 'min_fill_seconds', 2 );
		if ( $min > 0 && isset( $input['elapsed'] ) && (int) $input['elapsed'] < $min * 1000 ) {
			return new \WP_Error( 'dev3pu_too_fast', __( 'That was quick! Please try again.', 'dev3-ai-popup-builder' ), array( 'status' => 400 ) );
		}

		$values = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
		$clean  = self::validate( $config['form'], $values, ! empty( $input['consent'] ) );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$utm = array();
		foreach ( isset( $input['utm'] ) && is_array( $input['utm'] ) ? $input['utm'] : array() as $key => $value ) {
			if ( in_array( $key, array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ), true ) ) {
				$utm[ $key ] = mb_substr( sanitize_text_field( (string) $value ), 0, 100 );
			}
		}
		$consent_label = $config['form']['consent']['enabled'] ? wp_kses( $config['form']['consent']['label'], Settings::inline_html() ) : '';
		$saved         = Leads::upsert(
			array(
				'campaign_id'  => $campaign['id'],
				'variant'      => $variant['id'],
				'email'        => $clean['email'],
				'name'         => $clean['name'],
				'phone'        => $clean['phone'],
				'fields'       => $clean['fields'],
				'consent'      => $clean['consent'],
				'consent_text' => $clean['consent'] ? $consent_label : '',
				'page_url'     => isset( $input['page_url'] ) ? mb_substr( esc_url_raw( (string) $input['page_url'] ), 0, 500 ) : '',
				'referrer'     => isset( $input['referrer'] ) ? mb_substr( esc_url_raw( (string) $input['referrer'] ), 0, 500 ) : '',
				'utm'          => $utm,
				'device'       => isset( $input['device'] ) && in_array( $input['device'], array( 'desktop', 'tablet', 'mobile' ), true ) ? $input['device'] : '',
				'ip_hash'      => Settings_Store::get( 'store_ip', false ) ? sha1( wp_salt( 'nonce' ) . Rate_Limiter::client_ip() ) : '',
			)
		);
		if ( $saved['created'] && ! $preview ) {
			Stats::bump( $campaign['id'], $variant['id'], 'convert' );
		}
		$lead = Leads::get( $saved['id'] );

		// Offer.
		$code = '';
		if ( 'code' === $config['offer']['type'] ) {
			$code = $config['offer']['code'];
		} elseif ( 'coupon' === $config['offer']['type'] ) {
			$code = '' !== $lead['coupon'] ? $lead['coupon'] : Coupons::create( $config['offer'], $lead['email'], $campaign['id'] );
			if ( is_wp_error( $code ) ) {
				$code = '';
			}
		}
		if ( '' !== $code && $code !== $lead['coupon'] ) {
			Leads::set_coupon( $lead['id'], $code );
			$lead['coupon'] = $code;
		}

		Services::sync_lead( $lead, $campaign );

		/**
		 * A lead was captured (or updated for the same campaign + email).
		 *
		 * @param array $lead     Lead.
		 * @param array $campaign Campaign.
		 * @param bool  $created  True for a new lead.
		 */
		do_action( 'dev3pu_lead_captured', Leads::get( $lead['id'] ), $campaign, $saved['created'] );

		return self::success_state( $variant, $config, $code );
	}

	/**
	 * Picks the submitted variant (falls back to the first one).
	 *
	 * @param array  $campaign Campaign.
	 * @param string $id       Variant id.
	 * @return array
	 */
	public static function variant( array $campaign, $id ) {
		foreach ( $campaign['config']['variants'] as $variant ) {
			if ( $variant['id'] === $id ) {
				return $variant;
			}
		}
		return $campaign['config']['variants'][0];
	}

	/**
	 * Visitor-facing success state.
	 *
	 * @param array  $variant Variant.
	 * @param array  $config  Config.
	 * @param string $code    Offer code.
	 * @return array
	 */
	public static function success_state( array $variant, array $config, $code ) {
		return array(
			'ok'       => true,
			'title'    => $variant['content']['success_title'],
			'message'  => $variant['content']['success_message'],
			'code'     => (string) $code,
			'redirect' => 'redirect' === $config['success']['action'] ? $config['success']['redirect_url'] : '',
		);
	}

	/**
	 * Validates submitted values against the form definition.
	 *
	 * @param array $form    Form config.
	 * @param array $values  key => value.
	 * @param bool  $consent Consent box ticked.
	 * @return array|\WP_Error { email, name, phone, fields, consent }
	 */
	public static function validate( array $form, array $values, $consent ) {
		$errors = array();
		$out    = array(
			'email'   => '',
			'name'    => '',
			'phone'   => '',
			'fields'  => array(),
			'consent' => (bool) $consent,
		);
		foreach ( $form['fields'] as $field ) {
			$key   = $field['key'];
			$raw   = isset( $values[ $key ] ) ? $values[ $key ] : '';
			$value = 'checkbox' === $field['type'] ? ! empty( $raw ) && 'false' !== $raw : trim( sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' ) );
			if ( $field['required'] && ( '' === $value || false === $value ) ) {
				$errors[ $key ] = 'email' === $field['type'] ? __( 'Enter your email address.', 'dev3-ai-popup-builder' ) : __( 'This field is required.', 'dev3-ai-popup-builder' );
				continue;
			}
			switch ( $field['type'] ) {
				case 'email':
					if ( '' !== $value && ! is_email( $value ) ) {
						$errors[ $key ] = __( 'Enter a valid email address.', 'dev3-ai-popup-builder' );
						break;
					}
					$value = strtolower( sanitize_email( $value ) );
					if ( '' === $out['email'] ) {
						$out['email'] = $value;
						continue 2;
					}
					break;
				case 'tel':
					if ( '' !== $value && ! preg_match( '/^[0-9+()\s.\-]{5,30}$/', $value ) ) {
						$errors[ $key ] = __( 'Enter a valid phone number.', 'dev3-ai-popup-builder' );
						break;
					}
					if ( '' === $out['phone'] ) {
						$out['phone'] = $value;
						continue 2;
					}
					break;
				case 'select':
					if ( '' !== $value && ! in_array( $value, $field['options'], true ) ) {
						$errors[ $key ] = __( 'Choose one of the options.', 'dev3-ai-popup-builder' );
					}
					break;
				case 'text':
					$value = mb_substr( $value, 0, 300 );
					if ( 'name' === $key || false !== stripos( $field['label'], 'name' ) ) {
						if ( '' === $out['name'] ) {
							$out['name'] = mb_substr( $value, 0, 190 );
							continue 2;
						}
					}
					break;
			}
			if ( ! isset( $errors[ $key ] ) ) {
				$out['fields'][ $key ] = $value;
			}
		}
		if ( ! empty( $form['consent']['enabled'] ) && ! empty( $form['consent']['required'] ) && ! $consent ) {
			$errors['consent'] = __( 'Please tick the box to continue.', 'dev3-ai-popup-builder' );
		}
		if ( $errors ) {
			return new \WP_Error(
				'dev3pu_invalid',
				__( 'Please check the highlighted fields.', 'dev3-ai-popup-builder' ),
				array(
					'status' => 422,
					'fields' => $errors,
				)
			);
		}
		if ( '' === $out['email'] ) {
			return new \WP_Error(
				'dev3pu_invalid',
				__( 'Enter your email address.', 'dev3-ai-popup-builder' ),
				array(
					'status' => 422,
					'fields' => array( 'email' => __( 'Enter your email address.', 'dev3-ai-popup-builder' ) ),
				)
			);
		}
		return $out;
	}
}
