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
				__( 'Save a USD/Toman exchange rate before updating prices.', 'usd-to-toman-price-sync-for-woocommerce' )
			);
		}

		if ( Job::TYPE_PREVIEW !== $type ) {
			$active = $this->jobs->active_write_job();

			if ( $active ) {
				return new \WP_Error(
					'usdtf_job_running',
					__( 'Another price update is already running. Only one update can run at a time.', 'usd-to-toman-price-sync-for-woocommerce' ),
					array( 'job' => $active->to_array() )
				);
			}
		}

		$scope = $this->products->normalize_scope( $args['scope'] );

		// A currency mode change always requires a full recalculation of the price fields.
		if ( Job::TYPE_PREVIEW !== $type && $this->settings->currency_mode_is_stale() ) {
			$scope = Product_Repository::default_scope();
			$scope['label'] = __( 'Every managed product (currency mode change)', 'usd-to-toman-price-sync-for-woocommerce' );
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

		if ( Job::TYPE_PREVIEW === $type ) {
			$this->start_job( $job_id );
		} else {
			$this->start_job( $job_id );
		}

		return $job->to_array();
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

		$this->start_job( $job_id );

		$job = $this->jobs->get( $job_id );

		return $job->to_array();
	}

	/**
	 * Start a queued job and queue its first step.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
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

		if ( Job::PHASE_PROCESS === $job->phase() ) {
			$this->scheduler->enqueue( self::HOOK_PROCESS, array( $job_id ) );
		} else {
			$this->scheduler->enqueue( self::HOOK_DISCOVER, array( $job_id ) );
		}

		return true;
	}

	/**
	 * Discovery step: queue the products of the next page.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public function handle_discovery( $job_id ) {
		$job_id = (int) $job_id;
		$job    = $this->jobs->get( $job_id );

		if ( ! $job || Job::STATUS_RUNNING !== $job->status() || Job::PHASE_DISCOVER !== $job->phase() ) {
			return;
		}

		if ( ! $this->lock->heartbeat( $job_id ) ) {
			$this->logger->warning( 'Discovery stopped: the job lock is held by another process.', array(), $job_id );

			return;
		}

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

		$inserted = $this->jobs->add_items( $job_id, $items );

		$this->jobs->increment( $job_id, array( 'total_items' => $inserted ) );

		$this->logger->info(
			'Discovery page processed.',
			array(
				'page'    => $page,
				'found'   => count( $ids ),
				'queued'  => $inserted,
				'scope'   => $scope,
			),
			$job_id
		);

		$more = count( $ids ) >= $page_size;

		if ( $more ) {
			$this->jobs->update( $job_id, array( 'discovery_page' => $page + 1 ) );
			$this->jobs->heartbeat( $job_id );
			$this->scheduler->enqueue( self::HOOK_DISCOVER, array( $job_id ) );

			return;
		}

		$this->jobs->update(
			$job_id,
			array(
				'phase' => Job::PHASE_PROCESS,
			)
		);

		$this->jobs->heartbeat( $job_id );

		$this->scheduler->enqueue( self::HOOK_PROCESS, array( $job_id ) );
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

		if ( Job::PHASE_DISCOVER === $job->phase() ) {
			// Discovery stalled (for example after a plugin update): pick it up again.
			$this->scheduler->enqueue( self::HOOK_DISCOVER, array( $job_id ) );

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
			$this->scheduler->enqueue( self::HOOK_PROCESS, array( $job_id ) );

			return;
		}

		$this->jobs->update( $job_id, array( 'phase' => Job::PHASE_FINALIZE ) );

		$this->scheduler->enqueue( self::HOOK_FINALIZE, array( $job_id ) );
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
			$this->scheduler->enqueue( self::HOOK_FINALIZE, array( $job_id ) );

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
			$this->process_variable_item( $job, $item, $product );

			return;
		}

		$result = $this->calculate_and_write( $job, $item, $product, null );

		$this->complete_item( $item, $result );
	}

	/**
	 * Process one variable product in slices of variations.
	 *
	 * @param Job         $job     Job.
	 * @param array       $item    Item row.
	 * @param \WC_Product $product Variable product.
	 * @return void
	 */
	private function process_variable_item( Job $job, array $item, $product ) {
		$parent_id     = $product->get_id();
		$variation_ids = $this->products->variation_ids( $parent_id );

		if ( ! $variation_ids ) {
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
		$slice  = array_slice( $variation_ids, $offset, $slice_size );
		$total  = count( $variation_ids );

		$stats = array(
			'changed'   => 0,
			'unchanged' => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'conflict'  => 0,
		);

		$messages = array();

		foreach ( $slice as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation ) {
				++$stats['skipped'];

				continue;
			}

			$revision_before = $this->pricing->get_revision( $variation_id );
			$result          = $this->calculate_and_write( $job, $item, $variation, $revision_before );

			if ( ! $job->is_write_job() ) {
				// A dry run never writes, so a concurrent edit is not a conflict.
				$status = $result['status'];
			} elseif ( ! empty( $result['wrote'] ) && $this->pricing->get_revision( $variation_id ) !== $revision_before ) {
				$status = Job_Repository::ITEM_CONFLICT;
				$result['code']    = 'conflict';
				$result['message'] = __( 'The Toman price of this variation was edited while it was being synchronized. It is reported for recalculation.', 'usd-to-toman-price-sync-for-woocommerce' );
				$this->pricing->mark_conflict( $variation_id, $result['message'] );
			} else {
				$status = $result['status'];
			}

			if ( isset( $stats[ $status ] ) ) {
				++$stats[ $status ];
			}

			if ( ! empty( $result['message'] ) && count( $messages ) < 10 ) {
				$messages[] = sprintf( '#%d: %s', (int) $variation_id, (string) $result['message'] );
			}
		}

		$processed = $offset + count( $slice );

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
				'variations_changed'   => $stats['changed'],
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
					'attempts'     => 0,
					'retry_after'  => null,
					'message'      => $message,
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
		} elseif ( $stats['skipped'] === count( $slice ) ) {
			$status = Job_Repository::ITEM_SKIPPED;
		}

		$this->jobs->update_item(
			$item['id'],
			array(
				'status'       => $status,
				'child_cursor' => $processed,
				'child_total'  => $total,
				'attempts'     => 0,
				'retry_after'  => null,
				'message'      => $message,
			)
		);
	}

	/**
	 * Convert and write one product or variation.
	 *
	 * @param Job                  $job      Job.
	 * @param array                $item     Item row.
	 * @param \WC_Product          $product  Product or variation.
	 * @param int|null             $revision Expected revision override.
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

		$this->lock->release();

		$job = $this->jobs->get( $job->id() );

		if ( ! $job ) {
			return;
		}

		$counters = $job->counters();

		$this->mark_mode_synced( $job, $status );

		$this->logger->info(
			'Job finished.',
			array(
				'status'  => $status,
				'type'    => $job->type(),
				'rate'    => $job->rate(),
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

		$scope         = $this->products->normalize_scope( $job->scope() );
		$full          = $this->products->normalize_scope( Product_Repository::default_scope() );
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

		$this->lock->release();
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

		switch ( $job->phase() ) {
			case Job::PHASE_DISCOVER:
				$this->scheduler->enqueue( self::HOOK_DISCOVER, array( $job_id ) );
				break;
			case Job::PHASE_FINALIZE:
				$this->scheduler->enqueue( self::HOOK_FINALIZE, array( $job_id ) );
				break;
			default:
				$this->scheduler->enqueue( self::HOOK_PROCESS, array( $job_id ) );
				break;
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

		$this->lock->release();

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

			$this->scheduler->enqueue( self::HOOK_PROCESS, array( $job_id ) );

			return true;
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

		$this->scheduler->enqueue( self::HOOK_PROCESS, array( $job_id ) );

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
				__( 'There is no previous exchange rate to restore.', 'usd-to-toman-price-sync-for-woocommerce' )
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
			return new \WP_Error( 'usdtf_no_products', __( 'No products were selected.', 'usd-to-toman-price-sync-for-woocommerce' ) );
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
			foreach ( $this->jobs->query( array( 'status' => $status, 'limit' => 10 ) ) as $job ) {
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
						$this->scheduler->enqueue( self::HOOK_DISCOVER, array( $job->id() ), 0, false );
						break;
					case Job::PHASE_FINALIZE:
						$this->scheduler->enqueue( self::HOOK_FINALIZE, array( $job->id() ), 0, false );
						break;
					default:
						$this->scheduler->enqueue( self::HOOK_PROCESS, array( $job->id() ), 0, false );
						break;
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

		foreach ( $this->jobs->query( array( 'status' => Job::STATUS_RUNNING, 'limit' => 20 ) ) as $job ) {
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
				$this->lock->release();

				continue;
			}

			$resumes[ $job_id ] = $count + 1;
			update_option( 'usdtf_stale_resumes', $resumes, false );

			$this->logger->warning( 'Recovering a job whose worker stopped.', array( 'auto_resume' => $count + 1 ), $job_id );

			$this->lock->release();
			$this->resume( $job_id );

			++$recovered;
		}

		// Jobs that were left half started: queue their first step again.
		foreach ( $this->jobs->query( array( 'status' => Job::STATUS_QUEUED, 'limit' => 20 ) ) as $job ) {
			if ( ! $this->scheduler->has_pending( self::HOOK_DISCOVER, array( $job->id() ) ) ) {
				$this->scheduler->enqueue( self::HOOK_DISCOVER, array( $job->id() ) );
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
		$rate          = $this->rates->get_rate();
		$pending       = $this->rates->get_pending();
		$active        = $this->jobs->active_write_job();
		$last          = $this->jobs->last_finished_job();
		$last_preview  = $this->jobs->query( array( 'limit' => 1, 'status' => Job::STATUS_COMPLETED ) );

		$preview_job = null;

		foreach ( $this->jobs->query( array( 'limit' => 5 ) ) as $job ) {
			if ( Job::TYPE_PREVIEW === $job->type() ) {
				$preview_job = $job;
				break;
			}
		}

		unset( $last_preview );

		$scope = Product_Repository::default_scope();

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
			'lock'                => $this->lock->status(),
			'scheduler'           => $this->scheduler->health(),
			'settings'            => array(
				'batch_size'        => $this->settings->batch_size(),
				'time_budget'       => $this->settings->time_budget(),
				'rounding'          => $this->settings->get( 'rounding' ),
				'increment'         => (float) $this->settings->get( 'increment' ),
				'decimals'          => (int) $this->settings->get( 'decimals' ),
				'threshold'         => (float) $this->settings->get( 'rate_change_threshold' ),
				'retry_limit'       => (int) $this->settings->get( 'retry_limit' ),
				'retention_days'    => (int) $this->settings->get( 'retention_days' ),
				'display_toman'     => (bool) $this->settings->get( 'display_toman' ),
				'display_toman_cart' => (bool) $this->settings->get( 'display_toman_cart' ),
				'persian_digits'    => (bool) $this->settings->get( 'persian_digits' ),
				'toman_suffix'      => (string) $this->settings->get( 'toman_suffix' ),
				'auto_manage'       => (bool) $this->settings->get( 'auto_manage_new_products' ),
			),
		);
	}
}
