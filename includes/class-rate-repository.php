<?php
/**
 * Manual exchange rate storage and history.
 *
 * @package USDTF
 */

namespace USDTF;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the active rate, the pending rate that awaits confirmation and the
 * permanent rate history.
 */
final class Rate_Repository {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * The active manual rate (Toman per 1 USD).
	 *
	 * @return float 0 when no rate is configured yet.
	 */
	public function get_rate() {
		return (float) get_option( Settings::OPTION_RATE, 0 );
	}

	/**
	 * Whether a usable rate is configured.
	 *
	 * @return bool
	 */
	public function has_rate() {
		return $this->get_rate() > 0;
	}

	/**
	 * A rate that is waiting for explicit confirmation.
	 *
	 * @return array|null
	 */
	public function get_pending() {
		$pending = get_option( Settings::OPTION_PENDING_RATE, array() );

		if ( ! is_array( $pending ) || empty( $pending['rate'] ) ) {
			return null;
		}

		return wp_parse_args(
			$pending,
			array(
				'rate'           => 0.0,
				'previous_rate'  => $this->get_rate(),
				'change_percent' => null,
				'user_id'        => 0,
				'created_at'     => 0,
			)
		);
	}

	/**
	 * Remember a rate that needs explicit confirmation.
	 *
	 * @param array $assessed Result of Rate_Guard::assess().
	 * @return void
	 */
	public function set_pending( array $assessed ) {
		update_option(
			Settings::OPTION_PENDING_RATE,
			array(
				'rate'           => (float) $assessed['rate'],
				'previous_rate'  => $this->get_rate(),
				'change_percent' => $assessed['change_percent'],
				'user_id'        => get_current_user_id(),
				'created_at'     => time(),
			),
			false
		);
	}

	/**
	 * Forget the pending rate.
	 *
	 * @return void
	 */
	public function clear_pending() {
		delete_option( Settings::OPTION_PENDING_RATE );
	}

	/**
	 * The rate that was active before the current one.
	 *
	 * @return float
	 */
	public function previous_rate() {
		$table = Database::rates_table();

		if ( ! Database::table_exists( $table ) ) {
			return 0.0;
		}

		// Only real rate changes carry a previous rate. Job summaries are stored
		// in the same table for the audit trail, so they must be ignored here.
		$value = Database::get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal and the query has no user input.
			"SELECT previous_rate FROM `{$table}` WHERE previous_rate IS NOT NULL AND previous_rate > 0 ORDER BY id DESC LIMIT 1"
		);

