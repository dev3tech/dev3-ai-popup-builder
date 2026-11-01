<?php
/**
 * Admin REST: campaigns, templates, results, dashboard, targeting options.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Rest;

use Dev3\AiPopupBuilder\Campaign\Model;
use Dev3\AiPopupBuilder\Campaign\Templates;
use Dev3\AiPopupBuilder\Core3\Rest\Base_Controller;
use Dev3\AiPopupBuilder\Data\Campaigns;
use Dev3\AiPopupBuilder\Data\Leads;
use Dev3\AiPopupBuilder\Data\Stats;
use Dev3\AiPopupBuilder\Leads\Coupons;

defined( 'ABSPATH' ) || exit;

/**
 * Campaigns controller.
 */
class Campaigns_Controller extends Base_Controller {

	/**
	 * Routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		$id     = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
		register_rest_route(
			self::NS,
			'/campaigns',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/campaigns/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $manage,
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $manage,
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $manage,
					'args'                => $id,
				),
			)
		);
		foreach ( array(
			'duplicate' => 'duplicate',
			'status'    => 'status',
			'winner'    => 'winner',
		) as $route => $method ) {
			register_rest_route(
				self::NS,
				'/campaigns/(?P<id>\d+)/' . $route,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $method ),
					'permission_callback' => $manage,
					'args'                => $id,
				)
			);
		}
		register_rest_route(
			self::NS,
			'/campaigns/(?P<id>\d+)/results',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'results' ),
				'permission_callback' => $manage,
				'args'                => $id,
			)
		);
		foreach ( array(
			'templates'         => 'templates',
			'dashboard'         => 'dashboard',
			'targeting/options' => 'targeting_options',
			'campaigns/options' => 'options',
		) as $route => $method ) {
			register_rest_route(
				self::NS,
				'/' . $route,
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, $method ),
					'permission_callback' => 'campaigns/options' === $route ? array( $this, 'can_generate' ) : $manage,
				)
			);
		}
	}

	/**
	 * List row with 30-day stats.
	 *
	 * @param array $c      Campaign.
	 * @param array $totals Totals.
	 * @return array
	 */
	private function summary( array $c, array $totals ) {
		$t = isset( $totals[ $c['id'] ] ) ? $totals[ $c['id'] ] : Stats::row( 0, 0 );
		return array(
			'id'         => $c['id'],
			'title'      => $c['title'],
			'type'       => $c['type'],
			'status'     => $c['status'],
			'priority'   => $c['priority'],
			'variants'   => count( $c['config']['variants'] ),
			'ab'         => $c['config']['ab']['enabled'],
			'views'      => $t['views'],
			'leads'      => $t['conversions'],
			'rate'       => $t['rate'],
			'starts_at'  => $c['starts_at'],
			'ends_at'    => $c['ends_at'],
			'updated_at' => $c['updated_at'],
		);
	}

	/**
	 * GET campaigns.
	 *
	 * @return \WP_REST_Response
	 */
	public function index() {
		$totals = Stats::totals( 30 );
		$rows   = array();
		foreach ( Campaigns::all() as $c ) {
			$rows[] = $this->summary( $c, $totals );
		}
		return $this->respond( $rows );
	}

