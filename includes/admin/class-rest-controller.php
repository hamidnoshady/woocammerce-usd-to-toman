<?php
/**
 * REST API used by the admin screens.
 *
 * Every mutation goes through one of these routes, so capability checks,
 * nonces and validation are enforced in a single place.
 *
 * @package USDTF
 */

namespace USDTF\Admin;

use USDTF\Calculator;
use USDTF\Capabilities;
use USDTF\Health;
use USDTF\Job;
use USDTF\Job_Repository;
use USDTF\Product_Pricing;
use USDTF\Product_Repository;
use USDTF\Rate_Guard;
use USDTF\Rate_Repository;
use USDTF\Scheduler;
use USDTF\Settings;
use USDTF\Sync_Runner;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's REST routes.
 */
final class Rest_Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'usdtf/v1';

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Rate storage.
	 *
	 * @var Rate_Repository
	 */
	private $rates;

	/**
	 * Synchronization engine.
	 *
	 * @var Sync_Runner
	 */
	private $runner;

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
	 * Diagnostics.
	 *
	 * @var Health
	 */
	private $health;

	/**
	 * Pricing model.
	 *
	 * @var Product_Pricing
	 */
	private $pricing;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Scheduler          $scheduler Scheduler.
	 * @param Rate_Repository    $rates     Rate storage.
	 * @param Sync_Runner        $runner    Synchronization engine.
	 * @param Job_Repository     $jobs      Job storage.
	 * @param Product_Repository $products Product discovery.
	 * @param Health             $health    Diagnostics.
	 * @param Product_Pricing    $pricing   Pricing model.
	 * @param Settings           $settings  Settings.
	 */
	public function __construct( Scheduler $scheduler, Rate_Repository $rates, Sync_Runner $runner, Job_Repository $jobs, Product_Repository $products, Health $health, Product_Pricing $pricing, Settings $settings ) {
		$this->scheduler = $scheduler;
		$this->rates     = $rates;
		$this->runner    = $runner;
		$this->jobs      = $jobs;
		$this->products  = $products;
		$this->health    = $health;
		$this->pricing   = $pricing;
		$this->settings  = $settings;
	}

	/**
	 * Hook the REST API.
	 *
	 * REST requests are served on their own request where is_admin() is false,
	 * so this is registered for every request rather than from the admin
	 * screen.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$permission = array( $this, 'can_manage' );

		register_rest_route(
			self::NAMESPACE_V1,
			'/state',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_state' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/rate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_rate' ),
				'permission_callback' => $permission,
				'args'                => array(
					'rate'         => array(
						'type'     => array( 'string', 'number' ),
						'required' => true,
					),
					'confirmed'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'confirm_text' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_preview' ),
				'permission_callback' => $permission,
				'args'                => array(
					'scope' => array(
						'type'    => 'object',
						'default' => array(),
					),
					'rate'  => array(
						'type'    => array( 'string', 'number' ),
						'default' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/update',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_update' ),
				'permission_callback' => $permission,
				'args'                => array(
					'scope' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/rollback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rollback' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/recalculate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'recalculate' ),
				'permission_callback' => $permission,
				'args'                => array(
					'ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_jobs' ),
				'permission_callback' => $permission,
				'args'                => array(
					'limit'  => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'status' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id'             => array( 'type' => 'integer' ),
					'items_page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'items_status'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'items_per_page' => array(
						'type'    => 'integer',
						'default' => 25,
					),
				),
			)
		);

		foreach ( array( 'pause', 'resume', 'cancel', 'retry-failed', 'recalculate-conflicts' ) as $action ) {
			register_rest_route(
				self::NAMESPACE_V1,
				'/jobs/(?P<id>\d+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'job_action' ),
					'permission_callback' => $permission,
					'args'                => array(
						'id'     => array( 'type' => 'integer' ),
						'action' => array(
							'type'    => 'string',
							'default' => $action,
						),
					),
				)
			);
		}

		register_rest_route(
			self::NAMESPACE_V1,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/health/loopback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'loopback_test' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search_products' ),
				'permission_callback' => $permission,
				'args'                => array(
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return Capabilities::current_user_can();
	}

	/**
	 * Helper: collect the requested scope.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function scope_from_request( $request ) {
		$scope = $request->get_param( 'scope' );
		$scope = is_array( $scope ) ? $scope : array();

		return $this->products->normalize_scope( $scope );
	}

	/**
	 * GET /state
	 *
	 * @return \WP_REST_Response
	 */
	public function get_state() {
		$state = $this->runner->state();

		$state['rate_history'] = $this->format_history( 10 );
		$state['jobs']         = array_map(
			static function ( Job $job ) {
				return $job->to_array();
			},
			$this->jobs->query( array( 'limit' => 5 ) )
		);
		$state['checks']       = $this->health->checks();
		$state['can']          = array(
			'manage'   => Capabilities::current_user_can(),
			'rollback' => $this->rates->previous_rate() > 0,
		);

		return rest_ensure_response( $state );
	}

	/**
	 * POST /rate
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_rate( $request ) {
		$rate         = $request->get_param( 'rate' );
		$confirmed    = (bool) $request->get_param( 'confirmed' );
		$confirm_text = (string) $request->get_param( 'confirm_text' );

		if ( $confirmed && ! Rate_Guard::confirm_phrase_matches( $confirm_text ) ) {
			return new \WP_Error(
				'usdtf_confirmation_required',
				sprintf(
					/* translators: %s: the word the admin has to type. */
					__( 'Type %s in the confirmation field to confirm a large rate change.', 'usd-to-toman-price-sync-for-woocommerce' ),
					Rate_Guard::confirmation_phrase()
				),
				array( 'status' => 400 )
			);
		}

		$result = $this->rates->submit_rate( $rate, $confirmed );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );

			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /preview
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_preview( $request ) {
		$scope = $this->scope_from_request( $request );

		if ( ! $scope['label'] ) {
			$scope['label'] = $this->products->scope_label( $scope );
		}

		$rate   = (float) $request->get_param( 'rate' );
		$result = $this->runner->preview(
			array(
				'scope' => $scope,
				'rate'  => $rate > 0 ? $rate : 0,
				'note'  => __( 'Dry run.', 'usd-to-toman-price-sync-for-woocommerce' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /update
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_update( $request ) {
		$scope = $this->scope_from_request( $request );

		if ( ! $scope['label'] ) {
			$scope['label'] = $this->products->scope_label( $scope );
		}

		$result = $this->runner->create_job(
			array(
				'type'  => Job::TYPE_SYNC,
				'scope' => $scope,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /rollback
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rollback() {
		$result = $this->runner->rollback();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /recalculate
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function recalculate( $request ) {
		$ids = $request->get_param( 'ids' );

		$result = $this->runner->recalculate( is_array( $ids ) ? $ids : array() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /jobs
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_jobs( $request ) {
		$jobs = $this->jobs->query(
			array(
				'limit'  => (int) $request->get_param( 'limit' ),
				'status' => (string) $request->get_param( 'status' ),
			)
		);

		return rest_ensure_response(
			array_map(
				static function ( Job $job ) {
					return $job->to_array();
				},
				$jobs
			)
		);
	}

	/**
	 * GET /jobs/<id>
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_job( $request ) {
		$job = $this->jobs->get( (int) $request->get_param( 'id' ) );

		if ( ! $job ) {
			return new \WP_Error( 'usdtf_job_not_found', __( 'The job could not be found.', 'usd-to-toman-price-sync-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$page = max( 1, (int) $request->get_param( 'items_page' ) );

		$items = $this->jobs->items(
			$job->id(),
			array(
				'status' => (string) $request->get_param( 'items_status' ),
				'page'   => $page,
				'limit'  => min( 200, max( 1, (int) $request->get_param( 'items_per_page' ) ) ),
			)
		);

		$data           = $job->to_array();
		$data['items']  = array_map( array( $this, 'format_item' ), $items );
		$data['totals'] = $this->jobs->item_totals( $job->id() );
		$data['page']   = $page;

		return rest_ensure_response( $data );
	}

	/**
	 * POST /jobs/<id>/<action>
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function job_action( $request ) {
		$job_id = (int) $request->get_param( 'id' );
		$action = (string) $request->get_param( 'action' );

		switch ( $action ) {
			case 'pause':
				$done = $this->runner->pause( $job_id );
				break;
			case 'resume':
				$done = $this->runner->resume( $job_id );
				break;
			case 'cancel':
				$done = $this->runner->cancel( $job_id );
				break;
			case 'retry-failed':
				$done = $this->runner->retry_failed( $job_id ) > 0;
				break;
			case 'recalculate-conflicts':
				$done = $this->runner->recalculate_conflicts( $job_id ) > 0;
				break;
			default:
				return new \WP_Error( 'usdtf_unknown_action', __( 'Unknown job action.', 'usd-to-toman-price-sync-for-woocommerce' ), array( 'status' => 400 ) );
		}

		if ( ! $done ) {
			return new \WP_Error(
				'usdtf_action_failed',
				__( 'The action could not be applied to this job. Reload the page and try again.', 'usd-to-toman-price-sync-for-woocommerce' ),
				array( 'status' => 409 )
			);
		}

		$job = $this->jobs->get( $job_id );

		return rest_ensure_response(
			array(
				'job'     => $job ? $job->to_array() : null,
				'action'  => $action,
				'message' => __( 'Done.', 'usd-to-toman-price-sync-for-woocommerce' ),
			)
		);
	}

	/**
	 * GET /health
	 *
	 * @return \WP_REST_Response
	 */
	public function get_health() {
		return rest_ensure_response(
			array(
				'checks'    => $this->health->checks(),
				'scheduler' => $this->scheduler->health(),
				'lock'      => usdtf_plugin()->lock()->status(),
			)
		);
	}

	/**
	 * POST /health/loopback
	 *
	 * @return \WP_REST_Response
	 */
	public function loopback_test() {
		return rest_ensure_response( $this->health->loopback_test() );
	}

	/**
	 * GET /products – lightweight product search for the scope picker.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function search_products( $request ) {
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );

		$results = array();

		$query = new \WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				's'              => $search,
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$source = $this->pricing->get_source( $product_id );

			$results[] = array(
				'id'       => (int) $product_id,
				'name'     => $product->get_name(),
				'type'     => $product->get_type(),
				'mode'     => $this->pricing->get_mode( $product_id ),
				'toman'    => null === $source['regular'] ? '' : Calculator::format_toman( $source['regular'] ),
				'currency' => (string) $this->settings->get( 'currency_mode' ),
			);
		}

		return rest_ensure_response( $results );
	}

	/**
	 * Format a job item for the API.
	 *
	 * @param array $item Item row.
	 * @return array
	 */
	private function format_item( array $item ) {
		$product    = wc_get_product( (int) $item['object_id'] );
		$product_id = (int) $item['object_id'];

		return array(
			'id'            => (int) $item['id'],
			'object_id'     => $product_id,
			'parent_id'     => (int) $item['parent_id'],
			'object_type'   => (string) $item['object_type'],
			'name'          => $product ? $product->get_name() : sprintf(
				/* translators: %d: product ID. */
				__( 'Product #%d', 'usd-to-toman-price-sync-for-woocommerce' ),
				$product_id
			),
			'edit_link'     => (string) get_edit_post_link( $product_id, 'raw' ),
			'status'        => (string) $item['status'],
			'toman_regular' => null === $item['toman_regular'] ? null : (float) $item['toman_regular'],
			'toman_sale'    => null === $item['toman_sale'] ? null : (float) $item['toman_sale'],
			'old_regular'   => null === $item['old_regular'] ? null : (float) $item['old_regular'],
			'old_sale'      => null === $item['old_sale'] ? null : (float) $item['old_sale'],
			'new_regular'   => null === $item['new_regular'] ? null : (float) $item['new_regular'],
			'new_sale'      => null === $item['new_sale'] ? null : (float) $item['new_sale'],
			'attempts'      => (int) $item['attempts'],
			'message'       => (string) $item['message'],
			'child_cursor'  => (int) $item['child_cursor'],
			'child_total'   => (int) $item['child_total'],
			'mode'          => $this->pricing->get_mode( $product_id ),
			'revision'      => $this->pricing->get_revision( $product_id ),
		);
	}

	/**
	 * Format rate history rows.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	private function format_history( $limit ) {
		$rows = array();

		foreach ( $this->rates->history( $limit ) as $row ) {
			$rows[] = array(
				'id'               => (int) $row['id'],
				'rate'             => (float) $row['rate'],
				'previous_rate'    => null === $row['previous_rate'] ? null : (float) $row['previous_rate'],
				'change_percent'   => null === $row['change_percent'] ? null : (float) $row['change_percent'],
				'source'           => (string) $row['source'],
				'user'             => Job::user_display_name( (int) $row['user_id'] ),
				'job_id'           => (int) $row['job_id'],
				'status'           => (string) $row['status'],
				'note'             => null === $row['note'] ? '' : (string) $row['note'],
				'products_checked' => (int) $row['products_checked'],
				'products_changed' => (int) $row['products_changed'],
				'products_failed'  => (int) $row['products_failed'],
				'conflicts'        => (int) $row['conflicts'],
				'created_at'       => (string) $row['created_at'],
				'finished_at'      => null === $row['finished_at'] ? null : (string) $row['finished_at'],
			);
		}

		return $rows;
	}
}
