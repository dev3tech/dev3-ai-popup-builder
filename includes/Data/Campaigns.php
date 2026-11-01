<?php
/**
 * Campaign repository.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Data;

use Dev3\AiPopupBuilder\Campaign\Model;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * CRUD over {prefix}dev3pu_campaigns.
 */
class Campaigns {

	const CACHE_GROUP = 'dev3pu';

	/**
	 * Active campaigns cache key.
	 */
	const ACTIVE_KEY = 'dev3pu_active_campaigns';

	/**
	 * Row → array.
	 *
	 * @param object|array|null $row Row.
	 * @return array|null
	 */
	public static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row    = (array) $row;
		$config = json_decode( (string) $row['config'], true );
		return array(
			'id'         => (int) $row['id'],
			'title'      => (string) $row['title'],
			'type'       => (string) $row['type'],
			'status'     => (string) $row['status'],
			'priority'   => (int) $row['priority'],
			'starts_at'  => $row['starts_at'] ? (string) $row['starts_at'] : null,
			'ends_at'    => $row['ends_at'] ? (string) $row['ends_at'] : null,
			'config'     => Model::sanitize_config( is_array( $config ) ? $config : array(), (string) $row['type'] ),
			'created_by' => (int) $row['created_by'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		);
	}

	/**
	 * One campaign.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'campaigns' );
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ) );
	}

	/**
	 * All campaigns (optionally by status).
	 *
	 * @param string $status Status or ''.
	 * @return array
	 */
	public static function all( $status = '' ) {
		global $wpdb;
		$table = Tables::name( 'campaigns' );
		$rows  = '' === $status
			? $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT 500" )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY priority DESC, id ASC LIMIT 500", $status ) );
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Active campaigns within their schedule (cached for the request + object cache).
	 *
	 * @return array
	 */
	public static function active() {
		$cached = wp_cache_get( self::ACTIVE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			$list = $cached;
		} else {
			$list = self::all( 'active' );
			wp_cache_set( self::ACTIVE_KEY, $list, self::CACHE_GROUP, 300 );
		}
		$now = current_time( 'mysql' );
		return array_values(
			array_filter(
				$list,
				static function ( $c ) use ( $now ) {
					return ( ! $c['starts_at'] || $c['starts_at'] <= $now ) && ( ! $c['ends_at'] || $c['ends_at'] > $now );
				}
			)
		);
	}

	/**
	 * Creates a campaign.
	 *
	 * @param array $input Raw campaign.
	 * @return int|\WP_Error Id.
	 */
	public static function create( array $input ) {
		global $wpdb;
		$clean = Model::sanitize_campaign( $input );
		$now   = current_time( 'mysql' );
		$ok    = $wpdb->insert(
			Tables::name( 'campaigns' ),
			array(
				'title'      => $clean['title'],
				'type'       => $clean['type'],
				'status'     => $clean['status'],
				'priority'   => $clean['priority'],
				'config'     => wp_json_encode( $clean['config'] ),
				'starts_at'  => $clean['starts_at'],
				'ends_at'    => $clean['ends_at'],
				'created_by' => get_current_user_id(),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'dev3pu_db', __( 'Could not save the campaign.', 'dev3-ai-popup-builder' ), array( 'status' => 500 ) );
		}
		self::flush();
		$id = (int) $wpdb->insert_id;
		/**
		 * A campaign was created.
		 *
		 * @param int $id Campaign id.
		 */
		do_action( 'dev3pu_campaign_created', $id );
		return $id;
	}

	/**
	 * Updates a campaign (partial input allowed).
	 *
	 * @param int   $id    Id.
	 * @param array $input Raw input.
	 * @return array|\WP_Error Updated campaign.
	 */
	public static function update( $id, array $input ) {
		global $wpdb;
		$current = self::get( $id );
		if ( ! $current ) {
			return new \WP_Error( 'dev3pu_not_found', __( 'Campaign not found.', 'dev3-ai-popup-builder' ), array( 'status' => 404 ) );
		}
		$clean = Model::sanitize_campaign( $input, $current );
		$wpdb->update(
			Tables::name( 'campaigns' ),
			array(
				'title'      => $clean['title'],
				'type'       => $clean['type'],
				'status'     => $clean['status'],
				'priority'   => $clean['priority'],
				'config'     => wp_json_encode( $clean['config'] ),
				'starts_at'  => $clean['starts_at'],
				'ends_at'    => $clean['ends_at'],
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
		self::flush();
		return self::get( $id );
	}

	/**
	 * Duplicates as a draft.
	 *
	 * @param int $id Id.
	 * @return int|\WP_Error
	 */
	public static function duplicate( $id ) {
		$c = self::get( $id );
		if ( ! $c ) {
			return new \WP_Error( 'dev3pu_not_found', __( 'Campaign not found.', 'dev3-ai-popup-builder' ), array( 'status' => 404 ) );
		}
		$c['title']  = sprintf( /* translators: %s: campaign title */ __( '%s (copy)', 'dev3-ai-popup-builder' ), $c['title'] );
		$c['status'] = 'draft';
		return self::create( $c );
	}

	/**
	 * Deletes a campaign and its stats (leads are kept).
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$done = (bool) $wpdb->delete( Tables::name( 'campaigns' ), array( 'id' => (int) $id ) );
		$wpdb->delete( Tables::name( 'stats' ), array( 'campaign_id' => (int) $id ) );
		self::flush();
		return $done;
	}

	/**
	 * Clears caches.
	 */
	public static function flush() {
		wp_cache_delete( self::ACTIVE_KEY, self::CACHE_GROUP );
	}

	/**
	 * Id => title map.
	 *
	 * @return array
	 */
	public static function titles() {
		global $wpdb;
		$table = Tables::name( 'campaigns' );
		$out   = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, title FROM {$table}" ) as $row ) {
			$out[ (int) $row->id ] = (string) $row->title;
		}
		return $out;
	}
}
