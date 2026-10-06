<?php
/**
 * The background synchronization engine.
 *
 * Jobs are executed in small queued slices: a run discovers a page of products,
 * the next run processes a batch, and a variable product is walked through its
 * variations in slices. Every step persists its cursor, so a browser tab is
 * never required and an interrupted job resumes where it stopped.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, runs and controls synchronization jobs.
 */
final class Sync_Runner {

	/**
	 * Action that discovers the next page of products.
	 */
	const HOOK_DISCOVER = 'usdtf_run_discovery';

	/**
	 * Action that processes the next batch of items.
	 */
	const HOOK_PROCESS = 'usdtf_run_batch';

	/**
	 * Action that recalculates variable parents.
	 */
	const HOOK_FINALIZE = 'usdtf_run_finalize';

	/**
	 * Variations processed per slice for one variable product.
	 */
	const VARIATION_SLICE = 20;

	/**
	 * Parents recalculated per finalize run.
	 */
	const PARENT_SLICE = 20;

	/**
	 * Automatic resumes of a job that keeps losing its worker.
	 */
	const MAX_AUTO_RESUMES = 3;

	/**
	 * Result codes that need an administrator's attention: the product data is
	 * invalid, so the job is reported as completed with errors.
	 *
	 * @var string[]
	 */
	const ATTENTION_CODES = array( 'invalid_source', 'empty_target', 'sale_above_regular' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

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
	 * Price writer.
	 *
	 * @var Recorder
	 */
	private $recorder;

	/**
	 * Rate storage.
	 *
	 * @var Rate_Repository
	 */
	private $rates;

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
	 * Job lock.
	 *
	 * @var Lock
	 */
	private $lock;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings  Settings.
	 * @param Job_Repository     $jobs      Job storage.
	 * @param Product_Repository $products  Product discovery.
	 * @param Product_Pricing    $pricing   Pricing model.
	 * @param Recorder           $recorder  Price writer.
	 * @param Rate_Repository    $rates     Rate storage.
	 * @param Logger             $logger    Logger.
	 * @param Scheduler          $scheduler Scheduler.
	 * @param Lock               $lock      Job lock.
	 */
	public function __construct( Settings $settings, Job_Repository $jobs, Product_Repository $products, Product_Pricing $pricing, Recorder $recorder, Rate_Repository $rates, Logger $logger, Scheduler $scheduler, Lock $lock ) {
		$this->settings  = $settings;
		$this->jobs      = $jobs;
		$this->products  = $products;
		$this->pricing   = $pricing;
		$this->recorder  = $recorder;
		$this->rates     = $rates;
		$this->logger    = $logger;
		$this->scheduler = $scheduler;
		$this->lock      = $lock;
	}

	/**
	 * Register worker hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( self::HOOK_DISCOVER, array( $this, 'handle_discovery' ) );
		add_action( self::HOOK_PROCESS, array( $this, 'handle_batch' ) );
		add_action( self::HOOK_FINALIZE, array( $this, 'handle_finalize' ) );
		add_action( 'wp_ajax_' . Scheduler::LOOPBACK_ACTION, array( $this->scheduler, 'handle_loopback' ) );
		add_action( 'wp_ajax_nopriv_' . Scheduler::LOOPBACK_ACTION, array( $this->scheduler, 'handle_loopback' ) );
	}

	/**
	 * Create a job and queue its first step.
	 *
	 * @param array $args {
	 *     Job arguments.
	 *
	 *     @type string $type          Job type. Default sync.
	 *     @type array  $scope         Scope. Default full managed scope.
	 *     @type float  $rate          Rate to apply. Defaults to the active rate.
	 *     @type float  $previous_rate Previous rate, for rollbacks.
	 *     @type string $note          Extra note stored in the log.
	 *     @type int    $preview       Preview job ID presented as the completed
	 *                                 dry run, when preview enforcement is on.
	 * }
	 * @return array|\WP_Error Job data or an error, including the blocking job.
	 */
	public function create_job( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'          => Job::TYPE_SYNC,
				'scope'         => array(),
				'rate'          => 0,
				'previous_rate' => null,
				'note'          => '',
				'preview'       => 0,
			)
		);

		$type = in_array( $args['type'], array( Job::TYPE_SYNC, Job::TYPE_PREVIEW, Job::TYPE_ROLLBACK, Job::TYPE_RECALCULATE ), true ) ? $args['type'] : Job::TYPE_SYNC;

		$rate = (float) $args['rate'];

		if ( $rate <= 0 ) {
			$rate = $this->rates->get_rate();
		}

