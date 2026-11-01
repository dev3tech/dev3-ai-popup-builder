<?php
/**
 * Public REST for visitors: counters and lead capture. Same-origin + per-IP rate limits,
 * no nonces (page caches serve stale ones), strict input validation, nothing private returned.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Rest;

use Dev3\AiPopupBuilder\Core3\Core\Settings_Store;
use Dev3\AiPopupBuilder\Core3\Rest\Base_Controller;
use Dev3\AiPopupBuilder\Data\Campaigns;
use Dev3\AiPopupBuilder\Data\Stats;
use Dev3\AiPopupBuilder\Leads\Capture;

defined( 'ABSPATH' ) || exit;

/**
 * Public controller.
 */
class Public_Controller extends Base_Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/public/track',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'track' ),
				'permission_callback' => array( $this, 'track_permission' ),
				'args'                => array(
					'campaign_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'variant'     => array(
						'type'    => 'string',
						'default' => 'a',
					),
					'event'       => array(
						'type'     => 'string',
						'enum'     => array( 'view', 'close', 'click' ),
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/public/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => array( $this, 'submit_permission' ),
				'args'                => array(
					'campaign_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Track permission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function track_permission( \WP_REST_Request $request ) {
		return $this->public_permission( $request, 'dev3pu_track', 120 );
	}

	/**
	 * Submit permission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function submit_permission( \WP_REST_Request $request ) {
		return $this->public_permission( $request, 'dev3pu_submit', 10 );
	}

	/**
	 * POST public/track { campaign_id, variant, event }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function track( \WP_REST_Request $request ) {
		if ( ! Settings_Store::get( 'tracking', true ) ) {
			return $this->respond( array( 'ok' => true ) );
		}
		$campaign = Campaigns::get( (int) $request['campaign_id'] );
		$variant  = substr( preg_replace( '/[^a-d]/', '', strtolower( (string) $request['variant'] ) ), 0, 1 );
		if ( ! $campaign || 'active' !== $campaign['status'] || ! in_array( $variant, wp_list_pluck( $campaign['config']['variants'], 'id' ), true ) ) {
			return $this->respond( new \WP_Error( 'dev3pu_not_found', __( 'Unknown campaign.', 'dev3-ai-popup-builder' ), array( 'status' => 404 ) ) );
		}
		$event = (string) $request['event'];
		if ( 'click' === $event ) {
			// A click on a CTA button is the conversion of a campaign without a form.
			if ( 'cta' !== $campaign['config']['form']['mode'] ) {
				return $this->respond( array( 'ok' => true ) );
			}
			$event = 'convert';
		}
		Stats::bump( $campaign['id'], $variant, $event );
		return $this->respond( array( 'ok' => true ) );
	}

	/**
	 * POST public/submit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function submit( \WP_REST_Request $request ) {
		$body   = $request->get_json_params();
		$body   = is_array( $body ) ? $body : $request->get_params();
		$result = Capture::submit( $body );
		if ( is_wp_error( $result ) && 'dev3pu_invalid' === $result->get_error_code() ) {
			$data = (array) $result->get_error_data();
			return new \WP_REST_Response(
				array(
					'code'    => 'dev3pu_invalid',
					'message' => $result->get_error_message(),
					'fields'  => isset( $data['fields'] ) ? $data['fields'] : array(),
				),
				422
			);
		}
		return $this->respond( $result );
	}
}
