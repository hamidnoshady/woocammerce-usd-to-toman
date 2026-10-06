<?php
/**
 * Keeps CLI-driven scenarios deterministic.
 *
 * Scenarios that run worker phases directly in a CLI process must not have
 * the background queue execute the same job concurrently through the test web
 * server. Action Scheduler's async runner is disabled, and the plugin's own
 * worker loopback is answered locally. The dedicated real-HTTP scenario runs
 * its jobs inside the web server, so it still exercises both paths exactly
 * like production. The loopback diagnostic uses a different request and is
 * left untouched.
 *
 * @package USDTF
 */

add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );

add_filter(
	'pre_http_request',
	static function ( $preempt, $request ) {
		if ( isset( $request['body']['action'] ) && \USDTF\Scheduler::LOOPBACK_ACTION === $request['body']['action'] ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		return $preempt;
	},
	1,
	2
);