		if ( $rate <= 0 ) {
			return new \WP_Error(
				'usdtf_missing_rate',
				__( 'Save a USD/Toman exchange rate before updating prices.', 'usd-to-toman-price-sync-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		if ( Job::TYPE_PREVIEW !== $type ) {
			$active = $this->jobs->active_write_job();

			if ( $active ) {
				return new \WP_Error(
					'usdtf_job_running',
					__( 'Another price update is already running. Only one update can run at a time.', 'usd-to-toman-price-sync-for-woocommerce' ),
					array(
						'status' => 409,
						'job'    => $active->to_array(),
					)
				);
			}
		}

		$scope = $this->products->normalize_scope( $args['scope'] );

		// A currency mode change always requires a full recalculation of the price fields.
		// A dry run has to preview that same full recalculation, so its scope is
		// forced as well: otherwise the update could never match its preview.
		if ( $this->settings->currency_mode_is_stale() && in_array( $type, array( Job::TYPE_SYNC, Job::TYPE_PREVIEW ), true ) ) {
			$scope          = Product_Repository::default_scope();
			$scope['label'] = __( 'Every managed product (currency mode change)', 'usd-to-toman-price-sync-for-woocommerce' );
		}

		// Dry run first: a real update needs a completed preview that was made
		// with the very same rate, currency mode, rounding and scope.
		if ( Job::TYPE_SYNC === $type && $this->settings->get( 'require_preview' ) ) {
			$preview_check = $this->check_preview( $rate, $scope, (int) $args['preview'] );

			if ( is_wp_error( $preview_check ) ) {
				return $preview_check;
			}
		}

		if ( $scope['ids'] ) {
			$result = $this->create_selected_job( $type, $scope, $rate, $args );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $result;
		}

		$rounding = $this->settings->rounding_args();

		$job_id = $this->jobs->create(
			array(
				'status'        => Job::STATUS_QUEUED,
				'job_type'      => $type,
				'scope'         => $scope,
				'currency_mode' => (string) $this->settings->get( 'currency_mode' ),
				'rate'          => $rate,
				'previous_rate' => $args['previous_rate'],
				'rounding'      => $rounding['rounding'],
				'increment'     => $rounding['increment'],
				'decimals'      => $rounding['decimals'],
				'user_id'       => get_current_user_id(),
				'phase'         => Job::PHASE_DISCOVER,
			)
		);

		if ( ! $job_id ) {
			return new \WP_Error( 'usdtf_job_create_failed', __( 'The job could not be created. Check the plugin log for details.', 'usd-to-toman-price-sync-for-woocommerce' ) );
		}

		$this->logger->info(
			'Job created.',
			array(
				'type'  => $type,
				'rate'  => $rate,
				'scope' => $scope,
				'note'  => $args['note'],
			),
			$job_id
		);

		$job = $this->jobs->get( $job_id );

		if ( ! $job ) {
			return new \WP_Error( 'usdtf_job_create_failed', __( 'The job could not be created.', 'usd-to-toman-price-sync-for-woocommerce' ) );
		}

		$started = $this->start_job( $job_id );

		if ( is_wp_error( $started ) ) {
			return $started;
		}

		$job = $this->jobs->get( $job_id );

		return $job ? $job->to_array() : array();
	}

	/**
	 * Create a job for an explicit list of selected products and variations.
	 *
	 * @param string $type  Job type.
	 * @param array  $scope Normalized scope with IDs.
	 * @param float  $rate  Rate.
	 * @param array  $args  Original arguments.
	 * @return array|\WP_Error
	 */
	private function create_selected_job( $type, array $scope, $rate, array $args ) {
		$rounding = $this->settings->rounding_args();

		$job_id = $this->jobs->create(
			array(
				'status'        => Job::STATUS_QUEUED,
				'job_type'      => $type,
				'scope'         => $scope,
				'currency_mode' => (string) $this->settings->get( 'currency_mode' ),
				'rate'          => $rate,
				'previous_rate' => $args['previous_rate'],
				'rounding'      => $rounding['rounding'],
				'increment'     => $rounding['increment'],
				'decimals'      => $rounding['decimals'],
				'user_id'       => get_current_user_id(),
				'phase'         => Job::PHASE_DISCOVER,
			)
		);

		if ( ! $job_id ) {
			return new \WP_Error( 'usdtf_job_create_failed', __( 'The job could not be created.', 'usd-to-toman-price-sync-for-woocommerce' ) );
		}

		$items = array();

		foreach ( $scope['ids'] as $object_id ) {
			$product = wc_get_product( $object_id );

			if ( ! $product ) {
				continue;
			}

			$is_variation = $product->get_parent_id() > 0;

			$items[] = array(
				'object_id'         => (int) $object_id,
				'parent_id'         => (int) $product->get_parent_id(),
				'object_type'       => $is_variation ? 'variation' : 'product',
				'expected_revision' => $this->pricing->get_revision( $object_id ),
			);
		}

		$this->jobs->add_items( $job_id, $items );

		$job = $this->jobs->get( $job_id );

		if ( ! $job ) {
			return new \WP_Error( 'usdtf_job_create_failed', __( 'The job could not be created.', 'usd-to-toman-price-sync-for-woocommerce' ) );
		}

		$this->jobs->update(
			$job_id,
			array(
				'total_items' => count( $items ),
				'phase'       => Job::PHASE_PROCESS,
			)
		);

		$this->logger->info( 'Selected products queued.', array( 'count' => count( $items ) ), $job_id );

		$started = $this->start_job( $job_id );

		if ( is_wp_error( $started ) ) {
			return $started;
		}

		$job = $this->jobs->get( $job_id );

		return $job ? $job->to_array() : array();
	}

	/**
	 * Whether a completed dry run exists that matches the requested update.
	 *
	 * The dry run and the update must agree on the rate, the transaction
	 * currency, the rounding settings and the normalized scope. Without a
	 * matching dry run the update is refused, so nobody can rewrite the
	 * catalog without having seen what would happen.
	 *
	 * @param float $rate       Rate the update would use.
	 * @param array $scope      Normalized scope the update would use.
	 * @param int   $preview_id Optional preview job ID the caller presents.
	 * @return true|\WP_Error True when a matching completed preview exists.
	 */
	private function check_preview( $rate, array $scope, $preview_id = 0 ) {
		$fingerprint = self::job_fingerprint(
			$rate,
			(string) $this->settings->get( 'currency_mode' ),
			$this->settings->rounding_args(),
			$scope
		);

		if ( $preview_id > 0 ) {
			$preview = $this->jobs->get( $preview_id );

			if ( ! $preview || Job::TYPE_PREVIEW !== $preview->type() ) {
				return new \WP_Error(
					'usdtf_preview_not_found',
					__( 'The referenced dry run could not be found. Run the dry run again and start the update from its result.', 'usd-to-toman-price-sync-for-woocommerce' ),
					array( 'status' => 404 )
				);
			}

			$candidates = array( $preview );
		} else {
			$candidates = $this->jobs->query(
				array(
					'limit'    => 10,
					'status'   => Job::STATUS_COMPLETED,
					'job_type' => Job::TYPE_PREVIEW,
				)
			);
		}

		foreach ( $candidates as $candidate ) {
			if ( Job::STATUS_COMPLETED !== $candidate->status() ) {
				continue;
			}

			$preview_fingerprint = self::job_fingerprint(
				$candidate->rate(),
				(string) $candidate->data['currency_mode'],
				array(
					'rounding'  => (string) $candidate->data['rounding'],
					'increment' => (float) $candidate->data['increment'],
					'decimals'  => (int) $candidate->data['decimals'],
				),
				$this->products->normalize_scope( $candidate->scope() )
			);

			if ( $preview_fingerprint === $fingerprint ) {
				return true;
			}
		}

		return new \WP_Error(
			'usdtf_preview_required',
			__( 'Run a dry run first: an update may only start after a completed dry run that used the same exchange rate, transaction currency, rounding settings and scope.', 'usd-to-toman-price-sync-for-woocommerce' ),
			array(
				'status'     => 428,
				'fingerprint' => $fingerprint,
			)
		);
	}

	/**
	 * Fingerprint of what a job would do, used to match a preview to an update.
	 *
	 * @param float  $rate          Rate.
	 * @param string $currency_mode Transaction currency mode.
	 * @param array  $rounding      Rounding arguments: rounding, increment, decimals.
	 * @param array  $scope         Normalized scope.
	 * @return string
	 */
	public static function job_fingerprint( $rate, $currency_mode, array $rounding, array $scope ) {
		unset( $scope['label'] );

		ksort( $scope );

		return md5(
			wp_json_encode(
				array(
					'rate'          => round( (float) $rate, 6 ),
					'currency_mode' => (string) $currency_mode,
					'rounding'      => (string) $rounding['rounding'],
					'increment'     => (float) $rounding['increment'],
					'decimals'      => (int) $rounding['decimals'],
					'scope'         => $scope,
				)
			)
		);
	}

	/**
	 * Start a queued job and queue its first step.
	 *
	 * @param int $job_id Job ID.
	 * @return bool|\WP_Error True on success, false when the lock is held, or
	 *                        an error when the first worker step could not be queued.
	 */
	public function start_job( $job_id ) {
		$job = $this->jobs->get( $job_id );

		if ( ! $job || $job->is_finished() ) {
			return false;
		}

		if ( ! $this->lock->acquire( $job_id ) ) {
			$holder = $this->lock->read();

			$this->logger->warning(
				'Job could not start because another job holds the lock.',
				array( 'holder' => $holder['job_id'] ),
				$job_id
			);

			return false;
		}

		$this->jobs->update(
			$job_id,
			array(
				'status'     => Job::STATUS_RUNNING,
				'started_at' => $job->data['started_at'] ? $job->data['started_at'] : current_time( 'mysql', true ),
			)
		);

		$this->jobs->heartbeat( $job_id, Job::STATUS_RUNNING );

		$job = $this->jobs->get( $job_id );

		$hook = in_array( $job->phase(), array( Job::PHASE_DISCOVER, Job::PHASE_DISCOVER_VARIATIONS ), true ) ? self::HOOK_DISCOVER : self::HOOK_PROCESS;

		if ( ! $this->queue_step( $job_id, $hook ) ) {
			return new \WP_Error( 'usdtf_queue_failed', $this->queue_failed_message() );
		}

		return true;
	}

	/**
	 * The message shown when the background queue refuses a worker step.
	 *
	 * @return string
	 */
	private function queue_failed_message() {
		return __( 'The background queue could not be reached, so the job was paused. Open the jobs screen and resume it once the queue works again.', 'usd-to-toman-price-sync-for-woocommerce' );
	}

	/**
	 * Queue the next worker step, or park the job when queueing fails.
	 *
	 * A job whose worker action never reached the queue would look active and
	 * block every other update while making no progress. On failure the job is
	 * paused with a clear message, the lease is released and the error is
	 * logged, so an administrator can resume it once the queue works again.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $hook   Worker hook.
	 * @param int    $delay  Seconds to wait before running the step.
	 * @param bool   $unique Whether duplicate pending actions are avoided.
	 * @return bool True when the step was queued.
	 */
	private function queue_step( $job_id, $hook, $delay = 0, $unique = true ) {
		if ( $this->scheduler->enqueue( $hook, array( (int) $job_id ), $delay, $unique ) ) {
			return true;
		}

		$this->logger->error(
			'Queueing the next worker step failed.',
			array(
				'hook'  => $hook,
				'job'   => (int) $job_id,
				'delay' => (int) $delay,
			),
			$job_id
		);

		$this->jobs->update(
			$job_id,
			array(
				'status'  => Job::STATUS_PAUSED,
				'message' => $this->queue_failed_message(),
			)
		);

		$this->lock->release( $job_id );

		return false;
	}

	/**
	 * Discovery step: queue the products of the next page.
	 *
	 * Parent products are discovered first. When the scope addresses the whole
	 * managed catalog, a second stream then looks for managed variations that
	 * live under unmanaged parents, which the parent walk would never reach.
	 * Both streams run in this one call for as many pages as the run budget
	 * allows; a catalog that needs more pages continues in the next request
	 * through the saved phase and page.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public function handle_discovery( $job_id ) {
		$job_id = (int) $job_id;
		$job    = $this->jobs->get( $job_id );

		if ( ! $job || Job::STATUS_RUNNING !== $job->status() || ! in_array( $job->phase(), array( Job::PHASE_DISCOVER, Job::PHASE_DISCOVER_VARIATIONS ), true ) ) {
			return;
		}

		if ( ! $this->lock->heartbeat( $job_id ) ) {
			$this->logger->warning( 'Discovery stopped: the job lock is held by another process.', array(), $job_id );

			return;
		}

		/**
		 * Filters how many discovery pages (products and variations together)
		 * one worker run may process before it continues in the next request.
		 *
		 * @param int $pages  Pages per run.
		 * @param Job $job    Job.
		 */
		$max_pages = max( 1, (int) apply_filters( 'usdtf_discovery_pages_per_run', 20, $job ) );

		$pages = 0;

		while ( true ) {
			$job = $this->jobs->get( $job_id );

			if ( ! $job || Job::STATUS_RUNNING !== $job->status() ) {
				return;
			}

			if ( Job::PHASE_DISCOVER === $job->phase() ) {
				if ( $this->discover_products( $job ) ) {
					if ( ++$pages >= $max_pages ) {
						$this->jobs->heartbeat( $job_id );
						$this->queue_step( $job_id, self::HOOK_DISCOVER );

						return;
					}

					continue;
				}

				if ( $this->variation_discovery_applies( $job->scope() ) ) {
					$this->jobs->update(
						$job_id,
						array(
							'phase'          => Job::PHASE_DISCOVER_VARIATIONS,
							'discovery_page' => 1,
						)
					);

					$this->jobs->heartbeat( $job_id );

					continue;
				}

				break;
			}

			if ( Job::PHASE_DISCOVER_VARIATIONS === $job->phase() ) {
				if ( $this->discover_variations( $job ) ) {
					if ( ++$pages >= $max_pages ) {
						$this->jobs->heartbeat( $job_id );
						$this->queue_step( $job_id, self::HOOK_DISCOVER );

						return;
					}

					continue;
				}

				break;
			}

			break;
		}

		$this->jobs->update(
			$job_id,
			array(
				'phase'          => Job::PHASE_PROCESS,
				'discovery_page' => 1,
			)
		);

		$this->jobs->heartbeat( $job_id );

		$this->queue_step( $job_id, self::HOOK_PROCESS );
	}

