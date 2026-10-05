<?php
/**
 * Activation, schema and upgrade routines.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's storage.
 */
final class Installer {

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = 'usdtf_db_version';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::seed_options();

		update_option( self::VERSION_OPTION, Database::SCHEMA_VERSION, false );

		Cron::schedule();

		$logger = new Logger();
		$logger->info( 'Plugin activated.', array( 'version' => USDTF_VERSION ) );
	}

	/**
	 * Runs on plugin deactivation. Stored data is kept.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Cron::unschedule();

		Scheduler::unschedule_all( Sync_Runner::HOOK_DISCOVER );
		Scheduler::unschedule_all( Sync_Runner::HOOK_PROCESS );
		Scheduler::unschedule_all( Sync_Runner::HOOK_FINALIZE );

		delete_option( Lock::OPTION );
	}

	/**
	 * Upgrade the schema when the plugin files are newer than the database.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::VERSION_OPTION, '' );

		if ( Database::SCHEMA_VERSION === $installed ) {
			return;
		}

		self::create_tables();
		self::seed_options();

		update_option( self::VERSION_OPTION, Database::SCHEMA_VERSION, false );

		Cron::schedule();
	}

	/**
	 * Create or update the plugin tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$jobs            = Database::jobs_table();
		$items           = Database::items_table();
		$rates           = Database::rates_table();
		$logs            = Database::logs_table();

		$schema = array();

		$schema[] = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(24) NOT NULL DEFAULT 'queued',
			job_type varchar(24) NOT NULL DEFAULT 'sync',
			scope longtext NULL,
			currency_mode varchar(16) NOT NULL DEFAULT 'usd',
			rate decimal(20,6) NOT NULL DEFAULT 0,
			previous_rate decimal(20,6) NULL,
			rounding varchar(12) NOT NULL DEFAULT 'up',
			increment decimal(20,4) NOT NULL DEFAULT 1,
			decimals smallint(3) NOT NULL DEFAULT 0,
			total_items bigint(20) unsigned NOT NULL DEFAULT 0,
			processed bigint(20) unsigned NOT NULL DEFAULT 0,
			changed bigint(20) unsigned NOT NULL DEFAULT 0,
			unchanged bigint(20) unsigned NOT NULL DEFAULT 0,
			skipped bigint(20) unsigned NOT NULL DEFAULT 0,
			failed bigint(20) unsigned NOT NULL DEFAULT 0,
			conflicts bigint(20) unsigned NOT NULL DEFAULT 0,
			attention bigint(20) unsigned NOT NULL DEFAULT 0,
			variations_processed bigint(20) unsigned NOT NULL DEFAULT 0,
			variations_changed bigint(20) unsigned NOT NULL DEFAULT 0,
			discovery_page bigint(20) unsigned NOT NULL DEFAULT 1,
			phase varchar(16) NOT NULL DEFAULT 'discover',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			current_item varchar(190) NULL,
			message text NULL,
			created_at datetime NULL,
			started_at datetime NULL,
			heartbeat_at datetime NULL,
			updated_at datetime NULL,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY job_type (job_type),
			KEY heartbeat_at (heartbeat_at),
			KEY created_at (created_at),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_type varchar(20) NOT NULL DEFAULT 'product',
			expected_revision bigint(20) unsigned NOT NULL DEFAULT 0,
			source_regular varchar(32) NULL,
			source_sale varchar(32) NULL,
			old_regular varchar(32) NULL,
			old_sale varchar(32) NULL,
			new_regular varchar(32) NULL,
			new_sale varchar(32) NULL,
			toman_regular varchar(32) NULL,
			toman_sale varchar(32) NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			attention tinyint(1) NOT NULL DEFAULT 0,
			child_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
			child_total bigint(20) unsigned NOT NULL DEFAULT 0,
			parent_synced tinyint(1) NOT NULL DEFAULT 0,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			message text NULL,
			retry_after datetime NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (id),
			KEY job_status (job_id,status,id),
			KEY object_id (object_id),
			KEY parent_id (parent_id)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$rates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rate decimal(20,6) NOT NULL DEFAULT 0,
			previous_rate decimal(20,6) NULL,
			change_percent decimal(10,4) NULL,
			source varchar(20) NOT NULL DEFAULT 'admin',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			products_checked bigint(20) unsigned NOT NULL DEFAULT 0,
			products_changed bigint(20) unsigned NOT NULL DEFAULT 0,
			products_failed bigint(20) unsigned NOT NULL DEFAULT 0,
			conflicts bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL DEFAULT 'active',
			note varchar(190) NULL,
			created_at datetime NULL,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY job_id (job_id)
		) {$charset_collate};";

		$schema[] = "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(12) NOT NULL DEFAULT 'info',
			message text NULL,
			context longtext NULL,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY job_id (job_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		foreach ( $schema as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Make sure every option the plugin relies on exists.
	 *
	 * @return void
	 */
	public static function seed_options() {
		$settings = new Settings();
		$stored   = get_option( Settings::OPTION, null );

		if ( ! is_array( $stored ) ) {
			update_option( Settings::OPTION, Settings::defaults() );
		} else {
			$settings->update( $stored );
		}

		if ( '' === (string) get_option( Settings::OPTION_SYNCED_CURRENCY_MODE, '' ) ) {
			$settings->set_synced_currency_mode( (string) $settings->get( 'currency_mode' ) );
		}

		if ( '' === (string) get_option( Settings::OPTION_RATE, '' ) ) {
			update_option( Settings::OPTION_RATE, '0', false );
		}

		Scheduler::ensure_token();
	}

	/**
	 * Remove the plugin's own data. Used by uninstall.php.
	 *
	 * Options, cron events and the plugin tables are always removed. Product
	 * metadata (the canonical Toman prices) is only removed when the store owner
	 * opted in, because it is real price data that a reinstall can reuse.
	 *
	 * @param bool $purge_product_meta Whether product meta should be deleted too.
	 * @return void
	 */
	public static function uninstall( $purge_product_meta = false ) {
		global $wpdb;

		Cron::unschedule();

		$options = array(
			Settings::OPTION,
			Settings::OPTION_RATE,
			Settings::OPTION_PENDING_RATE,
			Settings::OPTION_SYNCED_CURRENCY_MODE,
			self::VERSION_OPTION,
			Lock::OPTION,
			Scheduler::TOKEN_OPTION,
			'usdtf_last_error',
			'usdtf_health_snapshot',
			'usdtf_needs_full_resync',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		$tables = array(
			Database::jobs_table(),
			Database::items_table(),
			Database::rates_table(),
			Database::logs_table(),
		);

		foreach ( $tables as $table ) {
			// Dropping the plugin's own tables is the documented uninstall behaviour.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are internal.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		delete_option( self::VERSION_OPTION );

		if ( ! $purge_product_meta ) {
			return;
		}

		// Remove per product metadata created by the plugin.
		foreach ( Product_Pricing::meta_keys() as $meta_key ) {
			// A bulk delete by meta key is the point of the purge and only runs on request.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) );
		}
	}
}
