<?php
/**
 * Daily counters per campaign + variant, and A/B maths.
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Data;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Stats.
 */
class Stats {

	const EVENTS = array(
		'view'    => 'views',
		'convert' => 'conversions',
		'close'   => 'closes',
	);

	/**
	 * Adds 1 to a counter.
	 *
	 * @param int    $campaign_id Campaign.
	 * @param string $variant     Variant id.
	 * @param string $event       view|convert|close.
	 * @param string $day         Y-m-d (default today, site time).
	 * @return bool
	 */
	public static function bump( $campaign_id, $variant, $event, $day = '' ) {
		global $wpdb;
		if ( ! isset( self::EVENTS[ $event ] ) ) {
			return false;
		}
		$col   = self::EVENTS[ $event ];
		$table = Tables::name( 'stats' );
		$day   = '' !== $day ? $day : current_time( 'Y-m-d' );
		return false !== $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (campaign_id, variant, day, {$col}) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE {$col} = {$col} + 1",
				(int) $campaign_id,
				substr( (string) $variant, 0, 8 ),
				$day
			)
		);
	}

	/**
	 * Sets counters for a day (seeder/tests).
	 *
	 * @param int    $campaign_id Campaign.
	 * @param string $variant     Variant.
	 * @param string $day         Day.
	 * @param int    $views       Views.
	 * @param int    $conversions Conversions.
	 * @param int    $closes      Closes.
	 */
	public static function set( $campaign_id, $variant, $day, $views, $conversions, $closes = 0 ) {
		global $wpdb;
		$table = Tables::name( 'stats' );
		$wpdb->query(
			$wpdb->prepare(
				"REPLACE INTO {$table} (campaign_id, variant, day, views, conversions, closes) VALUES (%d, %s, %s, %d, %d, %d)",
				(int) $campaign_id,
				$variant,
				$day,
				(int) $views,
				(int) $conversions,
				(int) $closes
			)
		);
	}

	/**
	 * Totals per campaign for the last N days: id => { views, conversions, closes, rate }.
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function totals( $days = 30 ) {
		global $wpdb;
		$table = Tables::name( 'stats' );
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT campaign_id, SUM(views) v, SUM(conversions) c, SUM(closes) x FROM {$table} WHERE day >= %s GROUP BY campaign_id", $since ) );
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->campaign_id ] = self::row( (int) $row->v, (int) $row->c, (int) $row->x );
		}
		return $out;
	}

	/**
	 * Per-variant totals for a campaign (all time).
	 *
	 * @param int $campaign_id Campaign.
	 * @return array variant => { views, conversions, closes, rate }
	 */
	public static function by_variant( $campaign_id ) {
		global $wpdb;
		$table = Tables::name( 'stats' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT variant, SUM(views) v, SUM(conversions) c, SUM(closes) x FROM {$table} WHERE campaign_id = %d GROUP BY variant", (int) $campaign_id ) );
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->variant ] = self::row( (int) $row->v, (int) $row->c, (int) $row->x );
		}
		return $out;
	}

	/**
	 * Daily series for the last N days (all campaigns or one).
	 *
	 * @param int $days        Days.
	 * @param int $campaign_id Campaign or 0.
	 * @return array [ { day, views, conversions } ]
	 */
	public static function series( $days = 30, $campaign_id = 0 ) {
		global $wpdb;
		$table = Tables::name( 'stats' );
		$today = strtotime( current_time( 'Y-m-d' ) );
		$since = gmdate( 'Y-m-d', $today - ( $days - 1 ) * DAY_IN_SECONDS );
		$rows  = $campaign_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(views) v, SUM(conversions) c FROM {$table} WHERE day >= %s AND campaign_id = %d GROUP BY day", $since, (int) $campaign_id ) )
			: $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(views) v, SUM(conversions) c FROM {$table} WHERE day >= %s GROUP BY day", $since ) );
		$map   = array();
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row->day ] = array( (int) $row->v, (int) $row->c );
		}
		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day   = gmdate( 'Y-m-d', $today - $i * DAY_IN_SECONDS );
			$out[] = array(
				'day'         => $day,
				'views'       => isset( $map[ $day ] ) ? $map[ $day ][0] : 0,
				'conversions' => isset( $map[ $day ] ) ? $map[ $day ][1] : 0,
			);
		}
		return $out;
	}

	/**
	 * Row with rate.
	 *
	 * @param int $views       Views.
	 * @param int $conversions Conversions.
	 * @param int $closes      Closes.
	 * @return array
	 */
	public static function row( $views, $conversions, $closes = 0 ) {
		return array(
			'views'       => $views,
			'conversions' => $conversions,
			'closes'      => $closes,
			'rate'        => $views > 0 ? round( 100 * $conversions / $views, 2 ) : 0.0,
		);
	}

	/**
	 * A/B results: each variant vs the control (first variant).
	 *
	 * @param array $campaign Campaign.
	 * @return array { variants: [ { id, name, weight, views, conversions, rate, uplift, confidence, significant } ], leader, enough_data }
	 */
	public static function results( array $campaign ) {
		$totals   = self::by_variant( $campaign['id'] );
		$variants = $campaign['config']['variants'];
		$control  = isset( $totals[ $variants[0]['id'] ] ) ? $totals[ $variants[0]['id'] ] : self::row( 0, 0 );
		$out      = array();
		$leader   = '';
		$best     = -1;
		$enough   = true;
		foreach ( $variants as $i => $variant ) {
			$t = isset( $totals[ $variant['id'] ] ) ? $totals[ $variant['id'] ] : self::row( 0, 0 );
			if ( $t['views'] < 100 ) {
				$enough = false;
			}
			$confidence = 0 === $i ? null : self::confidence( $control['views'], $control['conversions'], $t['views'], $t['conversions'] );
			$uplift     = ( 0 === $i || $control['rate'] <= 0 ) ? null : round( 100 * ( $t['rate'] - $control['rate'] ) / $control['rate'], 1 );
			$row        = array_merge(
				array(
					'id'     => $variant['id'],
					'name'   => $variant['name'],
					'weight' => $variant['weight'],
				),
				$t,
				array(
					'uplift'      => $uplift,
					'confidence'  => $confidence,
					'significant' => null !== $confidence && $confidence >= 95 && $t['views'] >= 100 && $control['views'] >= 100,
				)
			);
			$out[]      = $row;
			if ( $t['views'] > 0 && $t['rate'] > $best ) {
				$best   = $t['rate'];
				$leader = $variant['id'];
			}
		}
		return array(
			'variants'    => $out,
			'leader'      => $leader,
			'enough_data' => $enough,
			'series'      => self::series( 30, $campaign['id'] ),
		);
	}

	/**
	 * Two-sided confidence (%) that two conversion rates differ (two-proportion z-test).
	 *
	 * @param int $n1 Views A.
	 * @param int $c1 Conversions A.
	 * @param int $n2 Views B.
	 * @param int $c2 Conversions B.
	 * @return float 0-100
	 */
	public static function confidence( $n1, $c1, $n2, $c2 ) {
		if ( $n1 <= 0 || $n2 <= 0 ) {
			return 0.0;
		}
		$p1 = $c1 / $n1;
		$p2 = $c2 / $n2;
		$p  = ( $c1 + $c2 ) / ( $n1 + $n2 );
		$se = sqrt( $p * ( 1 - $p ) * ( 1 / $n1 + 1 / $n2 ) );
		if ( $se <= 0 ) {
			return 0.0;
		}
		$z = abs( $p2 - $p1 ) / $se;
		return round( 100 * self::erf( $z / M_SQRT2 ), 1 );
	}

	/**
	 * Error function (Abramowitz-Stegun 7.1.26, |error| < 1.5e-7).
	 *
	 * @param float $x X.
	 * @return float
	 */
	public static function erf( $x ) {
		$sign = $x < 0 ? -1 : 1;
		$x    = abs( $x );
		$t    = 1 / ( 1 + 0.3275911 * $x );
		$y    = 1 - ( ( ( ( ( 1.061405429 * $t - 1.453152027 ) * $t ) + 1.421413741 ) * $t - 0.284496736 ) * $t + 0.254829592 ) * $t * exp( -$x * $x );
		return $sign * $y;
	}

	/**
	 * Dashboard KPIs: last N days vs previous N days.
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function kpis( $days = 30 ) {
		global $wpdb;
		$table = Tables::name( 'stats' );
		$today = strtotime( current_time( 'Y-m-d' ) );
		$cur   = gmdate( 'Y-m-d', $today - ( $days - 1 ) * DAY_IN_SECONDS );
		$prev  = gmdate( 'Y-m-d', $today - ( 2 * $days - 1 ) * DAY_IN_SECONDS );
		$a     = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(views) v, SUM(conversions) c FROM {$table} WHERE day >= %s", $cur ) );
		$b     = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(views) v, SUM(conversions) c FROM {$table} WHERE day >= %s AND day < %s", $prev, $cur ) );
		return array(
			'current'  => self::row( (int) ( $a ? $a->v : 0 ), (int) ( $a ? $a->c : 0 ) ),
			'previous' => self::row( (int) ( $b ? $b->v : 0 ), (int) ( $b ? $b->c : 0 ) ),
		);
	}
}