	/**
	 * POST campaigns: { template?, campaign? }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create( \WP_REST_Request $request ) {
		$template = sanitize_key( (string) $request->get_param( 'template' ) );
		$input    = $request->get_param( 'campaign' );
		if ( '' !== $template ) {
			$input = Templates::campaign( $template );
			if ( ! $input ) {
				return $this->respond( new \WP_Error( 'dev3pu_template', __( 'Unknown template.', 'dev3-ai-popup-builder' ), array( 'status' => 400 ) ) );
			}
		}
		$input           = is_array( $input ) ? $input : array();
		$input['status'] = 'draft'; // New campaigns (templates, AI drafts) never go live on their own.
		$id              = Campaigns::create( $input );
		return is_wp_error( $id ) ? $this->respond( $id ) : $this->respond( Campaigns::get( $id ), 201 );
	}

	/**
	 * Loads or 404s.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function find( \WP_REST_Request $request ) {
		$c = Campaigns::get( (int) $request['id'] );
		return $c ? $c : new \WP_Error( 'dev3pu_not_found', __( 'Campaign not found.', 'dev3-ai-popup-builder' ), array( 'status' => 404 ) );
	}

	/**
	 * GET campaigns/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function show( \WP_REST_Request $request ) {
		$c = $this->find( $request );
		if ( ! is_wp_error( $c ) ) {
			$c['preview_url'] = add_query_arg( 'dev3pu_preview', $c['id'], home_url( '/' ) );
		}
		return $this->respond( $c );
	}

	/**
	 * PUT campaigns/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update( \WP_REST_Request $request ) {
		$input = $request->get_json_params();
		$input = is_array( $input ) ? $input : $request->get_params();
		unset( $input['id'] );
		$result = Campaigns::update( (int) $request['id'], $input );
		if ( ! is_wp_error( $result ) ) {
			$result['preview_url'] = add_query_arg( 'dev3pu_preview', $result['id'], home_url( '/' ) );
		}
		return $this->respond( $result );
	}

	/**
	 * DELETE campaigns/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function destroy( \WP_REST_Request $request ) {
		$c = $this->find( $request );
		if ( is_wp_error( $c ) ) {
			return $this->respond( $c );
		}
		Campaigns::delete( $c['id'] );
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * POST duplicate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function duplicate( \WP_REST_Request $request ) {
		$id = Campaigns::duplicate( (int) $request['id'] );
		return is_wp_error( $id ) ? $this->respond( $id ) : $this->respond( Campaigns::get( $id ), 201 );
	}

	/**
	 * POST status { status }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function status( \WP_REST_Request $request ) {
		$status = (string) $request->get_param( 'status' );
		if ( ! in_array( $status, Model::STATUSES, true ) ) {
			return $this->respond( new \WP_Error( 'dev3pu_status', __( 'Unknown status.', 'dev3-ai-popup-builder' ), array( 'status' => 400 ) ) );
		}
		return $this->respond( Campaigns::update( (int) $request['id'], array( 'status' => $status ) ) );
	}

	/**
	 * GET results.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function results( \WP_REST_Request $request ) {
		$c = $this->find( $request );
		return is_wp_error( $c ) ? $this->respond( $c ) : $this->respond( Stats::results( $c ) );
	}

	/**
	 * POST winner { variant }: serve only that variant, stop the test, keep history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function winner( \WP_REST_Request $request ) {
		$c = $this->find( $request );
		if ( is_wp_error( $c ) ) {
			return $this->respond( $c );
		}
		$variant = (string) $request->get_param( 'variant' );
		if ( ! in_array( $variant, wp_list_pluck( $c['config']['variants'], 'id' ), true ) ) {
			return $this->respond( new \WP_Error( 'dev3pu_variant', __( 'Unknown variant.', 'dev3-ai-popup-builder' ), array( 'status' => 400 ) ) );
		}
		$config       = $c['config'];
		$config['ab'] = array(
			'enabled' => false,
			'winner'  => $variant,
		);
		return $this->respond( Campaigns::update( $c['id'], array( 'config' => $config ) ) );
	}

	/**
	 * GET templates.
	 *
	 * @return \WP_REST_Response
	 */
	public function templates() {
		return $this->respond( Templates::all() );
	}

	/**
	 * GET dashboard.
	 *
	 * @return \WP_REST_Response
	 */
	public function dashboard() {
		$kpis   = Stats::kpis( 30 );
		$totals = Stats::totals( 30 );
		$all    = Campaigns::all();
		$top    = array();
		foreach ( $all as $c ) {
			$top[] = $this->summary( $c, $totals );
		}
		usort(
			$top,
			static function ( $a, $b ) {
				$diff = $b['leads'] <=> $a['leads'];
				return 0 !== $diff ? $diff : $b['views'] <=> $a['views'];
			}
		);
		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'Y-m-d' ) ) - 29 * DAY_IN_SECONDS );
		return $this->respond(
			array(
				'kpis'      => $kpis,
				'leads_30'  => Leads::count_since( $since ),
				'active'    => count(
					array_filter(
						$all,
						static function ( $c ) {
							return 'active' === $c['status'];
						}
					)
				),
				'campaigns' => count( $all ),
				'series'    => Stats::series( 30 ),
				'top'       => array_slice( $top, 0, 5 ),
				'recent'    => Leads::query( array(), 1, 5 )['items'],
			)
		);
	}

	/**
	 * GET targeting/options: post types, taxonomies + terms, roles, WooCommerce.
	 *
	 * @return \WP_REST_Response
	 */
	public function targeting_options() {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' !== $pt->name ) {
				$types[] = array(
					'value' => $pt->name,
					'label' => $pt->labels->singular_name,
				);
			}
		}
		$terms = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( 'post_format' === $tax->name ) {
				continue;
			}
			foreach ( get_terms(
				array(
					'taxonomy'   => $tax->name,
					'number'     => 100,
					'hide_empty' => false,
				)
			) as $term ) {
				if ( is_object( $term ) ) {
					$terms[] = array(
						'value' => $tax->name . ':' . $term->term_id,
						'label' => $tax->labels->singular_name . ': ' . $term->name,
					);
				}
			}
		}
		$roles = array();
		foreach ( wp_roles()->roles as $key => $role ) {
			$roles[] = array(
				'value' => $key,
				'label' => translate_user_role( $role['name'] ),
			);
		}
		return $this->respond(
			array(
				'post_types'  => $types,
				'terms'       => $terms,
				'roles'       => $roles,
				'woocommerce' => class_exists( 'WooCommerce' ),
				'coupons'     => Coupons::available(),
				'currency'    => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '',
			)
		);
	}

	/**
	 * GET campaigns/options (block editor pickers).
	 *
	 * @return \WP_REST_Response
	 */
	public function options() {
		$out = array();
		foreach ( Campaigns::all() as $c ) {
			if ( 'archived' === $c['status'] ) {
				continue;
			}
			$out[] = array(
				'value'  => $c['id'],
				'label'  => $c['title'],
				'type'   => $c['type'],
				'status' => $c['status'],
			);
		}
		return $this->respond( $out );
	}
}
