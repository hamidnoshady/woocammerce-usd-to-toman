<?php
/**
 * Admin screens.
 *
 * @package USDTF
 */

namespace USDTF\Admin;

use USDTF\Calculator;
use USDTF\Capabilities;
use USDTF\Database;
use USDTF\Health;
use USDTF\Job;
use USDTF\Job_Repository;
use USDTF\Logger;
use USDTF\Product_Pricing;
use USDTF\Product_Repository;
use USDTF\Rate_Repository;
use USDTF\Scheduler;
use USDTF\Settings;
use USDTF\Store_Currency;
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
	 * Action of the admin-post request and its nonce that dismiss the upgrade notice.
	 */
	const DISMISS_UPGRADE_NOTICE = 'usdtf_dismiss_upgrade_notice';

	/**
	 * Header of the job items CSV export.
	 */
	const ITEMS_CSV_HEADER = array( 'product_id', 'parent_id', 'object_type', 'status', 'usd_regular', 'usd_sale', 'old_price', 'new_price', 'attempts', 'message' );

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
		add_action( 'admin_post_' . self::DISMISS_UPGRADE_NOTICE, array( $this, 'dismiss_upgrade_notice' ) );

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

		// The script never reads the product counts, and this runs on every
		// product list and product edit screen.
		$state = $this->state( false );

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
					'rateSuffix'      => self::rate_label(),
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
			'A dry run with the same rate, rounding settings and scope is required first.',
			'Another price update is already running. Showing it instead.',
			'Attempts: %d',
			'Cancel',
			'Cancel this job? Prices that were already written are kept.',
			'Conflict',
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
			'No items match this filter.',
			'OK',
			'Pause',
			'Product #%d',
			'Rate %1$s → %2$s (%3$s%%). Managed products that would be affected: about %4$s.',
			'Re-based',
			'Remove',
			'Restore the previous exchange rate and recalculate all managed products?',
			'Resume',
			'Select at least one product first.',
			'Select at least one term first.',
			'Set the store currency to Iranian Toman or Rial',
			'Skipped',
			'Something went wrong. Please check the log for details.',
			'Start the background price update now?',
			'The background job was queued.',
			'The REST route %s was not found on this site. Open the Diagnostics tab to see which routes are missing, then resave the permalink settings or reinstall the plugin.',
			'Unchanged',
			'Unknown product',
			'Updated',
			'USD set',
			'variation',
			'Variations',
			'Variations processed: %1$d / %2$d',
			'View details',
			'Working…',
		);
		$strings  = array();

		foreach ( $messages as $message ) {
			// The literal msgids live in admin.js and are extracted into the POT.
			// Translating the same runtime key here lets the bundled MO catalogue
			// drive JavaScript without requiring separately generated Jed JSON.
			$strings[ $message ] = __( $message, 'usd-to-toman-price-sync-for-woocommerce' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
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
	 * The engine state plus the store currency, as the screens and the script use it.
	 *
	 * @param bool $with_summary Whether to include the product counts.
	 * @return array
	 */
	private function state( $with_summary = true ) {
		$state = $this->runner->state( $with_summary );

		$state['store_currency'] = self::store_currency();

		return $state;
	}

	/**
	 * The store currency as the REST state and the screens describe it.
	 *
	 * @return array{code:string, unit:string, label:string, supported:bool}
	 */
	public static function store_currency() {
		return array(
			'code'      => Store_Currency::code(),
			'unit'      => Store_Currency::unit(),
			'label'     => Store_Currency::unit_label(),
			'supported' => Store_Currency::is_supported(),
		);
	}

	/**
	 * Name of the store currency with its code, such as "Toman (IRT)".
	 *
	 * @return string The bare currency code when the currency is not supported.
	 */
	public static function currency_label() {
		if ( ! Store_Currency::is_supported() ) {
			return Store_Currency::code();
		}

		return sprintf(
			/* translators: 1: unit name (Toman or Rial), 2: WooCommerce currency code (IRT or IRR). */
			__( '%1$s (%2$s)', 'usd-to-toman-price-sync-for-woocommerce' ),
			Store_Currency::unit_label(),
			Store_Currency::code()
		);
	}

	/**
	 * Unit of the exchange rate: "Toman per 1 USD" or "Rial per 1 USD".
	 *
	 * @return string
	 */
	public static function rate_label() {
		if ( Store_Currency::UNIT_RIAL === Store_Currency::unit() ) {
			return __( 'Rial per 1 USD', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		return __( 'Toman per 1 USD', 'usd-to-toman-price-sync-for-woocommerce' );
	}

	/**
	 * Filter tabs of a job's items: item status => label.
	 *
	 * @return array<string,string>
	 */
	public static function item_status_tabs() {
		return array(
			Job_Repository::ITEM_CHANGED   => __( 'Updated', 'usd-to-toman-price-sync-for-woocommerce' ),
			Job_Repository::ITEM_GENERATED => __( 'USD set', 'usd-to-toman-price-sync-for-woocommerce' ),
			Job_Repository::ITEM_REBASED   => __( 'Re-based', 'usd-to-toman-price-sync-for-woocommerce' ),
			Job_Repository::ITEM_UNCHANGED => __( 'Unchanged', 'usd-to-toman-price-sync-for-woocommerce' ),
			Job_Repository::ITEM_SKIPPED   => __( 'Skipped', 'usd-to-toman-price-sync-for-woocommerce' ),
			Job_Repository::ITEM_FAILED    => __( 'Failed', 'usd-to-toman-price-sync-for-woocommerce' ),
			Job_Repository::ITEM_CONFLICT  => __( 'Conflicts', 'usd-to-toman-price-sync-for-woocommerce' ),
		);
	}

	/**
	 * Job counters shown in the progress cards: counter key => label.
	 *
	 * @return array<string,string>
	 */
	public static function counter_labels() {
		return array(
			'changed'   => __( 'Updated', 'usd-to-toman-price-sync-for-woocommerce' ),
			'generated' => __( 'USD set', 'usd-to-toman-price-sync-for-woocommerce' ),
			'rebased'   => __( 'Re-based', 'usd-to-toman-price-sync-for-woocommerce' ),
			'unchanged' => __( 'Unchanged', 'usd-to-toman-price-sync-for-woocommerce' ),
			'skipped'   => __( 'Skipped', 'usd-to-toman-price-sync-for-woocommerce' ),
			'failed'    => __( 'Failed', 'usd-to-toman-price-sync-for-woocommerce' ),
			'conflicts' => __( 'Conflicts', 'usd-to-toman-price-sync-for-woocommerce' ),
		);
	}

	/**
	 * Label of one item status, as shown in a row of the items table.
	 *
	 * @param string $status Item status.
	 * @return string
	 */
	public static function item_status_label( $status ) {
		if ( Job_Repository::ITEM_CONFLICT === $status ) {
			return __( 'Conflict', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		$tabs = self::item_status_tabs();

		return isset( $tabs[ $status ] ) ? $tabs[ $status ] : (string) $status;
	}

	/**
	 * A regular price and its sale price as display text, "regular / sale".
	 *
	 * @param mixed  $regular  Regular price, null or empty for none.
	 * @param mixed  $sale     Sale price, null or empty for none.
	 * @param int    $decimals Decimal places.
	 * @param string $prefix   Text put before each amount, such as "$".
	 * @return string An em dash when there is no price at all.
	 */
	public static function format_price_pair( $regular, $sale, $decimals, $prefix = '' ) {
		$parts = array();

		foreach ( array( $regular, $sale ) as $amount ) {
			if ( null !== $amount && '' !== $amount ) {
				$parts[] = $prefix . Calculator::format_price( $amount, $decimals );
			}
		}

		return $parts ? implode( ' / ', $parts ) : '—';
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
		$state       = $this->state();
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

		$this->render_upgrade_notice();

		$screen        = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_own_screen = $screen && false !== strpos( (string) $screen->id, self::PAGE );

		// The plugin prices in the WooCommerce currency and refuses every job
		// unless that is Toman or Rial: say so where its screens are used.
		if ( ! Store_Currency::is_supported() && ( $on_own_screen || ( $screen && 'product' === $screen->post_type ) ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'USD/Toman pricing:', 'usd-to-toman-price-sync-for-woocommerce' ),
				esc_html__( 'Set the store currency to Iranian Toman or Rial', 'usd-to-toman-price-sync-for-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=wc-settings' ) ),
				esc_html__( 'Open the WooCommerce currency settings', 'usd-to-toman-price-sync-for-woocommerce' )
			);
		}

		// A rate saved in the other unit refuses every job until it is saved again.
		$unit_changed = $this->runner->unit_changed_error();

		if ( $unit_changed && ( $on_own_screen || ( $screen && 'product' === $screen->post_type ) ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'USD/Toman pricing:', 'usd-to-toman-price-sync-for-woocommerce' ),
				esc_html( $unit_changed->get_error_message() ),
				esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
				esc_html__( 'Save the exchange rate', 'usd-to-toman-price-sync-for-woocommerce' )
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
	}

	/**
	 * The one-time notice the 1.2.0 upgrade left, until it is dismissed.
	 *
	 * Shown on every admin screen to the users who run the plugin. The caller
	 * has checked the capability.
	 *
	 * @return void
	 */
	private function render_upgrade_notice() {
		$kind = (string) get_option( Database::OPTION_UPGRADE_NOTICE, '' );

		switch ( $kind ) {
			case 'toman':
			case 'toman_review':
				$class   = 'notice-warning';
				$message = __( 'The store currency was set to Iranian Toman (IRT, no decimals) to keep the storefront as it was. Earlier versions applied this only while the plugin was active; it is now a normal WooCommerce setting.', 'usd-to-toman-price-sync-for-woocommerce' );

				if ( 'toman_review' === $kind ) {
					$message .= ' ' . __( 'Your prices were last written for the removed USD-store mode, so review them before you run an update. The Toman prices are kept in hidden product meta (_usdtf_source_regular and _usdtf_source_sale).', 'usd-to-toman-price-sync-for-woocommerce' );
				}
				break;

			case 'usd':
				$class   = 'notice-error';
				$message = __( 'The USD-store mode was removed. Set the WooCommerce store currency to Iranian Toman or Rial and review your prices; the plugin does not update prices until then. The old Toman prices are kept in hidden product meta (_usdtf_source_regular and _usdtf_source_sale).', 'usd-to-toman-price-sync-for-woocommerce' );
				break;

			default:
				return;
		}

		printf(
			'<div class="notice %1$s"><p><strong>%2$s</strong> %3$s</p><p><a href="%4$s">%5$s</a> | <a href="%6$s">%7$s</a></p></div>',
			esc_attr( $class ),
			esc_html__( 'USD/Toman pricing:', 'usd-to-toman-price-sync-for-woocommerce' ),
			esc_html( $message ),
			esc_url( admin_url( 'admin.php?page=wc-settings' ) ),
			esc_html__( 'Open the WooCommerce currency settings', 'usd-to-toman-price-sync-for-woocommerce' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_UPGRADE_NOTICE ), self::DISMISS_UPGRADE_NOTICE ) ),
			esc_html__( 'Dismiss this notice', 'usd-to-toman-price-sync-for-woocommerce' )
		);
	}

	/**
	 * Dismiss the upgrade notice for good, then go back where the user was.
	 *
	 * @return void
	 */
	public function dismiss_upgrade_notice() {
		if ( ! Capabilities::current_user_can() ) {
			wp_die( esc_html__( 'You are not allowed to manage USD/Toman pricing.', 'usd-to-toman-price-sync-for-woocommerce' ), 403 );
		}

		check_admin_referer( self::DISMISS_UPGRADE_NOTICE );

		delete_option( Database::OPTION_UPGRADE_NOTICE );

		$back = wp_get_referer();

		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
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

		$this->write_items_csv( $output, $job );

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming a CSV download.
		exit;
	}

	/**
	 * Write the items of a job as CSV rows, header first.
	 *
	 * @param resource $output Writable stream.
	 * @param Job      $job    Job.
	 * @return void
	 */
	public function write_items_csv( $output, Job $job ) {
		fputcsv( $output, self::ITEMS_CSV_HEADER );

		$page = 1;

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
						$item['usd_regular'],
						$item['usd_sale'],
						$this->csv_price_pair( $item['old_regular'], $item['old_sale'] ),
						$this->csv_price_pair( $item['new_regular'], $item['new_sale'] ),
						$item['attempts'],
						$item['message'],
					)
				);
			}

			++$page;
		} while ( 500 === $found );
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
