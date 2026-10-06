<?php
/**
 * Admin screens.
 *
 * @package USDTF
 */

namespace USDTF\Admin;

use USDTF\Capabilities;
use USDTF\Health;
use USDTF\Job;
use USDTF\Job_Repository;
use USDTF\Logger;
use USDTF\Product_Pricing;
use USDTF\Product_Repository;
use USDTF\Rate_Repository;
use USDTF\Scheduler;
use USDTF\Settings;
use USDTF\Sync_Runner;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WooCommerce submenu, the settings screen and the admin assets.
 */
final class Admin {

	/**
	 * Admin page slug.
	 */
	const PAGE = 'usdtf';

	/**
	 * Option group used by the settings screen.
	 */
	const OPTION_GROUP = 'usdtf_settings_group';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Synchronization engine.
	 *
	 * @var Sync_Runner
	 */
	private $runner;

	/**
	 * Rate storage.
	 *
	 * @var Rate_Repository
	 */
	private $rates;

	/**
	 * Job storage.
	 *
	 * @var Job_Repository
	 */
	private $jobs;

	/**
	 * Product discovery.
	 *
	 * @var Product_Repository
	 */
	private $products;

	/**
	 * Pricing model.
	 *
	 * @var Product_Pricing
	 */
	private $pricing;

	/**
	 * Diagnostics.
	 *
	 * @var Health
	 */
	private $health;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Product editing screen integration.
	 *
	 * @var Product_Panel
	 */
	private $panel;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings  Settings.
	 * @param Sync_Runner        $runner    Synchronization engine.
	 * @param Rate_Repository    $rates     Rate storage.
	 * @param Job_Repository     $jobs      Job storage.
	 * @param Product_Repository $products  Product discovery.
	 * @param Product_Pricing    $pricing   Pricing model.
	 * @param Health             $health    Diagnostics.
	 * @param Logger             $logger    Logger.
	 * @param Scheduler          $scheduler Scheduler.
	 */
	public function __construct( Settings $settings, Sync_Runner $runner, Rate_Repository $rates, Job_Repository $jobs, Product_Repository $products, Product_Pricing $pricing, Health $health, Logger $logger, Scheduler $scheduler ) {
		$this->settings  = $settings;
		$this->runner    = $runner;
		$this->rates     = $rates;
		$this->jobs      = $jobs;
		$this->products  = $products;
		$this->pricing   = $pricing;
		$this->health    = $health;
		$this->logger    = $logger;
		$this->scheduler = $scheduler;

		$this->panel = new Product_Panel( $this->settings, $this->pricing, $this->products );
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_usdtf_export', array( $this, 'export_csv' ) );

		add_filter(
			'plugin_action_links_' . USDTF_BASENAME,
			array( $this, 'add_action_links' )
		);

		$this->panel->hooks();
	}

	/**
	 * Add a settings link on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function add_action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'usd-to-toman-price-sync-for-woocommerce' ) )
		);

		return $links;
	}

	/**
	 * Register the WooCommerce submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'USD / Toman Pricing', 'usd-to-toman-price-sync-for-woocommerce' ),
			__( 'USD / Toman Pricing', 'usd-to-toman-price-sync-for-woocommerce' ),
			Capabilities::required(),
			self::PAGE,
			array( $this, 'render_page' ),
			56
		);
	}

	/**
	 * Register the settings option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( '\USDTF\Settings', 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);

		// options.php demands manage_options unless the group capability is
		// filtered explicitly. Align saving with the capability that already
		// opens the settings screen, so shop managers are not asked to be admins.
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'settings_capability' ) );
	}

	/**
	 * Capability required to save the settings screen through options.php.
	 *
	 * @return string
	 */
	public function settings_capability() {
		return Capabilities::required();
	}

