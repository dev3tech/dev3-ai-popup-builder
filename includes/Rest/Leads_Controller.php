<?php
/**
 * Admin REST: leads.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Rest;

use Dev3\AiPopupBuilder\Core3\Rest\Base_Controller;
use Dev3\AiPopupBuilder\Data\Campaigns;
use Dev3\AiPopupBuilder\Data\Leads;
use Dev3\AiPopupBuilder\Integrations\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Leads controller.
 */
class Leads_Controller extends Base_Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		register_rest_route(
			self::NS,
			'/leads',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'bulk_delete' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/leads/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/leads/(?P<id>\d+)/resync',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resync' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * Filters from a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function filters( $request ) {
		return array(
			'campaign_id' => absint( $request['campaign_id'] ),
			'status'      => sanitize_key( (string) $request['status'] ),
			'search'      => sanitize_text_field( (string) $request['search'] ),
			'from'        => sanitize_text_field( (string) $request['from'] ),
			'to'          => sanitize_text_field( (string) $request['to'] ),
		);
	}

	/**
	 * GET leads.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ) {
		$filters = self::filters( $request );
		$page    = max( 1, (int) $request['page'] );
		$per     = $request['per_page'] ? (int) $request['per_page'] : 20;
		$result  = Leads::query( $filters, $page, $per );
		$titles  = Campaigns::titles();
		foreach ( $result['items'] as &$lead ) {
			$lead['campaign'] = isset( $titles[ $lead['campaign_id'] ] ) ? $titles[ $lead['campaign_id'] ] : '#' . $lead['campaign_id'];
		}
		unset( $lead );
		$result['page']       = $page;
		$result['export_url'] = add_query_arg(
			array_merge(
				array_filter( $filters ),
				array(
					'action'   => 'dev3pu_export',
					'_wpnonce' => wp_create_nonce( 'dev3pu_export' ),
				)
			),
			admin_url( 'admin-post.php' )
		);
		return $this->respond( $result );
	}

	/**
	 * GET leads/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function show( \WP_REST_Request $request ) {
		$lead = Leads::get( (int) $request['id'] );
		if ( ! $lead ) {
			return $this->respond( new \WP_Error( 'dev3pu_not_found', __( 'Lead not found.', 'dev3-ai-popup-builder' ), array( 'status' => 404 ) ) );
		}
		$titles           = Campaigns::titles();
		$lead['campaign'] = isset( $titles[ $lead['campaign_id'] ] ) ? $titles[ $lead['campaign_id'] ] : '#' . $lead['campaign_id'];
		return $this->respond( $lead );
	}

	/**
	 * DELETE leads/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function destroy( \WP_REST_Request $request ) {
		return $this->respond( array( 'deleted' => Leads::delete( array( (int) $request['id'] ) ) ) );
	}

	/**
	 * DELETE leads { ids[] }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk_delete( \WP_REST_Request $request ) {
		return $this->respond( array( 'deleted' => Leads::delete( (array) $request->get_param( 'ids' ) ) ) );
	}

	/**
	 * POST leads/<id>/resync.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function resync( \WP_REST_Request $request ) {
		$lead     = Leads::get( (int) $request['id'] );
		$campaign = $lead ? Campaigns::get( $lead['campaign_id'] ) : null;
		if ( ! $lead || ! $campaign ) {
			return $this->respond( new \WP_Error( 'dev3pu_not_found', __( 'Lead or campaign not found.', 'dev3-ai-popup-builder' ), array( 'status' => 404 ) ) );
		}
		Services::sync_lead( $lead, $campaign );
		return $this->respond( Leads::get( $lead['id'] ) );
	}
}
