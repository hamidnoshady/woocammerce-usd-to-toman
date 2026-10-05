<?php
/**
 * Plugin container.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together and exposes the shared services.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Lazily created services.
	 *
	 * @var array
	 */
	private $services = array();

	/**
	 * Whether the modules are hooked.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Instance accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Register the early hooks.
	 *
	 * @return void
	 */
	private function boot() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'plugins_loaded', array( $this, 'init_modules' ), 20 );
	}

	/**
	 * Load the bundled translations.
	 *
	 * The plugin ships its own .mo catalogues (Persian today) next to the .pot
	 * template, so a store does not have to wait for a language pack. Files
	 * installed in wp-content/languages/plugins take precedence, exactly as
	 * WordPress documents it.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( USDTF_SLUG, false, dirname( USDTF_BASENAME ) . '/languages' );
	}

	/**
	 * Declare compatibility with WooCommerce features.
	 *
	 * @return void
	 */
	public function declare_compatibility() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', USDTF_FILE, true );
	}

	/**
	 * Initialize the plugin modules once every plugin is loaded.
	 *
	 * @return void
	 */
	public function init_modules() {
		if ( $this->initialized ) {
			return;
		}

		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_missing_notice' ) );

			return;
		}

		$this->initialized = true;

		Installer::maybe_upgrade();
		Cron::schedule();

		$this->pricing()->hooks();
		$this->runner()->hooks();
		$this->cron()->hooks();
		$this->health()->hooks();
		// Always, because a REST request is neither an admin nor a front-end
		// screen and the admin UI talks to the site over the REST API.
		$this->rest()->hooks();

		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->admin()->hooks();
		}

		$this->display()->hooks();
		$this->currency()->hooks();

		/**
		 * Fires once the plugin finished loading its modules.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'usdtf_loaded', $this );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Admin notice shown when WooCommerce is missing.
	 *
	 * @return void
	 */
	public function render_woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'USD to Toman Price Sync for WooCommerce needs WooCommerce to be installed and active.', 'usd-to-toman-price-sync-for-woocommerce' )
		);
	}

	/**
	 * Settings service.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->service( 'settings' );
	}

	/**
	 * Logger service.
	 *
	 * @return Logger
	 */
	public function logger() {
		return $this->service( 'logger' );
	}

	/**
	 * Scheduler service.
	 *
	 * @return Scheduler
	 */
	public function scheduler() {
		return $this->service( 'scheduler' );
	}

	/**
	 * Lock service.
	 *
	 * @return Lock
	 */
	public function lock() {
		return $this->service( 'lock' );
	}

	/**
	 * Job storage.
	 *
	 * @return Job_Repository
	 */
	public function jobs() {
		return $this->service( 'jobs' );
	}

	/**
	 * Rate storage.
	 *
	 * @return Rate_Repository
	 */
	public function rates() {
		return $this->service( 'rates' );
	}

	/**
	 * Pricing model.
	 *
	 * @return Product_Pricing
	 */
	public function pricing() {
		return $this->service( 'pricing' );
	}

	/**
	 * Product discovery.
	 *
	 * @return Product_Repository
	 */
	public function products() {
		return $this->service( 'products' );
	}

	/**
	 * Price writer.
	 *
	 * @return Recorder
	 */
	public function recorder() {
		return $this->service( 'recorder' );
	}

	/**
	 * Synchronization engine.
	 *
	 * @return Sync_Runner
	 */
	public function runner() {
		return $this->service( 'runner' );
	}

	/**
	 * Diagnostics.
	 *
	 * @return Health
	 */
	public function health() {
		return $this->service( 'health' );
	}

	/**
	 * Cron maintenance.
	 *
	 * @return Cron
	 */
	public function cron() {
		return $this->service( 'cron' );
	}

	/**
	 * Admin screens.
	 *
	 * @return \USDTF\Admin\Admin
	 */
	public function admin() {
		return $this->service( 'admin' );
	}

	/**
	 * Storefront display.
	 *
	 * @return \USDTF\Frontend\Display
	 */
	public function display() {
		return $this->service( 'display' );
	}

	/**
	 * Transaction currency mode.
	 *
	 * @return \USDTF\Frontend\Currency
	 */
	public function currency() {
		return $this->service( 'currency' );
	}

	/**
	 * REST API controller.
	 *
	 * @return \USDTF\Admin\Rest_Controller
	 */
	public function rest() {
		return $this->service( 'rest' );
	}

	/**
	 * Create a service on first use.
	 *
	 * @param string $name Service name.
	 * @return mixed
	 */
	private function service( $name ) {
		if ( isset( $this->services[ $name ] ) ) {
			return $this->services[ $name ];
		}

		switch ( $name ) {
			case 'settings':
				$service = new Settings();
				break;

			case 'logger':
				$service = new Logger();
				break;

			case 'scheduler':
				$service = new Scheduler( $this->settings() );
				break;

			case 'lock':
				$service = new Lock( wp_generate_password( 20, false, false ) );
				break;

			case 'jobs':
				$service = new Job_Repository();
				break;

			case 'pricing':
				$service = new Product_Pricing( $this->settings() );
				break;

			case 'products':
				$service = new Product_Repository( $this->settings(), $this->pricing() );
				break;

			case 'rates':
				$service = new Rate_Repository( $this->settings(), $this->logger() );
				break;

			case 'recorder':
				$service = new Recorder( $this->settings(), $this->pricing() );
				break;

			case 'runner':
				$service = new Sync_Runner(
					$this->settings(),
					$this->jobs(),
					$this->products(),
					$this->pricing(),
					$this->recorder(),
					$this->rates(),
					$this->logger(),
					$this->scheduler(),
					$this->lock()
				);
				break;

			case 'health':
				$service = new Health( $this->settings(), $this->scheduler(), $this->jobs(), $this->lock(), $this->logger(), $this->rates(), $this->products() );
				break;

			case 'cron':
				$service = new Cron();
				break;

			case 'admin':
				$service = new \USDTF\Admin\Admin( $this->settings(), $this->runner(), $this->rates(), $this->jobs(), $this->products(), $this->pricing(), $this->health(), $this->logger(), $this->scheduler() );
				break;

			case 'display':
				$service = new \USDTF\Frontend\Display( $this->settings(), $this->pricing() );
				break;

			case 'currency':
				$service = new \USDTF\Frontend\Currency( $this->settings() );
				break;

			case 'rest':
				$service = new \USDTF\Admin\Rest_Controller( $this->scheduler(), $this->rates(), $this->runner(), $this->jobs(), $this->products(), $this->health(), $this->pricing(), $this->settings() );
				break;

			default:
				$service = null;
				break;
		}

		$this->services[ $name ] = $service;

		return $service;
	}
}
