<?php
/**
 * Lead repository, CSV export and GDPR exporter/eraser.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Data;

use Dev3\AiPopupBuilder\Core3\Core\Privacy;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * Leads.
 */
class Leads {

	const STATUSES = array( 'new', 'synced', 'partial', 'failed' );

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
		$fields = json_decode( (string) $row['fields'], true );
		$utm    = json_decode( (string) $row['utm'], true );
		$sync   = json_decode( (string) $row['sync'], true );
		return array(
			'id'           => (int) $row['id'],
			'campaign_id'  => (int) $row['campaign_id'],
			'variant'      => (string) $row['variant'],
			'email'        => (string) $row['email'],
			'name'         => (string) $row['name'],
			'phone'        => (string) $row['phone'],
			'fields'       => is_array( $fields ) ? $fields : array(),
			'consent'      => (bool) $row['consent'],
			'consent_text' => (string) $row['consent_text'],
			'page_url'     => (string) $row['page_url'],
			'referrer'     => (string) $row['referrer'],
			'utm'          => is_array( $utm ) ? $utm : array(),
			'device'       => (string) $row['device'],
			'sync'         => is_array( $sync ) ? $sync : array(),
			'status'       => (string) $row['status'],
			'coupon'       => (string) $row['coupon'],
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
		);
	}

	/**
	 * One lead.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'leads' );
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ) );
	}

	/**
	 * Existing lead for a campaign + email.
	 *
	 * @param int    $campaign_id Campaign.
	 * @param string $email       Email.
	 * @return array|null
	 */
	public static function find( $campaign_id, $email ) {
		global $wpdb;
		$table = Tables::name( 'leads' );
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d AND email = %s ORDER BY id DESC LIMIT 1", (int) $campaign_id, $email ) ) );
	}

	/**
	 * Inserts or updates (same campaign + email) a lead.
	 *
	 * @param array $data Clean data.
	 * @return array { id, created: bool }
	 */
	public static function upsert( array $data ) {
		global $wpdb;
		$table    = Tables::name( 'leads' );
		$now      = isset( $data['created_at'] ) ? $data['created_at'] : current_time( 'mysql' );
		$existing = '' !== $data['email'] ? self::find( $data['campaign_id'], $data['email'] ) : null;
		$row      = array(
			'campaign_id'  => (int) $data['campaign_id'],
			'variant'      => (string) $data['variant'],
			'email'        => (string) $data['email'],
			'name'         => (string) $data['name'],
			'phone'        => (string) $data['phone'],
			'fields'       => wp_json_encode( $data['fields'] ),
			'consent'      => ! empty( $data['consent'] ) ? 1 : 0,
			'consent_text' => (string) $data['consent_text'],
			'page_url'     => (string) $data['page_url'],
			'referrer'     => (string) $data['referrer'],
			'utm'          => wp_json_encode( $data['utm'] ),
			'device'       => (string) $data['device'],
			'ip_hash'      => (string) $data['ip_hash'],
			'updated_at'   => $now,
		);
		if ( $existing ) {
			// Keep the original first-touch data when the new value is empty.
			foreach ( array( 'name', 'phone' ) as $key ) {
				if ( '' === $row[ $key ] ) {
					$row[ $key ] = $existing[ $key ];
				}
			}
			$row['fields'] = wp_json_encode( array_merge( $existing['fields'], $data['fields'] ) );
			$wpdb->update( $table, $row, array( 'id' => $existing['id'] ) );
			return array(
				'id'      => $existing['id'],
				'created' => false,
			);
		}
		$row['sync']       = '{}';
		$row['status']     = 'new';
		$row['coupon']     = '';
		$row['created_at'] = $now;
		$wpdb->insert( $table, $row );
		return array(
			'id'      => (int) $wpdb->insert_id,
			'created' => true,
		);
	}

	/**
	 * Updates sync results and the derived status.
	 *
	 * @param int   $id   Lead.
	 * @param array $sync service => { ok, message, at }.
	 */
	public static function set_sync( $id, array $sync ) {
		global $wpdb;
		$status = 'new';
		if ( $sync ) {
			$ok     = count( array_filter( wp_list_pluck( $sync, 'ok' ) ) );
			$status = count( $sync ) === $ok ? 'synced' : ( 0 === $ok ? 'failed' : 'partial' );
		}
		$wpdb->update(
			Tables::name( 'leads' ),
			array(
				'sync'   => wp_json_encode( (object) $sync ),
				'status' => $status,
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Stores the coupon shown to the lead.
	 *
	 * @param int    $id     Lead.
	 * @param string $coupon Code.
	 */
	public static function set_coupon( $id, $coupon ) {
		global $wpdb;
		$wpdb->update( Tables::name( 'leads' ), array( 'coupon' => substr( (string) $coupon, 0, 60 ) ), array( 'id' => (int) $id ) );
	}

	/**
	 * WHERE clause from filters.
	 *
	 * @param array $filters { campaign_id, status, search, from, to }.
	 * @return string
	 */
	private static function where( array $filters ) {
		global $wpdb;
		$where = array( '1=1' );
		if ( ! empty( $filters['campaign_id'] ) ) {
			$where[] = $wpdb->prepare( 'campaign_id = %d', (int) $filters['campaign_id'] );
		}
		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], self::STATUSES, true ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $filters['status'] );
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[] = $wpdb->prepare( '(email LIKE %s OR name LIKE %s OR phone LIKE %s)', $like, $like, $like );
		}
		if ( ! empty( $filters['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['from'] ) ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', $filters['from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['to'] ) ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', $filters['to'] . ' 23:59:59' );
		}
		return implode( ' AND ', $where );
	}

	/**
	 * Paged list.
	 *
	 * @param array $filters  Filters.
	 * @param int   $page     Page.
	 * @param int   $per_page Per page.
	 * @return array { items, total }
	 */
	public static function query( array $filters, $page = 1, $per_page = 20 ) {
		global $wpdb;
		$table    = Tables::name( 'leads' );
		$where    = self::where( $filters );
		$per_page = min( 200, max( 1, (int) $per_page ) );
		$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Count since a date.
	 *
	 * @param string $since Y-m-d H:i:s.
	 * @return int
	 */
	public static function count_since( $since ) {
		global $wpdb;
		$table = Tables::name( 'leads' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since ) );
	}

	/**
	 * Deletes leads.
	 *
	 * @param int[] $ids Ids.
	 * @return int Deleted.
	 */
	public static function delete( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$table = Tables::name( 'leads' );
		$in    = implode( ',', $ids );
		return (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" );
	}

	/**
	 * Retention: deletes leads older than N days (0 = keep forever).
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public static function prune( $days ) {
		global $wpdb;
		if ( $days <= 0 ) {
			return 0;
		}
		$table = Tables::name( 'leads' );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * CSV rows (header first) for the filters.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	public static function csv_rows( array $filters ) {
		global $wpdb;
		$table  = Tables::name( 'leads' );
		$where  = self::where( $filters );
		$titles = Campaigns::titles();
		$rows   = array_map( array( __CLASS__, 'hydrate' ), (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT 50000" ) );
		$extra  = array();
		foreach ( $rows as $lead ) {
			foreach ( array_keys( $lead['fields'] ) as $key ) {
				$extra[ $key ] = true;
			}
		}
		$extra  = array_keys( $extra );
		$header = array_merge( array( 'id', 'created_at', 'email', 'name', 'phone' ), $extra, array( 'consent', 'consent_text', 'campaign', 'variant', 'status', 'coupon', 'page_url', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'device' ) );
		$out    = array( $header );
		foreach ( $rows as $lead ) {
			$line = array( $lead['id'], $lead['created_at'], $lead['email'], $lead['name'], $lead['phone'] );
			foreach ( $extra as $key ) {
				$value  = isset( $lead['fields'][ $key ] ) ? $lead['fields'][ $key ] : '';
				$line[] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
			$line  = array_merge(
				$line,
				array(
					$lead['consent'] ? 'yes' : 'no',
					wp_strip_all_tags( $lead['consent_text'] ),
					isset( $titles[ $lead['campaign_id'] ] ) ? $titles[ $lead['campaign_id'] ] : '#' . $lead['campaign_id'],
					$lead['variant'],
					$lead['status'],
					$lead['coupon'],
					$lead['page_url'],
					$lead['referrer'],
					isset( $lead['utm']['utm_source'] ) ? $lead['utm']['utm_source'] : '',
					isset( $lead['utm']['utm_medium'] ) ? $lead['utm']['utm_medium'] : '',
					isset( $lead['utm']['utm_campaign'] ) ? $lead['utm']['utm_campaign'] : '',
					$lead['device'],
				)
			);
			$out[] = array_map( array( __CLASS__, 'csv_safe' ), $line );
		}
		return $out;
	}

	/**
	 * Guards against CSV formula injection.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}

	/**
	 * CSV text for rows (with BOM so Excel reads UTF-8).
	 *
	 * @param array $rows Rows.
	 * @return string
	 */
	public static function to_csv( array $rows ) {
		$csv = "\xEF\xBB\xBF";
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $cell ) {
				$cell    = (string) $cell;
				$cells[] = preg_match( '/[",\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
			}
			$csv .= implode( ',', $cells ) . "\r\n";
		}
		return $csv;
	}

	/**
	 * Registers the GDPR exporter + eraser.
	 */
	public static function register_privacy() {
		Privacy::add_exporter( 'Leads', array( __CLASS__, 'export_personal_data' ) );
		Privacy::add_eraser( 'Leads', array( __CLASS__, 'erase_personal_data' ) );
	}

	/**
	 * Exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export_personal_data( $email, $page = 1 ) {
		global $wpdb;
		$table = Tables::name( 'leads' );
		$rows  = array_map( array( __CLASS__, 'hydrate' ), (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id ASC LIMIT 100 OFFSET %d", (string) $email, ( max( 1, (int) $page ) - 1 ) * 100 ) ) );
		$data  = array();
		foreach ( $rows as $lead ) {
			$items = array(
				array(
					'name'  => __( 'Email', 'dev3-ai-popup-builder' ),
					'value' => $lead['email'],
				),
				array(
					'name'  => __( 'Name', 'dev3-ai-popup-builder' ),
					'value' => $lead['name'],
				),
				array(
					'name'  => __( 'Phone', 'dev3-ai-popup-builder' ),
					'value' => $lead['phone'],
				),
				array(
					'name'  => __( 'Consent', 'dev3-ai-popup-builder' ),
					'value' => $lead['consent'] ? wp_strip_all_tags( $lead['consent_text'] ) : __( 'No', 'dev3-ai-popup-builder' ),
				),
				array(
					'name'  => __( 'Page', 'dev3-ai-popup-builder' ),
					'value' => $lead['page_url'],
				),
				array(
					'name'  => __( 'Date', 'dev3-ai-popup-builder' ),
					'value' => $lead['created_at'],
				),
			);
			foreach ( $lead['fields'] as $key => $value ) {
				$items[] = array(
					'name'  => $key,
					'value' => is_array( $value ) ? implode( ', ', $value ) : (string) $value,
				);
			}
			$data[] = array(
				'group_id'    => 'dev3pu-leads',
				'group_label' => __( 'Popup leads', 'dev3-ai-popup-builder' ),
				'item_id'     => 'dev3pu-lead-' . $lead['id'],
				'data'        => $items,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 100,
		);
	}

	/**
	 * Eraser: deletes the visitor's leads.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase_personal_data( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$table   = Tables::name( 'leads' );
		$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email = %s", (string) $email ) );
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