	/**
	 * Enqueue the admin assets where they are needed.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$screen          = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_plugin       = false !== strpos( (string) $hook_suffix, self::PAGE );
		$is_product      = $screen && 'product' === $screen->post_type;
		$is_product_list = $screen && 'edit-product' === $screen->id;

		if ( ! $is_plugin && ! $is_product && ! $is_product_list ) {
			return;
		}

		wp_enqueue_style(
			'usdtf-admin',
			USDTF_URL . 'assets/css/admin.css',
			array(),
			USDTF_VERSION
		);

		wp_enqueue_script(
			'usdtf-admin',
			USDTF_URL . 'assets/js/admin.js',
			array( 'wp-api-fetch', 'wp-i18n', 'wp-url', 'jquery' ),
			USDTF_VERSION,
			true
		);

		wp_set_script_translations( 'usdtf-admin', 'usd-to-toman-price-sync-for-woocommerce', USDTF_DIR . 'languages' );

		$job_id = isset( $_GET['job'] ) ? (int) $_GET['job'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only display parameter.

		$state = $this->runner->state();

		$state['preview_scope'] = $this->preview_scope();

		wp_localize_script(
			'usdtf-admin',
			'usdtfData',
			array(
				'restNamespace' => Rest_Controller::NAMESPACE_V1,
				'restUrl'       => esc_url_raw( rest_url( Rest_Controller::NAMESPACE_V1 ) ),
				'pageUrl'       => esc_url_raw( admin_url( 'admin.php?page=' . self::PAGE ) ),
				'jobUrl'        => esc_url_raw( admin_url( 'admin.php?page=' . self::PAGE . '&tab=jobs' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'jobId'         => $job_id,
				'state'         => $state,
				'currencyMode'  => (string) $this->settings->get( 'currency_mode' ),
				// JavaScript translation JSON is not guaranteed to be generated or
				// installed with a custom plugin. Ship every dynamic admin string
				// through PHP as well, so the bundled MO catalogue translates the
				// live progress UI in Persian immediately.
				'strings'       => $this->javascript_strings(),
				'labels'        => array(
					'confirmPhrase'   => \USDTF\Rate_Guard::confirmation_phrase(),
					'savedRate'       => __( 'Exchange rate saved.', 'usd-to-toman-price-sync-for-woocommerce' ),
					'jobStarted'      => __( 'The background job was queued.', 'usd-to-toman-price-sync-for-woocommerce' ),
					'previewFinished' => __( 'Dry run finished. Nothing was changed.', 'usd-to-toman-price-sync-for-woocommerce' ),
					'genericError'    => __( 'Something went wrong. Please check the log for details.', 'usd-to-toman-price-sync-for-woocommerce' ),
					'working'         => __( 'Working…', 'usd-to-toman-price-sync-for-woocommerce' ),
					'noChanges'       => __( 'No synchronization required.', 'usd-to-toman-price-sync-for-woocommerce' ),
					'unknownProduct'  => __( 'Unknown product', 'usd-to-toman-price-sync-for-woocommerce' ),
					'currentProduct'  => __( 'Currently processing', 'usd-to-toman-price-sync-for-woocommerce' ),
					'lastProduct'     => __( 'Last product', 'usd-to-toman-price-sync-for-woocommerce' ),
					'rateSuffix'      => __( 'Toman / USD', 'usd-to-toman-price-sync-for-woocommerce' ),
					'alreadyRunning'  => __( 'Another price update is already running. Showing it instead.', 'usd-to-toman-price-sync-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Dynamic strings used by the admin JavaScript.
	 *
	 * WordPress' JavaScript i18n normally needs generated Jed JSON files.
	 * This plugin ships PO/MO catalogues, so translate the same msgids through
	 * PHP and expose them to the script as a lookup table. The literal msgids
	 * remain in admin.js, where the POT generator also discovers them.
	 *
	 * @return array<string,string> English msgid => translated text.
	 */
	private function javascript_strings() {
		$messages = array(
			'A dry run with the same rate, transaction currency, rounding settings and scope is required first.',
			'Another price update is already running. Showing it instead.',
			'Cancel',
			'Cancel this job? Prices that were already written are kept.',
			'Changed',
			'Conflicts',
			'Currently processing',
			'Done.',
			'Dry run finished. Nothing was changed.',
			'Enter a rate first.',
			'Enter a rate to preview.',
			'Exchange rate saved.',
			'Failed',
			'Job #%1$d finished: %2$s',
			'Last product',
			'OK',
			'Pause',
			'Rate %1$s → %2$s (%3$s%%). Managed products that would be affected: about %4$s.',
			'Remove',
			'Restore the previous exchange rate and recalculate all managed products?',
			'Resume',
			'Select at least one product first.',
			'Skipped',
			'Something went wrong. Please check the log for details.',
			'Start the background price update now?',
			'The background job was queued.',
			'The REST route %s was not found on this site. Open the Diagnostics tab to see which routes are missing, then resave the permalink settings or reinstall the plugin.',
			'Toman / USD',
			'Unchanged',
			'Unknown product',
			'Variations',
			'View details',
			'Working…',
		);
		$strings  = array();

		foreach ( $messages as $message ) {
			$strings[ $message ] = translate( $message, 'usd-to-toman-price-sync-for-woocommerce' );
		}

		return $strings;
	}