	/**
	 * Queue the products of the next discovery page.
	 *
	 * @param Job $job Job.
	 * @return bool True when another product page is waiting.
	 */
	private function discover_products( Job $job ) {
		$scope = $job->scope();
		$page  = max( 1, (int) $job->data['discovery_page'] );

		/**
		 * Filters how many parent products are inspected per discovery run.
		 *
		 * @param int $page_size Products per run.
		 * @param Job $job       Job.
		 */
		$page_size = max( 10, min( 500, (int) apply_filters( 'usdtf_discovery_page_size', Product_Repository::DISCOVERY_PAGE_SIZE, $job ) ) );

		$ids = $this->products->get_ids( $scope, $page, $page_size, 'product', 0, $job->rate() );

		$items = array();

		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$items[] = array(
				'object_id'         => (int) $product_id,
				'parent_id'         => (int) $product->get_parent_id(),
				'object_type'       => $product->get_parent_id() > 0 ? 'variation' : 'product',
				'expected_revision' => $this->pricing->get_revision( $product_id ),
			);
		}

		$inserted = $this->jobs->add_items( $job->id(), $items );

		$this->jobs->increment( $job->id(), array( 'total_items' => $inserted ) );

		$this->logger->info(
			'Discovery page processed.',
			array(
				'page'   => $page,
				'found'  => count( $ids ),
				'queued' => $inserted,
				'scope'  => $scope,
			),
			$job->id()
		);

		if ( count( $ids ) >= $page_size ) {
			$this->jobs->update( $job->id(), array( 'discovery_page' => $page + 1 ) );

			return true;
		}

