<?php
/**
 * Admin REST: DEV3 AI drafts. Nothing here saves a campaign.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Rest;

use Dev3\AiPopupBuilder\Ai\Assistant;
use Dev3\AiPopupBuilder\Core3\Rest\Base_Controller;
use Dev3\AiPopupBuilder\Data\Campaigns;

defined( 'ABSPATH' ) || exit;

/**
 * AI controller.
 */
class Ai_Controller extends Base_Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		foreach ( array( 'copy', 'variants', 'targeting', 'explain', 'campaign' ) as $route ) {
			register_rest_route(
				self::NS,
				'/ai/' . $route,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $route ),
					'permission_callback' => 'explain' === $route || 'targeting' === $route ? array( $this, 'can_manage' ) : array( $this, 'can_generate' ),
				)
			);
		}
	}

	/**
	 * JSON body.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function body( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : $request->get_params();
	}

	/**
	 * POST ai/copy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function copy( \WP_REST_Request $request ) {
		return $this->respond( Assistant::copy( $this->body( $request ) ) );
	}

	/**
	 * POST ai/variants.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function variants( \WP_REST_Request $request ) {
		return $this->respond( Assistant::variants( $this->body( $request ) ) );
	}

	/**
	 * POST ai/targeting.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function targeting( \WP_REST_Request $request ) {
		return $this->respond( Assistant::targeting( $this->body( $request ) ) );
	}

	/**
	 * POST ai/explain { id }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function explain( \WP_REST_Request $request ) {
		$campaign = Campaigns::get( (int) $request->get_param( 'id' ) );
		if ( ! $campaign ) {
			return $this->respond( new \WP_Error( 'dev3pu_not_found', __( 'Campaign not found.', 'dev3-ai-popup-builder' ), array( 'status' => 404 ) ) );
		}
		return $this->respond( Assistant::explain( $campaign ) );
	}

	/**
	 * POST ai/campaign { brief }: a draft campaign object (not saved).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function campaign( \WP_REST_Request $request ) {
		return $this->respond( Assistant::campaign( (string) $request->get_param( 'brief' ) ) );
	}
}
