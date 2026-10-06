<?php
/**
 * Diagnostics and Site Health integration.
 *
 * @package USDTF
 */

namespace USDTF;

use USDTF\Admin\Rest_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the health information shown on the plugin diagnostics screen and in
 * the WordPress Site Health report.
 */
final class Health {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Job storage.
	 *
	 * @var Job_Repository
	 */
	private $jobs;

	/**
	 * Lock.
	 *
	 * @var Lock
	 */
	private $lock;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Rate storage.
	 *
	 * @var Rate_Repository
	 */
	private $rates;

	/**
	 * Product discovery.
	 *
	 * @var Product_Repository
	 */
	private $products;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings  Settings.
	 * @param Scheduler          $scheduler Scheduler.
	 * @param Job_Repository     $jobs      Job storage.
	 * @param Lock               $lock      Lock.
	 * @param Logger             $logger    Logger.
	 * @param Rate_Repository    $rates     Rate storage.
	 * @param Product_Repository $products  Product discovery.
	 */
	public function __construct( Settings $settings, Scheduler $scheduler, Job_Repository $jobs, Lock $lock, Logger $logger, Rate_Repository $rates, Product_Repository $products ) {
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
		$this->jobs      = $jobs;
		$this->lock      = $lock;
		$this->logger    = $logger;
		$this->rates     = $rates;
		$this->products  = $products;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'site_status_tests', array( $this, 'register_site_health_tests' ) );
	}

	/**
	 * Add the plugin's checks to Tools → Site Health.
	 *
	 * @param array $tests Existing tests.
	 * @return array
	 */
	public function register_site_health_tests( $tests ) {
		$tests['direct']['usdtf_health'] = array(
			'label' => __( 'USD/Toman pricing', 'usd-to-toman-price-sync-for-woocommerce' ),
			'test'  => array( $this, 'site_health_test' ),
		);

		return $tests;
	}

	/**
	 * Site Health test callback.
	 *
	 * @return array
	 */
	public function site_health_test() {
		$checks   = $this->checks();
		$problems = array();

		foreach ( $checks as $check ) {
			if ( 'good' !== $check['status'] ) {
				$problems[] = $check;
			}
		}

		if ( ! $problems ) {
			return array(
				'label'       => __( 'USD/Toman price synchronization is healthy', 'usd-to-toman-price-sync-for-woocommerce' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'WooCommerce', 'usd-to-toman-price-sync-for-woocommerce' ),
					'color' => 'blue',
				),
				'description' => '<p>' . esc_html__( 'The exchange rate is saved, the background queue is available and no synchronization job is stuck.', 'usd-to-toman-price-sync-for-woocommerce' ) . '</p>',
				'test'        => 'usdtf_health',
			);
		}

		$messages = array();

		foreach ( $problems as $problem ) {
			$messages[] = sprintf( '<strong>%s</strong>: %s', esc_html( $problem['label'] ), esc_html( $problem['description'] ) );
		}

		$critical = false;

		foreach ( $problems as $problem ) {
			if ( 'critical' === $problem['status'] ) {
				$critical = true;
			}
		}

		return array(
			'label'       => $critical
				? __( 'USD/Toman pricing needs attention', 'usd-to-toman-price-sync-for-woocommerce' )
				: __( 'USD/Toman pricing has warnings', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => $critical ? 'critical' : 'recommended',
			'badge'       => array(
				'label' => __( 'WooCommerce', 'usd-to-toman-price-sync-for-woocommerce' ),
				'color' => 'blue',
			),
			'description' => '<p>' . implode( '</p><p>', $messages ) . '</p>',
			'actions'     => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=usdtf&tab=health' ) ),
				esc_html__( 'Open the USD/Toman diagnostics', 'usd-to-toman-price-sync-for-woocommerce' )
			),
			'test'        => 'usdtf_health',
		);
	}

	/**
	 * Every diagnostic check.
	 *
	 * @return array[]
	 */
	public function checks() {
		$checks = array();

		$rate = $this->rates->get_rate();

		$checks[] = array(
			'id'          => 'rate',
			'label'       => __( 'Exchange rate', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => $rate > 0 ? 'good' : 'critical',
			'value'       => $rate > 0 ? (string) $rate : '',
			'description' => $rate > 0
				? sprintf(
					/* translators: %s: formatted rate. */
					__( 'The manual rate is %s Toman per 1 USD.', 'usd-to-toman-price-sync-for-woocommerce' ),
					Calculator::format_toman( $rate )
				)
				: __( 'No exchange rate is stored yet, so no product price can be calculated.', 'usd-to-toman-price-sync-for-woocommerce' ),
		);

		$pending = $this->rates->get_pending();

		if ( $pending ) {
			$checks[] = array(
				'id'          => 'pending_rate',
				'label'       => __( 'Rate waiting for confirmation', 'usd-to-toman-price-sync-for-woocommerce' ),
				'status'      => 'recommended',
				'value'       => (string) $pending['rate'],
				'description' => sprintf(
					/* translators: 1: pending rate, 2: percentage change. */
					__( 'The rate %1$s was submitted but is a %2$s%% move and waits for explicit confirmation. Until then the active rate stays unchanged.', 'usd-to-toman-price-sync-for-woocommerce' ),
					Calculator::format_toman( (float) $pending['rate'] ),
					number_format_i18n( (float) $pending['change_percent'], 2 )
				),
			);
		}

		$backend = $this->scheduler->backend();

		$checks[] = array(
			'id'          => 'scheduler',
			'label'       => __( 'Background queue', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => Scheduler::BACKEND_NONE === $backend ? 'critical' : ( Scheduler::BACKEND_ACTION_SCHEDULER === $backend ? 'good' : 'recommended' ),
			'value'       => $backend,
			'description' => $this->backend_description( $backend ),
		);

		$queue = $this->scheduler->health();

		$checks[] = array(
			'id'          => 'queue_depth',
			'label'       => __( 'Pending worker actions', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => $queue['pending'] > 50 ? 'recommended' : 'good',
			'value'       => (string) $queue['pending'],
			'description' => sprintf(
				/* translators: 1: pending actions, 2: failed actions. */
				__( '%1$d worker actions are queued and %2$d failed. A long queue can mean that WP-Cron is not running.', 'usd-to-toman-price-sync-for-woocommerce' ),
				(int) $queue['pending'],
				(int) $queue['failed']
			),
		);

		if ( ! empty( $queue['cron_disabled'] ) ) {
			$checks[] = array(
				'id'          => 'cron',
				'label'       => __( 'WP-Cron is disabled', 'usd-to-toman-price-sync-for-woocommerce' ),
				'status'      => Scheduler::BACKEND_ACTION_SCHEDULER === $backend ? 'good' : 'critical',
				'value'       => '',
				'description' => Scheduler::BACKEND_ACTION_SCHEDULER === $backend
					? __( 'DISABLE_WP_CRON is set, but Action Scheduler takes care of the queue through its own runner.', 'usd-to-toman-price-sync-for-woocommerce' )
					: __( 'DISABLE_WP_CRON is set and Action Scheduler is unavailable. Add a real cron job that requests wp-cron.php, or enable the loopback fallback.', 'usd-to-toman-price-sync-for-woocommerce' ),
			);
		}

		$lock = $this->lock->status();

		$checks[] = array(
			'id'          => 'lock',
			'label'       => __( 'Job lock', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => 'good',
			'value'       => $lock['active'] ? (string) $lock['job_id'] : '',
			'description' => $lock['active']
				? sprintf(
					/* translators: 1: job ID, 2: age in seconds. */
					__( 'Job #%1$d holds the synchronization lock (heartbeat %2$d seconds ago).', 'usd-to-toman-price-sync-for-woocommerce' ),
					(int) $lock['job_id'],
					(int) $lock['age']
				)
				: __( 'No synchronization job holds the lock.', 'usd-to-toman-price-sync-for-woocommerce' ),
		);

		$missing_routes = $this->missing_rest_routes();

		$checks[] = array(
			'id'          => 'rest_routes',
			'label'       => __( 'REST API routes', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => $missing_routes ? 'critical' : 'good',
			'value'       => $missing_routes ? '' : Rest_Controller::NAMESPACE_V1,
			'description' => $missing_routes
				? sprintf(
					/* translators: %s: list of missing routes. */
					__( 'The admin screen cannot reach these routes: %s. Every admin action would fail with "no route found". Reinstall the plugin or resave the permalink settings, then reload this screen.', 'usd-to-toman-price-sync-for-woocommerce' ),
					implode( ', ', $missing_routes )
				)
				: sprintf(
					/* translators: %s: REST namespace. */
					__( 'Every admin route is registered under %s.', 'usd-to-toman-price-sync-for-woocommerce' ),
					Rest_Controller::NAMESPACE_V1
				),
		);

		$last_error = $this->logger->last_error();

		$checks[] = array(
			'id'          => 'last_error',
			'label'       => __( 'Last job error', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => $last_error ? 'recommended' : 'good',
			'value'       => $last_error ? (string) $last_error['created_at'] : '',
			'description' => $last_error
				? (string) $last_error['message']
				: __( 'No errors have been logged.', 'usd-to-toman-price-sync-for-woocommerce' ),
		);

		$stale = $this->stale_jobs();

		$checks[] = array(
			'id'          => 'stale_jobs',
			'label'       => __( 'Interrupted jobs', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => $stale ? 'recommended' : 'good',
			'value'       => (string) count( $stale ),
			'description' => $stale
				? __( 'Some jobs lost their background worker. Open the jobs screen and resume them; progress is not lost.', 'usd-to-toman-price-sync-for-woocommerce' )
				: __( 'Every job either finished or is progressing normally.', 'usd-to-toman-price-sync-for-woocommerce' ),
		);

		$batch = $this->settings->batch_size();

		$checks[] = array(
			'id'          => 'batch_size',
			'label'       => __( 'Batch size', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => $batch > 25 ? 'recommended' : 'good',
			'value'       => (string) $batch,
			'description' => sprintf(
				/* translators: 1: batch size, 2: time budget, 3: PHP execution limit. */
				__( '%1$d products per worker run, %2$d seconds budget, PHP max_execution_time: %3$s.', 'usd-to-toman-price-sync-for-woocommerce' ),
				$batch,
				(int) $this->settings->time_budget(),
				0 === (int) ini_get( 'max_execution_time' ) ? __( 'unlimited', 'usd-to-toman-price-sync-for-woocommerce' ) : (string) ini_get( 'max_execution_time' )
			),
		);

		$checks[] = array(
			'id'          => 'memory',
			'label'       => __( 'Memory limit', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) < 128 * MB_IN_BYTES && -1 !== (int) ini_get( 'memory_limit' ) ? 'recommended' : 'good',
			'value'       => (string) ini_get( 'memory_limit' ),
			'description' => __( 'The worker processes small batches, so 128 MB or more keeps variable products with many variations comfortable.', 'usd-to-toman-price-sync-for-woocommerce' ),
		);

		$checks[] = array(
			'id'          => 'currency_mode',
			'label'       => __( 'Transaction currency', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => 'good',
			'value'       => (string) $this->settings->get( 'currency_mode' ),
			'description' => $this->settings->is_toman_mode()
				? __( 'Mode B: WooCommerce transacts in Toman and the derived USD price is kept as an internal reference.', 'usd-to-toman-price-sync-for-woocommerce' )
				: __( 'Mode A: WooCommerce transacts in USD and the storefront displays the canonical Toman price.', 'usd-to-toman-price-sync-for-woocommerce' ),
		);

		if ( $this->settings->currency_mode_is_stale() ) {
			$checks[] = array(
				'id'          => 'currency_mode_stale',
				'label'       => __( 'Currency mode change pending', 'usd-to-toman-price-sync-for-woocommerce' ),
				'status'      => 'critical',
				'value'       => '',
				'description' => __( 'The transaction currency was switched after the last full synchronization. Run a full update so every price field holds the right currency.', 'usd-to-toman-price-sync-for-woocommerce' ),
			);
		}

		$summary = $this->products->summary( $rate );

		$checks[] = array(
			'id'          => 'inventory',
			'label'       => __( 'Products', 'usd-to-toman-price-sync-for-woocommerce' ),
			'status'      => 'good',
			'value'       => (string) $summary['managed'],
			'description' => sprintf(
				/* translators: 1: managed products, 2: managed variations, 3: native products, 4: excluded products, 5: products without a mode. */
				__( '%1$d managed products and %2$d managed variations. %3$d products keep a native USD price, %4$d are excluded and %5$d have no explicit mode yet.', 'usd-to-toman-price-sync-for-woocommerce' ),
				(int) $summary['managed'],
				(int) $summary['managed_variations'],
				(int) $summary['native'],
				(int) $summary['excluded'],
				(int) $summary['unset']
			),
		);

		$conflict_plugin = $this->detect_currency_plugin();

		if ( $conflict_plugin ) {
			$checks[] = array(
				'id'          => 'currency_conflict',
				'label'       => __( 'Another currency plugin is active', 'usd-to-toman-price-sync-for-woocommerce' ),
				'status'      => 'recommended',
				'value'       => $conflict_plugin,
				'description' => __( 'Multiple plugins that change the WooCommerce currency can fight over the same hooks. This plugin should be the only currency switcher with Toman mode enabled.', 'usd-to-toman-price-sync-for-woocommerce' ),
			);
		}

		/**
		 * Filters the diagnostics checks.
		 *
		 * @param array[] $checks Checks.
		 */
		return apply_filters( 'usdtf_health_checks', $checks );
	}

	/**
	 * Admin REST routes that are not registered on the live REST server.
	 *
	 * A missing route is what turns every admin action into the REST
	 * "rest_no_route" error (for example after a broken upgrade left stale
	 * files behind), so the diagnostics reports it instead of leaving the
	 * admin to guess.
	 *
	 * @return string[] Method + path pairs that are missing.
	 */
	public function missing_rest_routes() {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return array();
		}

		$expected = array(
			'/state'       => 'GET',
			'/rate'        => 'POST',
			'/preview'     => 'POST',
			'/update'      => 'POST',
			'/rollback'    => 'POST',
			'/recalculate' => 'POST',
			'/jobs'                       => 'GET',
			'/jobs/(?P<id>\\d+)/status' => 'GET',
			'/health'                     => 'GET',
			'/products'    => 'GET',
		);

		$routes = rest_get_server()->get_routes();

		$missing = array();

		foreach ( $expected as $path => $method ) {
			$route = '/' . Rest_Controller::NAMESPACE_V1 . $path;

			if ( empty( $routes[ $route ] ) ) {
				$missing[] = $method . ' ' . $route;
				continue;
			}

			$methods = array();

			foreach ( (array) $routes[ $route ] as $handler ) {
				if ( empty( $handler['methods'] ) ) {
					continue;
				}

				// The REST server normalizes the methods into
				// array( 'GET' => true ); older shapes keep them as a plain
				// list of names or a bare string.
				foreach ( (array) $handler['methods'] as $name => $enabled ) {
					$methods[] = is_int( $name ) ? strval( $enabled ) : strval( $name );
				}
			}

			if ( ! in_array( $method, $methods, true ) ) {
				$missing[] = $method . ' ' . $route;
			}
		}

		return $missing;
	}

	/**
	 * Jobs that lost their worker.
	 *
	 * @return Job[]
	 */
	public function stale_jobs() {
		$stale = array();

		foreach ( $this->jobs->query( array( 'limit' => 20 ) ) as $job ) {
			if ( $job->is_stale() ) {
				$stale[] = $job;
			}
		}

		return $stale;
	}

	/**
	 * Description for a scheduling backend.
	 *
	 * @param string $backend Backend key.
	 * @return string
	 */
	private function backend_description( $backend ) {
		switch ( $backend ) {
			case Scheduler::BACKEND_ACTION_SCHEDULER:
				return __( 'WooCommerce Action Scheduler is available. Big updates run in the background queue and do not need an open browser tab.', 'usd-to-toman-price-sync-for-woocommerce' );
			case Scheduler::BACKEND_WP_CRON:
				return __( 'Action Scheduler is missing, so WP-Cron is used. WP-Cron needs site traffic or a real cron job on wp-cron.php.', 'usd-to-toman-price-sync-for-woocommerce' );
			case Scheduler::BACKEND_LOOPBACK:
				return __( 'WP-Cron is disabled and Action Scheduler is missing, so worker runs are triggered by non blocking loopback requests. A real cron job is strongly recommended.', 'usd-to-toman-price-sync-for-woocommerce' );
			case Scheduler::BACKEND_NONE:
			default:
				return __( 'No background runner is available. Enable the loopback fallback or restore Action Scheduler / WP-Cron.', 'usd-to-toman-price-sync-for-woocommerce' );
		}
	}

	/**
	 * Detect another plugin that hijacks the WooCommerce currency.
	 *
	 * @return string Plugin name or empty string.
	 */
	private function detect_currency_plugin() {
		$candidates = array(
			'WOOCS'               => 'WooCommerce Currency Switcher',
			'WCML_Multi_Currency' => 'WPML Multi Currency',
			'Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher' => 'Aelia Currency Switcher',
			'WC_CS_Currencies'    => 'WooCommerce Currency Switcher',
		);

		foreach ( $candidates as $class => $label ) {
			if ( class_exists( $class ) ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Run a loopback test to see whether the site can trigger its own worker.
	 *
	 * @return array
	 */
	public function loopback_test() {
		$url = add_query_arg(
			array(
				'usdtf_loopback_check' => 1,
				'token'                => Scheduler::token(),
			),
			home_url( '/' )
		);

		$started = microtime( true );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 2,
			)
		);

		$duration = round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'          => false,
				'status'      => 0,
				'duration'    => $duration,
				'description' => $response->get_error_message(),
			);
		}

		return array(
			'ok'          => 200 === (int) wp_remote_retrieve_response_code( $response ),
			'status'      => (int) wp_remote_retrieve_response_code( $response ),
			'duration'    => $duration,
			'description' => __( 'The site answered its own request. Background workers can be triggered.', 'usd-to-toman-price-sync-for-woocommerce' ),
		);
	}

	/**
	 * Snapshot stored by the daily cron for the dashboard widget.
	 *
	 * @return array
	 */
	public function snapshot() {
		return array(
			'checks' => $this->checks(),
			'time'   => time(),
		);
	}
}