		return false;
	}

	/**
	 * Whether the independent variation discovery applies to a scope.
	 *
	 * Only scopes that address the whole managed catalog are eligible:
	 * category, type and selection scopes cannot be expressed for variation
	 * posts, so guessing there would queue products nobody asked for. Scopes
	 * that exclude variations never walk variations at all.
	 *
	 * @param array $scope Normalized scope.
	 * @return bool
	 */
	private function variation_discovery_applies( array $scope ) {
		$scope = $this->products->normalize_scope( $scope );

		if ( Product_Pricing::MODE_MANAGED !== $scope['mode'] ) {
			return false;
		}

		if ( $scope['ids'] || $scope['category'] || $scope['product_type'] ) {
			return false;
		}

		if ( empty( $scope['include_variations'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Queue managed variations under unmanaged parents of the next page.
	 *
	 * Variations of a managed parent are handled by the parent item, so they
	 * are skipped here. What remains are exactly the managed variations a
	 * parent based discovery would have missed.
	 *
	 * @param Job $job Job.
	 * @return bool True when another variation page is waiting.
	 */
	private function discover_variations( Job $job ) {
		$scope = $job->scope();
		$page  = max( 1, (int) $job->data['discovery_page'] );

		/**
		 * Filters how many variations are inspected per discovery run.
		 *
		 * @param int $page_size Variations per run.
		 * @param Job $job       Job.
		 */
		$page_size = max( 10, min( 500, (int) apply_filters( 'usdtf_variation_discovery_page_size', Product_Repository::DISCOVERY_PAGE_SIZE, $job ) ) );

		$ids = $this->products->get_ids( $scope, $page, $page_size, 'variation', 0, $job->rate() );

		$items = array();

		foreach ( $ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation ) {
				continue;
			}

			$parent_id = (int) $variation->get_parent_id();

			if ( $parent_id > 0 && $this->pricing->is_managed( $parent_id ) ) {
				// Reached through the parent item.
				continue;
			}

			$items[] = array(
				'object_id'         => (int) $variation_id,
				'parent_id'         => $parent_id,
				'object_type'       => 'variation',
				'expected_revision' => $this->pricing->get_revision( $variation_id ),
			);
		}

		$inserted = $this->jobs->add_items( $job->id(), $items );

		$this->jobs->increment( $job->id(), array( 'total_items' => $inserted ) );

		$this->logger->info(
			'Variation discovery page processed.',
			array(
				'page'   => $page,
				'found'  => count( $ids ),
				'queued' => $inserted,
			),
			$job->id()
		);

		if ( count( $ids ) >= $page_size ) {
			$this->jobs->update( $job->id(), array( 'discovery_page' => $page + 1 ) );

			return true;
		}

		return false;
	}

	/**
	 * Processing step: run one batch of items.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public function handle_batch( $job_id ) {
		$job_id = (int) $job_id;
		$job    = $this->jobs->get( $job_id );

		if ( ! $job || Job::STATUS_RUNNING !== $job->status() ) {
			return;
		}

		if ( in_array( $job->phase(), array( Job::PHASE_DISCOVER, Job::PHASE_DISCOVER_VARIATIONS ), true ) ) {
			// Discovery stalled (for example after a plugin update): pick it up again.
			$this->queue_step( $job_id, self::HOOK_DISCOVER );

			return;
		}

		if ( ! $this->lock->heartbeat( $job_id ) ) {
			$this->logger->warning( 'Batch stopped: the job lock is held by another process.', array(), $job_id );

			return;
		}

		$started = microtime( true );
		$budget  = $this->settings->time_budget();

		$items = $this->jobs->next_items( $job_id, $this->settings->batch_size() );

		$out_of_time = false;

		foreach ( $items as $item ) {
			if ( microtime( true ) - $started > $budget ) {
				$out_of_time = true;

				break;
			}

			$this->process_item( $job, $item );

			// A pause or cancel request is honoured between items.
			$fresh = $this->jobs->get( $job_id );

			if ( ! $fresh || Job::STATUS_RUNNING !== $fresh->status() ) {
				$this->after_run( $job_id, true );

				return;
			}
		}

		unset( $out_of_time );

		$this->after_run( $job_id, false );
	}

	/**
	 * Decide what a job does after a batch finished.
	 *
	 * @param int  $job_id Job ID.
	 * @param bool $halted Whether the run stopped because of a pause/cancel.
	 * @return void
	 */
	private function after_run( $job_id, $halted ) {
		$job = $this->jobs->get( $job_id );

		if ( ! $job ) {
			return;
		}

		$this->sync_counters( $job );

		if ( $halted ) {
			return;
		}

		if ( Job::STATUS_RUNNING !== $job->status() ) {
			return;
		}

		$this->jobs->heartbeat( $job_id );

		if ( $this->jobs->pending_count( $job_id ) > 0 ) {
			// Items whose retry delay has not passed yet must not be picked up
			// immediately again, so the next batch waits for the earliest retry.
			$this->queue_step( $job_id, self::HOOK_PROCESS, $this->jobs->next_retry_delay( $job_id ) );

			return;
		}

		$this->jobs->update( $job_id, array( 'phase' => Job::PHASE_FINALIZE ) );

		$this->queue_step( $job_id, self::HOOK_FINALIZE );
	}

	/**
	 * Finalize step: recalculate variable parents of touched variations.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public function handle_finalize( $job_id ) {
		$job_id = (int) $job_id;
		$job    = $this->jobs->get( $job_id );

		if ( ! $job || Job::STATUS_RUNNING !== $job->status() ) {
			return;
		}

		if ( ! $this->lock->heartbeat( $job_id ) ) {
			$this->logger->warning( 'Finalize stopped: the job lock is held by another process.', array(), $job_id );

			return;
		}

		$result = $this->jobs->parents_for_finalize( $job_id, Job_Repository::ITEM_CHANGED, self::PARENT_SLICE );

		if ( $result['parents'] ) {
			foreach ( $result['parents'] as $parent_id ) {
				$this->recorder->sync_parent( (int) $parent_id );
			}

			$this->jobs->mark_parents_synced( $job_id, $result['parents'] );

			$this->logger->info(
				'Variable parents recalculated.',
				array( 'count' => count( $result['parents'] ) ),
				$job_id
			);

			$this->jobs->heartbeat( $job_id );
			$this->queue_step( $job_id, self::HOOK_FINALIZE );

			return;
		}

		$this->sync_counters( $job );

		$job = $this->jobs->get( $job_id );

		if ( ! $job ) {
			return;
		}

		if ( Job::STATUS_RUNNING === $job->status() ) {
			$this->finish_job( $job, $job->completed_status(), '' );
		}
	}

	/**
	 * Recalculate the job counters from the item table.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	private function sync_counters( Job $job ) {
		$totals = $this->jobs->item_totals( $job->id() );

		$statuses = array(
			Job_Repository::ITEM_CHANGED,
			Job_Repository::ITEM_UNCHANGED,
			Job_Repository::ITEM_SKIPPED,
			Job_Repository::ITEM_FAILED,
			Job_Repository::ITEM_CONFLICT,
			Job_Repository::ITEM_CANCELLED,
		);

		$processed = 0;

		foreach ( $statuses as $status ) {
			$processed += isset( $totals[ $status ] ) ? (int) $totals[ $status ] : 0;
		}

		$this->jobs->update(
			$job->id(),
			array(
				'changed'   => isset( $totals[ Job_Repository::ITEM_CHANGED ] ) ? (int) $totals[ Job_Repository::ITEM_CHANGED ] : 0,
				'unchanged' => isset( $totals[ Job_Repository::ITEM_UNCHANGED ] ) ? (int) $totals[ Job_Repository::ITEM_UNCHANGED ] : 0,
				'skipped'   => isset( $totals[ Job_Repository::ITEM_SKIPPED ] ) ? (int) $totals[ Job_Repository::ITEM_SKIPPED ] : 0,
				'failed'    => isset( $totals[ Job_Repository::ITEM_FAILED ] ) ? (int) $totals[ Job_Repository::ITEM_FAILED ] : 0,
				'conflicts' => isset( $totals[ Job_Repository::ITEM_CONFLICT ] ) ? (int) $totals[ Job_Repository::ITEM_CONFLICT ] : 0,
				'attention' => $this->jobs->attention_total( $job->id() ),
				'processed' => $processed,
			)
		);
	}

	/**
	 * Process a single work item.
	 *
	 * @param Job   $job  Job.
	 * @param array $item Item row.
	 * @return void
	 */
	public function process_item( Job $job, array $item ) {
		$object_id = (int) $item['object_id'];
		$product   = wc_get_product( $object_id );

		// Announce the product the worker is busy with: the live progress panel
		// reads it back from the persisted job row.
		$this->jobs->update( $job->id(), array( 'current_item' => self::item_label( $object_id, $product ) ) );

		if ( ! $product ) {
			$this->complete_item(
				$item,
				array(
					'status'  => Job_Repository::ITEM_SKIPPED,
					'code'    => 'missing',
					'message' => __( 'The product no longer exists.', 'usd-to-toman-price-sync-for-woocommerce' ),
				)
			);

			return;
		}

		if ( $product->is_type( 'variable' ) ) {
			$scope = $job->scope();

			if ( empty( $scope['include_variations'] ) ) {
				$this->complete_item(
					$item,
					array(
						'status'  => Job_Repository::ITEM_SKIPPED,
						'code'    => 'variations_excluded',
						'message' => __( 'Variations are excluded from this update, so this variable product was skipped. Select its variations directly to update them anyway.', 'usd-to-toman-price-sync-for-woocommerce' ),
					)
				);

				return;
			}

			$this->process_variable_item( $job, $item, $product );

			return;
		}

		$result = $this->calculate_and_write( $job, $item, $product, null );

		$this->complete_item( $item, $result );
	}

	/**
	 * Process one variable product in slices of variations.
	 *
	 * Variation IDs are paginated from the database, so a product with more
	 * variations than one slice holds is walked in as many slices as it needs
	 * instead of being cut off at a hard limit. The per-item counters are
	 * cumulative across slices: a failure or conflict in the first slice stays
	 * visible when the last slice finishes.
	 *
	 * @param Job         $job     Job.
	 * @param array       $item    Item row.
	 * @param \WC_Product $product Variable product.
	 * @return void
	 */
	private function process_variable_item( Job $job, array $item, $product ) {
		$parent_id = $product->get_id();
		$total     = $this->products->count_variations( $parent_id );

		if ( $total <= 0 ) {
			$this->complete_item(
				$item,
				array(
					'status'  => Job_Repository::ITEM_SKIPPED,
					'code'    => 'no_variations',
					'message' => __( 'This variable product has no variations, so there is nothing to synchronize.', 'usd-to-toman-price-sync-for-woocommerce' ),
				)
			);

			return;
		}

		/**
		 * Filters how many variations are processed per slice.
		 *
		 * @param int $slice Number of variations.
		 * @param int $parent_id Variable product ID.
		 */
		$slice_size = max( 1, min( 50, (int) apply_filters( 'usdtf_variation_slice', self::VARIATION_SLICE, $parent_id ) ) );

		$offset = max( 0, (int) $item['child_cursor'] );
		$slice  = $this->products->variation_ids( $parent_id, $offset, $slice_size );

		if ( ! $slice ) {
			$this->complete_item(
				$item,
				array(
					'status'  => Job_Repository::ITEM_SKIPPED,
					'code'    => 'no_variations',
					'message' => __( 'This variable product has no variations, so there is nothing to synchronize.', 'usd-to-toman-price-sync-for-woocommerce' ),
				)
			);

			return;
		}

		// Counters survive the request boundary: they are read back from the
		// item row, which is what keeps an early slice failure from disappearing.
		$stats = array();

		if ( ! empty( $item['child_stats'] ) ) {
			$stats = json_decode( (string) $item['child_stats'], true );
		}

		$stats = wp_parse_args(
			is_array( $stats ) ? $stats : array(),
			array(
				'changed'   => 0,
				'unchanged' => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'conflict'  => 0,
				'attention' => 0,
			)
		);

		$slice_stats    = $stats;
		$changed_before = (int) $stats['changed'];

		$messages = array();

		foreach ( $slice as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation ) {
				++$slice_stats['skipped'];

				continue;
			}

			$revision_before = $this->pricing->get_revision( $variation_id );
			$result          = $this->calculate_and_write( $job, $item, $variation, $revision_before );

			if ( ! empty( $result['code'] ) && in_array( $result['code'], self::ATTENTION_CODES, true ) ) {
				// An invalid variation source is an error worth reporting even
				// when the rest of the slices finish cleanly.
				$slice_stats['attention'] = 1;
			}

			if ( ! $job->is_write_job() ) {
				// A dry run never writes, so a concurrent edit is not a conflict.
				$status = $result['status'];
			} elseif ( ! empty( $result['wrote'] ) && $this->pricing->get_revision( $variation_id ) !== $revision_before ) {
				$status            = Job_Repository::ITEM_CONFLICT;
				$result['code']    = 'conflict';
				$result['message'] = __( 'The Toman price of this variation was edited while it was being synchronized. It is reported for recalculation.', 'usd-to-toman-price-sync-for-woocommerce' );
				$this->pricing->mark_conflict( $variation_id, $result['message'] );
			} else {
				$status = $result['status'];
			}

			if ( array_key_exists( $status, $slice_stats ) ) {
				++$slice_stats[ $status ];
			}

			if ( ! empty( $result['message'] ) && count( $messages ) < 10 ) {
				$messages[] = sprintf( '#%d: %s', (int) $variation_id, (string) $result['message'] );
			}
		}

		$processed = min( $total, $offset + count( $slice ) );
		$stats     = $slice_stats;

		$summary = sprintf(
			/* translators: 1: processed variations, 2: total variations, 3: changed, 4: unchanged, 5: skipped, 6: failed. */
			__( 'Variations %1$d/%2$d — changed: %3$d, unchanged: %4$d, skipped: %5$d, failed: %6$d.', 'usd-to-toman-price-sync-for-woocommerce' ),
			$processed,
			$total,
			$stats['changed'],
			$stats['unchanged'],
			$stats['skipped'],
			$stats['failed']
		);

		$this->jobs->increment(
			$job->id(),
			array(
				'variations_processed' => count( $slice ),
				'variations_changed'   => max( 0, (int) $stats['changed'] - $changed_before ),
			)
		);

		$message = $summary . ( $messages ? ' ' . implode( ' | ', $messages ) : '' );

		if ( $processed < $total ) {
			// More slices to go: the item stays pending and is picked up again.
			$this->jobs->update_item(
				$item['id'],
				array(
					'status'       => Job_Repository::ITEM_PROCESSING,
					'child_cursor' => $processed,
					'child_total'  => $total,
					'child_stats'  => wp_json_encode( $stats ),
					'attempts'     => 0,
					'retry_after'  => null,
					'message'      => $message,
					'attention'    => (int) $stats['attention'],
				)
			);

			return;
		}

		// Last slice: make sure the parent price range is correct.
		$this->recorder->sync_parent( $parent_id );

		$status = Job_Repository::ITEM_UNCHANGED;

		if ( $stats['failed'] > 0 ) {
			$status = Job_Repository::ITEM_FAILED;
		} elseif ( $stats['conflict'] > 0 ) {
			$status = Job_Repository::ITEM_CONFLICT;
		} elseif ( $stats['changed'] > 0 ) {
			$status = Job_Repository::ITEM_CHANGED;
		} elseif ( $stats['skipped'] >= $total ) {
			$status = Job_Repository::ITEM_SKIPPED;
		}

		$this->jobs->update_item(
			$item['id'],
			array(
				'status'       => $status,
				'child_cursor' => $processed,
				'child_total'  => $total,
				'child_stats'  => wp_json_encode( $stats ),
				'attempts'     => 0,
				'retry_after'  => null,
				'message'      => $message,
				'attention'    => (int) $stats['attention'],
			)
		);
	}

	/**
	 * Convert and write one product or variation.
	 *
	 * @param Job         $job      Job.
	 * @param array       $item     Item row.
	 * @param \WC_Product $product  Product or variation.
	 * @param int|null    $revision Expected revision override.
	 * @return array Recorder result.
	 */
	private function calculate_and_write( Job $job, array $item, $product, $revision = null ) {
		$rounding = array(
			'rounding'  => (string) $job->data['rounding'],
			'increment' => (float) $job->data['increment'],
			'decimals'  => (int) $job->data['decimals'],
		);

		$snapshot = $this->pricing->get_snapshot( $product );

		$source = array(
			'regular' => $snapshot['source_regular'],
			'sale'    => $snapshot['source_sale'],
		);

		$rate = $job->rate();

		$target = array(
			'regular' => null === $source['regular'] ? null : Calculator::from_toman( $source['regular'], $rate, $rounding ),
			'sale'    => null === $source['sale'] ? null : Calculator::from_toman( $source['sale'], $rate, $rounding ),
		);

		if ( null === $revision ) {
			$revision = (int) $item['expected_revision'];
		}

		$result = $this->recorder->apply(
			$product,
			$source,
			$target,
			array(
				'dry_run'           => ! $job->is_write_job(),
				'rate'              => $rate,
				'currency_mode'     => (string) $job->data['currency_mode'],
				'job_id'            => $job->id(),
				'expected_revision' => $revision,
				'sync_parent'       => false,
			)
		);

		$result['source'] = $source;
		$result['target'] = $target;

		return $result;
	}

	/**
	 * Human readable label of the item a worker is processing.
	 *
	 * @param int              $object_id Product or variation ID.
	 * @param \WC_Product|null $product   Loaded product, when it still exists.
	 * @return string
	 */
	private static function item_label( $object_id, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			/* translators: %d: product ID. */
			return sprintf( __( '#%d (deleted)', 'usd-to-toman-price-sync-for-woocommerce' ), (int) $object_id );
		}

		$name = wp_strip_all_tags( (string) $product->get_name() );

		if ( $product->is_type( 'variation' ) ) {
			/* translators: 1: variation ID, 2: variation name. */
			return sprintf( __( 'variation #%1$d %2$s', 'usd-to-toman-price-sync-for-woocommerce' ), (int) $object_id, substr( $name, 0, 140 ) );
		}

		/* translators: 1: product ID, 2: product name. */
		return sprintf( __( '#%1$d %2$s', 'usd-to-toman-price-sync-for-woocommerce' ), (int) $object_id, substr( $name, 0, 150 ) );
	}

	/**
	 * Write the outcome of an item to the item table.
	 *
	 * @param array $item   Item row.
	 * @param array $result Result data.
	 * @return void
	 */
	private function complete_item( array $item, array $result ) {
		$status = isset( $result['status'] ) ? (string) $result['status'] : Job_Repository::ITEM_SKIPPED;
		$code   = isset( $result['code'] ) ? (string) $result['code'] : '';

		$data = array(
			'status'    => $status,
			'message'   => isset( $result['message'] ) ? (string) $result['message'] : '',
			'attention' => in_array( $code, self::ATTENTION_CODES, true ) ? 1 : 0,
		);

		if ( isset( $result['old'] ) ) {
			$data['old_regular'] = Calculator::to_price_string( $result['old']['regular'], 6 );
			$data['old_sale']    = Calculator::to_price_string( $result['old']['sale'], 6 );
		}

		if ( isset( $result['new'] ) ) {
			$data['new_regular'] = Calculator::to_price_string( $result['new']['regular'], 6 );
			$data['new_sale']    = Calculator::to_price_string( $result['new']['sale'], 6 );
		}

		if ( isset( $result['source'] ) ) {
			$data['toman_regular'] = Calculator::to_price_string( $result['source']['regular'], 0 );
			$data['toman_sale']    = Calculator::to_price_string( $result['source']['sale'], 0 );
		}

		$attempts = (int) $item['attempts'];

		if ( Job_Repository::ITEM_FAILED === $status && isset( $result['code'] ) && 'exception' === $result['code'] ) {
			$limit = (int) $this->settings->get( 'retry_limit' );

			if ( $attempts < $limit ) {
				$data['status']      = Job_Repository::ITEM_PENDING;
				$data['attempts']    = $attempts + 1;
				$data['retry_after'] = gmdate( 'Y-m-d H:i:s', time() + ( 30 * ( $attempts + 1 ) ) );
			} else {
				$data['attempts'] = $attempts;
			}
		}

		$this->jobs->update_item( $item['id'], $data );
	}

	/**
	 * Finish a job.
	 *
	 * @param Job    $job     Job.
	 * @param string $status  Terminal status.
	 * @param string $message Optional message.
	 * @return void
	 */
	public function finish_job( Job $job, $status, $message = '' ) {
		$this->jobs->update(
			$job->id(),
			array(
				'status'      => $status,
				'phase'       => Job::PHASE_DONE,
				'finished_at' => current_time( 'mysql', true ),
				'message'     => $message,
			)
		);

		$this->lock->release( $job->id() );

		$job = $this->jobs->get( $job->id() );

		if ( ! $job ) {
			return;
		}

		$counters = $job->counters();

		$this->mark_mode_synced( $job, $status );

		$this->logger->info(
			'Job finished.',
			array(
				'status'   => $status,
				'type'     => $job->type(),
				'rate'     => $job->rate(),
				'counters' => $counters,
			),
			$job->id()
		);

		if ( $job->is_sync_job() ) {
			$this->rates->log_job_summary( $job, $status, $message );
		}

		if ( Job::TYPE_PREVIEW !== $job->type() && ! $this->settings->currency_mode_is_stale() && count( $job->scope() ) ) {
			$this->settings->set_synced_currency_mode( (string) $this->settings->get( 'currency_mode' ) );
		}

		delete_option( 'usdtf_stale_resumes' );

		$this->products->flush_counts_cache();

		/**
		 * Fires after a synchronization job finished.
		 *
		 * @param Job    $job    Finished job.
		 * @param string $status Terminal status.
		 */
		do_action( 'usdtf_job_finished', $job, $status );
	}

	/**
	 * Remember that the price fields match the active transaction currency.
	 *
	 * The "currency mode changed" warning may only be cleared by a full catalog
	 * update that wrote every item, so partial scopes and jobs with failures or
	 * conflicts keep it visible.
	 *
	 * @param Job    $job    Finished job.
	 * @param string $status Terminal status.
	 * @return void
	 */
	private function mark_mode_synced( Job $job, $status ) {
		if ( ! $job->is_write_job() ) {
			return;
		}

		if ( ! in_array( $status, array( Job::STATUS_COMPLETED, Job::STATUS_COMPLETED_WITH_ERRORS ), true ) ) {
			return;
		}

		$counters = $job->counters();

		if ( $counters['failed'] > 0 || $counters['conflicts'] > 0 ) {
			return;
		}

		$scope          = $this->products->normalize_scope( $job->scope() );
		$full           = $this->products->normalize_scope( Product_Repository::default_scope() );
		$scope['label'] = '';
		$full['label']  = '';

		if ( $scope !== $full ) {
			return;
		}

		$this->settings->set_synced_currency_mode( (string) $job->data['currency_mode'] );
		$this->products->flush_counts_cache();
	}

	/**
	 * Pause a running job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function pause( $job_id ) {
		$job = $this->jobs->get( $job_id );

		if ( ! $job || ! $job->is_active() ) {
			return false;
		}

		$this->jobs->update(
			$job_id,
			array(
				'status'  => Job::STATUS_PAUSED,
				'message' => __( 'Paused by an administrator. The job can be resumed and continues from the saved cursor.', 'usd-to-toman-price-sync-for-woocommerce' ),
			)
		);

		$this->lock->release( $job_id );
		$this->logger->info( 'Job paused.', array(), $job_id );

		return true;
	}

	/**
	 * Resume a paused or failed job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function resume( $job_id ) {
		$job = $this->jobs->get( $job_id );

		if ( ! $job || ! in_array( $job->status(), array( Job::STATUS_PAUSED, Job::STATUS_FAILED, Job::STATUS_QUEUED ), true ) ) {
			return false;
		}

		if ( ! $this->lock->acquire( $job_id ) ) {
			return false;
		}

		$this->jobs->update(
			$job_id,
			array(
				'status'  => Job::STATUS_RUNNING,
				'message' => '',
			)
		);

		$this->jobs->heartbeat( $job_id, Job::STATUS_RUNNING );

		$job = $this->jobs->get( $job_id );

		$queued = false;

		switch ( $job->phase() ) {
			case Job::PHASE_DISCOVER:
			case Job::PHASE_DISCOVER_VARIATIONS:
				$queued = $this->queue_step( $job_id, self::HOOK_DISCOVER );
				break;
			case Job::PHASE_FINALIZE:
				$queued = $this->queue_step( $job_id, self::HOOK_FINALIZE );
				break;
			default:
				$queued = $this->queue_step( $job_id, self::HOOK_PROCESS );
				break;
		}

		if ( ! $queued ) {
			return false;
		}

		$this->logger->info( 'Job resumed.', array(), $job_id );

		return true;
	}

	/**
	 * Cancel a job and release its slot.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function cancel( $job_id ) {
		$job = $this->jobs->get( $job_id );

		if ( ! $job ) {
			return false;
		}

		$this->jobs->cancel_pending_items( $job_id );

		$this->jobs->update(
			$job_id,
			array(
				'status'      => Job::STATUS_CANCELLED,
				'phase'       => Job::PHASE_DONE,
				'finished_at' => current_time( 'mysql', true ),
				'message'     => __( 'Cancelled by an administrator. Prices that were already written are kept.', 'usd-to-toman-price-sync-for-woocommerce' ),
			)
		);

		$this->lock->release( $job_id );

		$this->sync_counters( $job );

		$job = $this->jobs->get( $job_id );

		if ( $job && $job->is_sync_job() ) {
			$this->rates->log_job_summary( $job, Job::STATUS_CANCELLED, __( 'Cancelled by an administrator.', 'usd-to-toman-price-sync-for-woocommerce' ) );
		}

		$this->logger->warning( 'Job cancelled.', array(), $job_id );

		return true;
	}

	/**
	 * Queue items for another attempt and make sure the job runs them.
	 *
	 * A finished job (completed_with_errors, failed, cancelled) is reopened
	 * rather than resumed, because its saved cursor and item rows must be kept.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True when the job is queued for the worker.
	 */
	private function requeue_job( $job_id ) {
		$job = $this->jobs->get( $job_id );

		if ( ! $job ) {
			return false;
		}

		if ( $job->is_active() ) {
			if ( ! $job->is_running() ) {
				$this->resume( $job_id );
			}

			return $this->queue_step( $job_id, self::HOOK_PROCESS );
		}

		if ( ! $this->lock->acquire( $job_id ) ) {
			return false;
		}

		$this->jobs->update(
			$job_id,
			array(
				'status'      => Job::STATUS_RUNNING,
				'phase'       => Job::PHASE_PROCESS,
				'finished_at' => null,
				'message'     => '',
			)
		);

		$this->jobs->heartbeat( $job_id, Job::STATUS_RUNNING );

		if ( ! $this->queue_step( $job_id, self::HOOK_PROCESS ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Queue failed items for another attempt.
	 *
	 * @param int $job_id Job ID.
	 * @return int Number of items queued again.
	 */
	public function retry_failed( $job_id ) {
		$job = $this->jobs->get( $job_id );

		if ( ! $job ) {
			return 0;
		}

		$count = $this->jobs->reset_items( $job_id, Job_Repository::ITEM_FAILED, Job_Repository::ITEM_PENDING );

		if ( $count > 0 ) {
			$this->sync_counters( $job );

			$this->requeue_job( $job_id );
		}

		return $count;
	}

	/**
	 * Queue conflicting products for another attempt with a fresh revision.
	 *
	 * @param int $job_id Job ID.
	 * @return int Number of conflicting items queued again.
	 */
	public function recalculate_conflicts( $job_id ) {
		$items = $this->jobs->items(
			$job_id,
			array(
				'status' => Job_Repository::ITEM_CONFLICT,
				'limit'  => 500,
			)
		);

		$count = 0;

		foreach ( $items as $item ) {
			$revision = $this->pricing->get_revision( (int) $item['object_id'] );

			$this->jobs->update_item(
				$item['id'],
				array(
					'status'            => Job_Repository::ITEM_PENDING,
					'attempts'          => 0,
					'retry_after'       => null,
					'expected_revision' => $revision,
					'message'           => __( 'Queued again after a conflict review.', 'usd-to-toman-price-sync-for-woocommerce' ),
				)
			);

			++$count;
		}

		if ( $count > 0 ) {
			$this->sync_counters( $this->jobs->get( $job_id ) );

			$this->requeue_job( $job_id );

			$this->logger->info( 'Conflicting products queued again.', array( 'count' => $count ), $job_id );
		}

		return $count;
	}

	/**
	 * Roll back the last rate change.
	 *
	 * The rollback is a normal queued synchronization that uses the previous
	 * rate, so it is just as safe and resumable as any other update.
	 *
	 * @return array|\WP_Error
	 */
	public function rollback() {
		$previous = $this->rates->previous_rate();

		if ( $previous <= 0 ) {
			return new \WP_Error(
				'usdtf_no_previous_rate',
				__( 'There is no previous exchange rate to restore.', 'usd-to-toman-price-sync-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$current = $this->rates->get_rate();

		$this->rates->save_rate(
			$previous,
			array(
				'source' => 'rollback',
				'note'   => __( 'Previous rate restored.', 'usd-to-toman-price-sync-for-woocommerce' ),
			)
		);

		return $this->create_job(
			array(
				'type'          => Job::TYPE_ROLLBACK,
				'scope'         => array( 'label' => __( 'Every managed product (rate rollback)', 'usd-to-toman-price-sync-for-woocommerce' ) ),
				'rate'          => $previous,
				'previous_rate' => $current,
				'note'          => __( 'Rate rollback.', 'usd-to-toman-price-sync-for-woocommerce' ),
			)
		);
	}

	/**
	 * Create a dry run job.
	 *
	 * @param array $args Same arguments as create_job().
	 * @return array|\WP_Error
	 */
	public function preview( array $args = array() ) {
		$args['type'] = Job::TYPE_PREVIEW;

		return $this->create_job( $args );
	}

	/**
	 * Recalculate a single product (or the selected ones) right away.
	 *
	 * @param int[] $product_ids Product or variation IDs.
	 * @return array|\WP_Error
	 */
	public function recalculate( array $product_ids ) {
		$product_ids = array_values( array_filter( array_map( 'intval', $product_ids ) ) );

		if ( ! $product_ids ) {
			return new \WP_Error( 'usdtf_no_products', __( 'No products were selected.', 'usd-to-toman-price-sync-for-woocommerce' ), array( 'status' => 400 ) );
		}

		return $this->create_job(
			array(
				'type'  => Job::TYPE_RECALCULATE,
				'scope' => array(
					'ids'   => $product_ids,
					'label' => sprintf(
						/* translators: %d: number of products. */
						_n( 'Recalculate %d product', 'Recalculate %d products', count( $product_ids ), 'usd-to-toman-price-sync-for-woocommerce' ),
						count( $product_ids )
					),
				),
			)
		);
	}

	/**
	 * Resume jobs whose worker action disappeared (host without Action Scheduler).
	 *
	 * @return int Number of resumed jobs.
	 */
	public function resume_orphaned_jobs() {
		$resumed = 0;

		if ( $this->lock->is_held_by_other() ) {
			return 0;
		}

		foreach ( array( Job::STATUS_QUEUED, Job::STATUS_RUNNING ) as $status ) {
			foreach ( $this->jobs->query(
				array(
					'status' => $status,
					'limit'  => 10,
				)
			) as $job ) {
				if ( $job->is_stale() ) {
					continue;
				}

				$pending_process  = $this->scheduler->has_pending( self::HOOK_PROCESS, array( $job->id() ) );
				$pending_discover = $this->scheduler->has_pending( self::HOOK_DISCOVER, array( $job->id() ) );
				$pending_finalize = $this->scheduler->has_pending( self::HOOK_FINALIZE, array( $job->id() ) );

				if ( $pending_process || $pending_discover || $pending_finalize ) {
					continue;
				}

				switch ( $job->phase() ) {
					case Job::PHASE_DISCOVER:
						$queued = $this->queue_step( $job->id(), self::HOOK_DISCOVER, 0, false );
						break;
					case Job::PHASE_FINALIZE:
						$queued = $this->queue_step( $job->id(), self::HOOK_FINALIZE, 0, false );
						break;
					default:
						$queued = $this->queue_step( $job->id(), self::HOOK_PROCESS, 0, false );
						break;
				}

				if ( ! $queued ) {
					continue;
				}

				$this->logger->info( 'Job worker re-queued after a lost action.', array( 'phase' => $job->phase() ), $job->id() );

				++$resumed;
			}
		}

		return $resumed;
	}

	/**
	 * Recover jobs whose worker died without finishing.
	 *
	 * Progress is never thrown away: the job is resumed (up to a small number of
	 * automatic attempts) or paused so an administrator can look at it.
	 *
	 * @return int Number of recovered jobs.
	 */
	public function recover_stale_jobs() {
		$recovered = 0;
		$resumes   = get_option( 'usdtf_stale_resumes', array() );
		$resumes   = is_array( $resumes ) ? $resumes : array();

		foreach ( $this->jobs->query(
			array(
				'status' => Job::STATUS_RUNNING,
				'limit'  => 20,
			)
		) as $job ) {
			if ( ! $job->is_stale() ) {
				continue;
			}

			$job_id = $job->id();
			$count  = isset( $resumes[ $job_id ] ) ? (int) $resumes[ $job_id ] : 0;

			if ( $count >= self::MAX_AUTO_RESUMES ) {
				$this->jobs->update(
					$job_id,
					array(
						'status'  => Job::STATUS_PAUSED,
						'message' => __( 'The background worker stopped several times. The job is paused and can be resumed from the saved progress.', 'usd-to-toman-price-sync-for-woocommerce' ),
					)
				);

				$this->logger->warning( 'Job paused after repeated worker failures.', array( 'auto_resumes' => $count ), $job_id );
				$this->lock->release( $job_id );

				continue;
			}

			$resumes[ $job_id ] = $count + 1;
			update_option( 'usdtf_stale_resumes', $resumes, false );

			$this->logger->warning( 'Recovering a job whose worker stopped.', array( 'auto_resume' => $count + 1 ), $job_id );

			$this->lock->release( $job_id );
			$this->resume( $job_id );

			++$recovered;
		}

		// Jobs that were left half started: queue their first step again.
		foreach ( $this->jobs->query(
			array(
				'status' => Job::STATUS_QUEUED,
				'limit'  => 20,
			)
		) as $job ) {
			if ( ! $this->scheduler->has_pending( self::HOOK_DISCOVER, array( $job->id() ) ) && $this->queue_step( $job->id(), self::HOOK_DISCOVER ) ) {
				++$recovered;
			}
		}

		return $recovered;
	}

	/**
	 * Everything the dashboard needs to render its state.
	 *
	 * @return array
	 */
	public function state() {
		$rate         = $this->rates->get_rate();
		$pending      = $this->rates->get_pending();
		$active       = $this->jobs->active_write_job();
		$last         = $this->jobs->last_finished_job();
		$last_preview = $this->jobs->query(
			array(
				'limit'  => 1,
				'status' => Job::STATUS_COMPLETED,
			)
		);

		$preview_job = null;

		foreach ( $this->jobs->query( array( 'limit' => 5 ) ) as $job ) {
			if ( Job::TYPE_PREVIEW === $job->type() ) {
				$preview_job = $job;
				break;
			}
		}

		unset( $last_preview );

		$scope = Product_Repository::default_scope();

		$preview_required = (bool) $this->settings->get( 'require_preview' );
		$preview_ok       = false;

		if ( $rate > 0 ) {
			$fingerprint = self::job_fingerprint(
				$rate,
				(string) $this->settings->get( 'currency_mode' ),
				$this->settings->rounding_args(),
				$scope
			);

			foreach ( $this->jobs->query(
				array(
					'limit'    => 10,
					'status'   => Job::STATUS_COMPLETED,
					'job_type' => Job::TYPE_PREVIEW,
				)
			) as $candidate ) {
				$candidate_fingerprint = self::job_fingerprint(
					$candidate->rate(),
					(string) $candidate->data['currency_mode'],
					array(
						'rounding'  => (string) $candidate->data['rounding'],
						'increment' => (float) $candidate->data['increment'],
						'decimals'  => (int) $candidate->data['decimals'],
					),
					$this->products->normalize_scope( $candidate->scope() )
				);

				if ( $candidate_fingerprint === $fingerprint ) {
					$preview_ok = true;
					break;
				}
			}
		}

		return array(
			'rate'                => $rate,
			'previous_rate'       => $this->rates->previous_rate(),
			'pending_rate'        => $pending ? array(
				'rate'           => (float) $pending['rate'],
				'previous_rate'  => (float) $pending['previous_rate'],
				'change_percent' => null === $pending['change_percent'] ? null : (float) $pending['change_percent'],
				'created_at'     => (int) $pending['created_at'],
				'user'           => Job::user_display_name( (int) $pending['user_id'] ),
			) : null,
			'summary'             => $this->products->summary( $rate ),
			'scope'               => $scope,
			'currency_mode'       => (string) $this->settings->get( 'currency_mode' ),
			'currency_mode_stale' => $this->settings->currency_mode_is_stale(),
			'active_job'          => $active ? $active->to_array() : null,
			'last_job'            => $last ? $last->to_array() : null,
			'last_preview_job'    => $preview_job ? $preview_job->to_array() : null,
			'preview_required'    => $preview_required,
			'preview_ok'          => $preview_ok,
			'lock'                => $this->lock->status(),
			'scheduler'           => $this->scheduler->health(),
			'settings'            => array(
				'batch_size'         => $this->settings->batch_size(),
				'time_budget'        => $this->settings->time_budget(),
				'rounding'           => $this->settings->get( 'rounding' ),
				'increment'          => (float) $this->settings->get( 'increment' ),
				'decimals'           => (int) $this->settings->get( 'decimals' ),
				'threshold'          => (float) $this->settings->get( 'rate_change_threshold' ),
				'retry_limit'        => (int) $this->settings->get( 'retry_limit' ),
				'retention_days'     => (int) $this->settings->get( 'retention_days' ),
				'display_toman'      => (bool) $this->settings->get( 'display_toman' ),
				'display_toman_cart' => (bool) $this->settings->get( 'display_toman_cart' ),
				'persian_digits'     => (bool) $this->settings->get( 'persian_digits' ),
				'toman_suffix'       => (string) $this->settings->get( 'toman_suffix' ),
				'auto_manage'        => (bool) $this->settings->get( 'auto_manage_new_products' ),
			),
		);
	}
}