	/**
	 * The preview scope, reusable for both buttons.
	 *
	 * @return array
	 */
	private function preview_scope() {
		$scope          = Product_Repository::default_scope();
		$scope['label'] = __( 'Every managed product', 'usd-to-toman-price-sync-for-woocommerce' );

		return $scope;
	}

	/**
	 * Render the plugin screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You are not allowed to manage USD/Toman pricing.', 'usd-to-toman-price-sync-for-woocommerce' ), 403 );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only display parameter.

		if ( ! in_array( $tab, array( 'dashboard', 'jobs', 'settings', 'health' ), true ) ) {
			$tab = 'dashboard';
		}

		$page        = self::PAGE;
		$state       = $this->runner->state();
		$rates       = $this->rates;
		$jobs        = $this->jobs;
		$products    = $this->products;
		$health      = $this->health;
		$settings    = $this->settings;
		$logger      = $this->logger;
		$scheduler   = $this->scheduler;
		$history     = $this->rates->history( 15 );
		$current_job = null;

		if ( 'jobs' === $tab ) {
			$job_id = isset( $_GET['job'] ) ? (int) $_GET['job'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only display parameter.

			if ( $job_id ) {
				$current_job = $jobs->get( $job_id );
			}
		}

		/**
		 * Filters the tabs of the plugin screen.
		 *
		 * @param array $usdtf_tabs Tab slug => label.
		 */
		$usdtf_tabs = apply_filters(
			'usdtf_admin_tabs',
			array(
				'dashboard' => __( 'Dashboard', 'usd-to-toman-price-sync-for-woocommerce' ),
				'jobs'      => __( 'Jobs & history', 'usd-to-toman-price-sync-for-woocommerce' ),
				'health'    => __( 'Diagnostics', 'usd-to-toman-price-sync-for-woocommerce' ),
				'settings'  => __( 'Settings', 'usd-to-toman-price-sync-for-woocommerce' ),
			)
		);

