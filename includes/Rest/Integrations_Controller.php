<?php
/**
 * Admin REST: integration keys (masked), lists, connection tests.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Rest;

use Dev3\AiPopupBuilder\Core3\Rest\Base_Controller;
use Dev3\AiPopupBuilder\Integrations\Services;
use Dev3\AiPopupBuilder\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Integrations controller.
 */
class Integrations_Controller extends Base_Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		register_rest_route(
			self::NS,
			'/integrations',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/integrations/(?P<service>mailchimp|brevo)/lists',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'lists' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::NS,
			'/integrations/(?P<service>mailchimp|brevo|webhook)/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * GET integrations.
	 *
	 * @return \WP_REST_Response
	 */
	public function index() {
		return $this->respond( Secrets::status() );
	}

	/**
	 * POST integrations { mailchimp_key, brevo_key, webhook_secret }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save( \WP_REST_Request $request ) {
		$input = $request->get_json_params();
		Secrets::update( is_array( $input ) ? $input : array() );
		return $this->respond( Secrets::status() );
	}

	/**
	 * GET lists.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function lists( \WP_REST_Request $request ) {
		return $this->respond( Services::lists( (string) $request['service'] ) );
	}

	/**
	 * POST test { url } (webhook).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function test( \WP_REST_Request $request ) {
		return $this->respond( Services::test( (string) $request['service'], esc_url_raw( (string) $request->get_param( 'url' ) ) ) );
	}
}
