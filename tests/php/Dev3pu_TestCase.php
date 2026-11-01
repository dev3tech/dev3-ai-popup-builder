<?php
/**
 * Base test case: plugin tables, clean settings, captured HTTP with queued fake responses.
 *
 * @package Dev3\AiPopupBuilder
 */

// phpcs:ignoreFile -- test helpers.

use Dev3\AiPopupBuilder\Campaign\Model;
use Dev3\AiPopupBuilder\Core3\Api\Ai;
use Dev3\AiPopupBuilder\Core3\Core\Cache;
use Dev3\AiPopupBuilder\Core3\Core\Schema;
use Dev3\AiPopupBuilder\Core3\Core\Settings_Store;
use Dev3\AiPopupBuilder\Data\Campaigns;
use Dev3\AiPopupBuilder\Data\Tables;

abstract class Dev3pu_TestCase extends WP_UnitTestCase {

	/** @var array Captured requests. */
	protected $requests = array();

	/** @var array Fake responses ([status, body array] or callable); the last repeats. */
	protected $responses = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3PU_DB_VERSION );
		global $wpdb;
		foreach ( Tables::all() as $table ) {
			$wpdb->query( "DELETE FROM {$table}" );
		}
		update_option( Settings_Store::OPTION, array() );
		delete_option( 'dev3pu_secrets' );
		Settings_Store::flush();
		Campaigns::flush();
		Cache::reset_memo();
		Ai::$client = null;
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'dev3pu_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3pu_jobs_use_action_scheduler', '__return_false' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3pu_retry_delay_ms', '__return_zero' );
		remove_filter( 'dev3pu_jobs_use_action_scheduler', '__return_false' );
		wp_cache_flush();
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'     => $url,
			'method'  => isset( $args['method'] ) ? $args['method'] : 'GET',
			'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
			'raw'     => isset( $args['body'] ) ? (string) $args['body'] : '',
			'body'    => isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null,
		);
		$next = count( $this->responses ) > 1 ? array_shift( $this->responses ) : ( $this->responses ? $this->responses[0] : array( 200, array() ) );
		if ( is_callable( $next ) ) {
			$next = $next( end( $this->requests ) );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $next[1] ),
			'response' => array(
				'code'    => $next[0],
				'message' => 'x',
			),
			'cookies'  => array(),
		);
	}

	/** Gemini JSON response. */
	protected function gemini( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 100,
					'candidatesTokenCount' => 50,
					'totalTokenCount'      => 150,
				),
			),
		);
	}

	protected function with_key() {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Settings_Store::flush();
	}

	/**
	 * Creates a campaign.
	 *
	 * @param array $over Overrides (config keys merged one level deep).
	 * @return array
	 */
	protected function make_campaign( array $over = array() ) {
		$config = Model::defaults();
		foreach ( isset( $over['config'] ) ? $over['config'] : array() as $key => $value ) {
			$config[ $key ] = is_array( $value ) && isset( $config[ $key ] ) && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ? array_merge( $config[ $key ], $value ) : $value;
		}
		$over['config'] = $config;
		$id             = Campaigns::create( array_merge( array( 'title' => 'Test', 'type' => 'popup' ), $over ) );
		if ( isset( $over['status'] ) ) {
			Campaigns::update( $id, array( 'status' => $over['status'] ) );
		}
		return Campaigns::get( $id );
	}

	protected function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}

	protected function rest( $method, $route, array $params = array(), $nonce = true, array $headers = array() ) {
		$req = new WP_REST_Request( $method, '/dev3pu/v1/' . ltrim( $route, '/' ) );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		if ( in_array( $method, array( 'POST', 'PUT' ), true ) ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		} else {
			foreach ( $params as $k => $v ) {
				$req->set_param( $k, $v );
			}
		}
		return rest_do_request( $req );
	}
}