		include USDTF_DIR . 'includes/admin/views/page.php';
	}

	/**
	 * Admin notices for states the admin should know about.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! Capabilities::current_user_can() ) {
			return;
		}

		$screen        = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_own_screen = $screen && false !== strpos( (string) $screen->id, self::PAGE );

		if ( $this->settings->currency_mode_is_stale() && ( $on_own_screen || ( $screen && 'product' === $screen->post_type ) ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'USD/Toman pricing:', 'usd-to-toman-price-sync-for-woocommerce' ),
				esc_html__( 'the transaction currency changed after the last full update, so the stored price fields no longer match. Run a full update to rewrite them.', 'usd-to-toman-price-sync-for-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
				esc_html__( 'Run the update', 'usd-to-toman-price-sync-for-woocommerce' )
			);
		}

		if ( $on_own_screen && \USDTF\Scheduler::BACKEND_ACTION_SCHEDULER !== $this->scheduler->backend() ) {
			$health = $this->scheduler->health();

			if ( ! empty( $health['cron_disabled'] ) ) {
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'USD/Toman pricing:', 'usd-to-toman-price-sync-for-woocommerce' ),
					esc_html__( 'WP-Cron is disabled and WooCommerce Action Scheduler was not found. Background updates can only run through loopback requests; a real cron job on wp-cron.php is recommended.', 'usd-to-toman-price-sync-for-woocommerce' )
				);
			}
		}

		$override = get_transient( 'usdtf_manual_override_' . get_current_user_id() );

		if ( $override ) {
			delete_transient( 'usdtf_manual_override_' . get_current_user_id() );

			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'USD/Toman pricing:', 'usd-to-toman-price-sync-for-woocommerce' ),
				esc_html__( 'a manual price change on a managed product was reverted, because in USD mode the price fields are calculated from the Toman source price. Edit the Toman price instead, or switch the product to “Native USD”.', 'usd-to-toman-price-sync-for-woocommerce' )
			);
		}
	}

	/**
	 * Stream a CSV export of a job or of the rate history.
	 *
	 * @return void
	 */
	public function export_csv() {
		if ( ! Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You are not allowed to export this data.', 'usd-to-toman-price-sync-for-woocommerce' ), 403 );
		}

		check_admin_referer( 'usdtf_export' );

		$type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'items';
		$job_id = isset( $_GET['job_id'] ) ? (int) $_GET['job_id'] : 0;

		$filename = 'usdtf-' . $type . '-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			exit;
		}

		// UTF-8 BOM so Excel and Persian text behave.
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming a CSV download.

		if ( 'history' === $type ) {
			fputcsv( $output, array( 'id', 'rate', 'previous_rate', 'change_percent', 'source', 'user', 'job_id', 'status', 'products_checked', 'products_changed', 'products_failed', 'conflicts', 'created_at', 'finished_at', 'note' ) );

			foreach ( $this->rates->history( 500 ) as $row ) {
				fputcsv(
					$output,
					array(
						$row['id'],
						$row['rate'],
						$row['previous_rate'],
						$row['change_percent'],
						$row['source'],
						Job::user_display_name( (int) $row['user_id'] ),
						$row['job_id'],
						$row['status'],
						$row['products_checked'],
						$row['products_changed'],
						$row['products_failed'],
						$row['conflicts'],
						$row['created_at'],
						$row['finished_at'],
						$row['note'],
					)
				);
			}

			fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming a CSV download.
			exit;
		}

		$job = $this->jobs->get( $job_id );

		if ( ! $job ) {
			wp_die( esc_html__( 'The job could not be found.', 'usd-to-toman-price-sync-for-woocommerce' ), 404 );
		}

		fputcsv( $output, array( 'product_id', 'parent_id', 'object_type', 'status', 'toman_regular', 'toman_sale', 'old_price', 'new_price', 'attempts', 'message' ) );

		$page  = 1;
		$found = 0;

		do {
			$items = $this->jobs->items(
				$job->id(),
				array(
					'page'  => $page,
					'limit' => 500,
				)
			);
			$found = count( $items );

			foreach ( $items as $item ) {
				fputcsv(
					$output,
					array(
						$item['object_id'],
						$item['parent_id'],
						$item['object_type'],
						$item['status'],
						$item['toman_regular'],
						$item['toman_sale'],
						$this->csv_price_pair( $item['old_regular'], $item['old_sale'] ),
						$this->csv_price_pair( $item['new_regular'], $item['new_sale'] ),
						$item['attempts'],
						$item['message'],
					)
				);
			}

			++$page;
		} while ( 500 === $found && $page < 40 );

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming a CSV download.
		exit;
	}

	/**
	 * Join a regular/sale pair for CSV output.
	 *
	 * @param mixed $regular Regular price.
	 * @param mixed $sale    Sale price.
	 * @return string
	 */
	private function csv_price_pair( $regular, $sale ) {
		$regular = ( null === $regular || '' === $regular ) ? '' : (string) $regular;
		$sale    = ( null === $sale || '' === $sale ) ? '' : (string) $sale;

		if ( '' === $regular && '' === $sale ) {
			return '';
		}

		return $regular . ( '' === $sale ? '' : ' / ' . $sale );
	}
}
