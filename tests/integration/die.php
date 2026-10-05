<?php
/**
 * Exception used to turn wp_die() into a catchable failure.
 *
 * @package USDTF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the suite's wp_die() handler.
 */
class USDTF_IT_Die extends \Exception {

	/**
	 * Response status requested by wp_die().
	 *
	 * @var int
	 */
	public $die_status = 0;

	/**
	 * Die title.
	 *
	 * @var string
	 */
	public $die_title = '';

	/**
	 * Constructor.
	 *
	 * @param string $message Message.
	 * @param string $title   Title.
	 * @param array  $args    wp_die() arguments.
	 */
	public function __construct( $message, $title = '', $args = array() ) {
		parent::__construct( is_string( $message ) ? $message : 'WP_Error' );

		$this->die_status = isset( $args['response'] ) ? (int) $args['response'] : 0;
		$this->die_title  = (string) $title;
	}
}
