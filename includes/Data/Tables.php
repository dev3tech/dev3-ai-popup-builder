<?php
/**
 * Plugin tables (dbDelta through the dev3pu_schema_sql filter).
 *
 * @package Dev3\AiPopupBuilder
 */

namespace Dev3\AiPopupBuilder\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + schema.
 */
class Tables {

	/**
	 * Table name.
	 *
	 * @param string $name campaigns|stats|leads.
	 * @return string
	 */
	public static function name( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'dev3pu_' . $name;
	}

	/**
	 * All plugin tables.
	 *
	 * @return array
	 */
	public static function all() {
		return array( self::name( 'campaigns' ), self::name( 'stats' ), self::name( 'leads' ) );
	}

	/**
	 * Adds CREATE TABLE statements.
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$campaigns = self::name( 'campaigns' );
		$stats     = self::name( 'stats' );
		$leads     = self::name( 'leads' );
		$sql[]     = "CREATE TABLE {$campaigns} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(190) NOT NULL DEFAULT '',
			type varchar(20) NOT NULL DEFAULT 'popup',
			status varchar(20) NOT NULL DEFAULT 'draft',
			priority smallint(5) unsigned NOT NULL DEFAULT 10,
			config longtext NOT NULL,
			starts_at datetime NULL DEFAULT NULL,
			ends_at datetime NULL DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset};";
		$sql[]     = "CREATE TABLE {$stats} (
			campaign_id bigint(20) unsigned NOT NULL,
			variant varchar(8) NOT NULL DEFAULT 'a',
			day date NOT NULL,
			views int(10) unsigned NOT NULL DEFAULT 0,
			conversions int(10) unsigned NOT NULL DEFAULT 0,
			closes int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (campaign_id,variant,day),
			KEY day (day)
		) {$charset};";
		$sql[]     = "CREATE TABLE {$leads} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variant varchar(8) NOT NULL DEFAULT 'a',
			email varchar(190) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			fields longtext NOT NULL,
			consent tinyint(1) unsigned NOT NULL DEFAULT 0,
			consent_text text NOT NULL,
			page_url varchar(500) NOT NULL DEFAULT '',
			referrer varchar(500) NOT NULL DEFAULT '',
			utm text NOT NULL,
			device varchar(10) NOT NULL DEFAULT '',
			ip_hash char(40) NOT NULL DEFAULT '',
			sync text NOT NULL,
			status varchar(12) NOT NULL DEFAULT 'new',
			coupon varchar(60) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY campaign_email (campaign_id,email),
			KEY email (email),
			KEY created (created_at)
		) {$charset};";
		return $sql;
	}
}