		return null === $value ? 0.0 : (float) $value;
	}

	/**
	 * Store a new active rate and log it in the permanent history.
	 *
	 * @param float $rate New rate.
	 * @param array $args {
	 *     Optional. Arguments.
	 *
	 *     @type string $source Source label (admin|rollback).
	 *     @type string $note   Note shown in the history.
	 *     @type int    $user_id User ID.
	 * }
	 * @return array History row data.
	 */
	public function save_rate( $rate, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'source'  => 'admin',
				'note'    => '',
				'user_id' => get_current_user_id(),
			)
		);

		$previous = $this->get_rate();
		$rate     = (float) $rate;

		update_option( Settings::OPTION_RATE, (string) $rate, false );
		$this->clear_pending();

		$change = $previous > 0 ? Calculator::percent_change( $previous, $rate ) : null;

		$row_id = Database::insert(
			Database::rates_table(),
			array(
				'rate'           => $rate,
				'previous_rate'  => $previous > 0 ? $previous : null,
				'change_percent' => null === $change ? null : $change,
				'source'         => (string) $args['source'],
				'user_id'        => (int) $args['user_id'],
				'job_id'         => 0,
				'status'         => 'active',
				'note'           => '' === $args['note'] ? null : $args['note'],
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%f', '%f', '%f', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		$this->logger->info(
			'Exchange rate saved.',
			array(
				'previous'  => $previous,
				'rate'      => $rate,
				'change_pc' => $change,
				'source'    => $args['source'],
				'row'       => $row_id,
			)
		);

		return array(
			'id'             => $row_id,
			'rate'           => $rate,
			'previous_rate'  => $previous,
			'change_percent' => $change,
		);
	}

	/**
	 * Rate history, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array[]
	 */
	public function history( $limit = 20 ) {
		$table = Database::rates_table();

		if ( ! Database::table_exists( $table ) ) {
			return array();
		}

		global $wpdb;

		return Database::get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d",
				max( 1, (int) $limit )
			)
		);
	}

	/**
	 * Attach the results of a job to the history row of the rate it used.
	 *
	 * @param Job    $job        Job.
	 * @param string $status     Completion status.
	 * @param string $note       Optional note.
	 * @return void
	 */
	public function log_job_summary( Job $job, $status, $note = '' ) {
		$table = Database::rates_table();

		if ( ! Database::table_exists( $table ) ) {
			return;
		}

		global $wpdb;

		$counters = $job->counters();

		$existing = Database::get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				"SELECT id FROM `{$table}` WHERE job_id = %d LIMIT 1",
				$job->id()
			)
		);

		$data = array(
			'rate'             => $job->rate(),
			'job_id'           => $job->id(),
			'products_checked' => $counters['processed'],
			'products_changed' => $counters['changed'],
			'products_failed'  => $counters['failed'],
			'conflicts'        => $counters['conflicts'],
			'status'           => (string) $status,
			'finished_at'      => current_time( 'mysql', true ),
		);

		if ( $note ) {
			$data['note'] = $note;
		}

		if ( $existing ) {
			$clean   = array();
			$formats = array();

			foreach ( $data as $key => $value ) {
				$clean[ $key ] = $value;
				$formats[]     = in_array( $key, array( 'products_checked', 'products_changed', 'products_failed', 'conflicts', 'job_id' ), true ) ? '%d' : ( 'rate' === $key ? '%f' : '%s' );
			}

			Database::update( $table, $clean, array( 'id' => (int) $existing ), $formats, array( '%d' ) );

			return;
		}

		Database::insert(
			$table,
			array(
				'rate'             => (float) $data['rate'],
				'previous_rate'    => null,
				'change_percent'   => null,
				'source'           => Job::TYPE_ROLLBACK === $job->type() ? 'rollback' : 'sync',
				'user_id'          => (int) $job->data['user_id'],
				'job_id'           => $job->id(),
				'products_checked' => (int) $data['products_checked'],
				'products_changed' => (int) $data['products_changed'],
				'products_failed'  => (int) $data['products_failed'],
				'conflicts'        => (int) $data['conflicts'],
				'status'           => (string) $status,
				'note'             => '' === $note ? null : $note,
				'created_at'       => current_time( 'mysql', true ),
				'finished_at'      => current_time( 'mysql', true ),
			),
			array( '%f', '%f', '%f', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Assess a candidate rate and, when it is safe, store it.
	 *
	 * A large move is never stored implicitly: it is kept as "pending" until the
	 * admin confirms it explicitly.
	 *
	 * @param mixed $raw          Raw rate input.
	 * @param bool  $confirmed    Whether the admin already confirmed a large move.
	 * @param float $sample_ratio Share of managed products to sample for the estimate.
	 * @return array|\WP_Error Result data or error.
	 */
	public function submit_rate( $raw, $confirmed = false, $sample_ratio = 0.05 ) {
		$current   = $this->get_rate();
		$threshold = (float) $this->settings->get( 'rate_change_threshold' );
		$assessed  = Rate_Guard::assess( $raw, $current, $threshold );

		if ( ! $assessed['valid'] ) {
			return new \WP_Error(
				'usdtf_invalid_rate',
				$assessed['message'],
				array(
					'status'         => 400,
					'code'           => $assessed['code'],
					'change_percent' => null,
				)
			);
		}

		if ( Rate_Guard::CODE_UNCHANGED === $assessed['code'] ) {
			return array(
				'saved'                 => false,
				'requires_confirmation' => false,
				'rate'                  => $current,
				'previous_rate'         => $current,
				'change_percent'        => 0.0,
				'affected'              => 0,
				'message'               => $assessed['message'],
			);
		}

		if ( $assessed['requires_confirmation'] && ! $confirmed ) {
			$this->set_pending( $assessed );

			return array(
				'saved'                 => false,
				'requires_confirmation' => true,
				'rate'                  => $assessed['rate'],
				'previous_rate'         => $current,
				'change_percent'        => $assessed['change_percent'],
				'affected'              => $this->estimate_affected( $assessed['rate'], $sample_ratio ),
				'message'               => $assessed['message'],
			);
		}

		if ( $assessed['requires_confirmation'] && $confirmed ) {
			$pending = $this->get_pending();

			// Only the exact rate that was shown for confirmation may be stored.
			if ( $pending && abs( (float) $pending['rate'] - (float) $assessed['rate'] ) > 0.000001 ) {
				return new \WP_Error(
					'usdtf_confirmation_mismatch',
					__( 'The rate changed after the confirmation prompt. Please review the new rate and confirm again.', 'usd-to-toman-price-sync-for-woocommerce' ),
					array( 'status' => 400 )
				);
			}
		}

		$saved = $this->save_rate( $assessed['rate'] );

		return array(
			'saved'                 => true,
			'requires_confirmation' => false,
			'rate'                  => $saved['rate'],
			'previous_rate'         => $saved['previous_rate'],
			'change_percent'        => $saved['change_percent'],
			'affected'              => $this->estimate_affected( $saved['rate'], $sample_ratio ),
			'message'               => __( 'The exchange rate was saved. Product prices are updated only when you run an update.', 'usd-to-toman-price-sync-for-woocommerce' ),
		);
	}

	/**
	 * Estimate how many managed products would get a different price.
	 *
	 * A bounded sample is used so the estimate stays cheap on large catalogs.
	 *
	 * @param float $rate         Candidate rate.
	 * @param float $sample_ratio Share of the catalog to sample (0.01 - 1).
	 * @return int Estimated products affected.
	 */
	public function estimate_affected( $rate, $sample_ratio = 0.05 ) {
		$rate = (float) $rate;

		if ( $rate <= 0 ) {
			return 0;
		}

		$sample_ratio = max( 0.01, min( 1.0, (float) $sample_ratio ) );

		$pricing = usdtf_plugin()->pricing();
		$repo    = usdtf_plugin()->products();

		$total = $repo->count(
			array(
				'mode'  => Product_Pricing::MODE_MANAGED,
				'label' => '',
			),
			'product'
		);

		if ( 0 === $total ) {
			return 0;
		}

		$sample_size = (int) max( 20, min( 500, ceil( $total * $sample_ratio ) ) );

		$ids = $repo->get_ids(
			array(
				'mode'  => Product_Pricing::MODE_MANAGED,
				'label' => '',
			),
			1,
			$sample_size,
			'product'
		);

		if ( ! $ids ) {
			return 0;
		}

		$settings = $this->settings->rounding_args();
		$affected = 0;
		$checked  = 0;

		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			++$checked;

			$item = $pricing->get_snapshot( $product );

			foreach ( array( 'regular', 'sale' ) as $which ) {
				$source = 'regular' === $which ? $item['source_regular'] : $item['source_sale'];

				if ( null === $source ) {
					continue;
				}

				$new = Calculator::from_toman( $source, $rate, $settings );
				$old = 'regular' === $which ? $item['derived_regular'] : $item['derived_sale'];

				if ( ! Calculator::prices_equal( $new, $old, (int) $settings['decimals'] ) ) {
					++$affected;
					break;
				}
			}
		}

		if ( 0 === $checked ) {
			return 0;
		}

		return (int) round( ( $affected / $checked ) * $total );
	}
}
