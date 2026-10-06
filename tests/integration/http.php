<?php
/**
 * Integration scenario: the admin REST API over real HTTP.
 *
 * These scenarios need a running web server for the test site
 * (php -S 127.0.0.1:8888 <wp>/index.php in CI). Without one they skip, so the
 * suite still runs everywhere; with one they prove that the routes are
 * reachable through a real HTTP round trip, including the exact rest_no_route
 * error an admin would see after a broken upgrade.
 *
 * @package USDTF
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test script.

use USDTF\Job;

global $runner, $rates, $settings;

$usdtf_http_probe = wp_remote_get( site_url( '/' ), array( 'timeout' => 5 ) );

if ( is_wp_error( $usdtf_http_probe ) || 200 !== (int) wp_remote_retrieve_response_code( $usdtf_http_probe ) ) {
	echo "skip - the HTTP scenarios need a running web server (php -S 127.0.0.1:8888 <wp>/index.php)\n";

	return;
}

if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	echo "skip - this WordPress version has no application passwords\n";

	return;
}

/**
 * Issue an authenticated or anonymous REST request over real HTTP.
 *
 * @param string      $method  HTTP method.
 * @param string      $path    Path inside the plugin namespace.
 * @param array|null  $body    JSON body, null for none.
 * @param string|null $authorization Authorization header value, null for anonymous.
 * @param array       $query   Query parameters, added the way the site URL is built.
 * @return array Response pieces: code, body, json.
 */
function usdtf_it_http( $method, $path, $body = null, $authorization = null, array $query = array() ) {
	$headers = array( 'Content-Type' => 'application/json' );

	if ( null !== $authorization ) {
		$headers['Authorization'] = $authorization;
	}

	$url = rest_url( 'usdtf/v1' . $path );

	if ( $query ) {
		// The site may use plain permalinks, in which case rest_url() already
		// carries ?rest_route=… and a glued-on query string would break the route.
		$url = add_query_arg( $query, $url );
	}

	$response = wp_remote_request(
		$url,
		array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => $headers,
			'body'    => null === $body ? null : wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array(
			'code' => 0,
			'body' => $response->get_error_message(),
			'json' => array(),
		);
	}

	$raw  = (string) wp_remote_retrieve_body( $response );
	$json = json_decode( $raw, true );

	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => $raw,
		'json' => is_array( $json ) ? $json : array(),
	);
}

/**
 * Wait for a real HTTP-started background job to finish without manually
 * stepping its worker. This is the regression test for jobs that used to sit
 * at 0/0 until another admin page request woke Action Scheduler.
 *
 * @param int    $job_id        Job ID.
 * @param string $authorization Authorization header.
 * @param int    $timeout       Timeout in seconds.
 * @return array Last job payload.
 */
function usdtf_it_http_wait_job( $job_id, $authorization, $timeout = 30 ) {
	$deadline = microtime( true ) + max( 1, (int) $timeout );
	$last     = array();

	do {
		$response = usdtf_it_http( 'GET', '/jobs/' . (int) $job_id, null, $authorization, array( '_usdtf' => (string) microtime( true ) ) );

		if ( 200 === $response['code'] && is_array( $response['json'] ) ) {
			$last = $response['json'];

			if ( empty( $last['is_active'] ) ) {
				return $last;
			}
		}

		usleep( 250000 );
	} while ( microtime( true ) < $deadline );

	return $last;
}

// ---------------------------------------------------------------------------
// 47. The admin REST namespace answers real HTTP requests.
// ---------------------------------------------------------------------------
usdtf_it_reset_plugin_state();
usdtf_it_delete_products();

$rates->save_rate( 270000 );
usdtf_it_make_simple_product( 'HTTP product', '5000000' );

// Application passwords are the only cookie-less REST authentication WordPress
// ships; the availability filter makes them work on plain local HTTP.
add_filter( 'wp_is_application_passwords_available', '__return_true' );

$usdtf_http_user     = wp_get_current_user();
$usdtf_http_password = WP_Application_Passwords::create_new_application_password(
	$usdtf_http_user->ID,
	array( 'name' => 'usdtf-integration' )
);

usdtf_it_assert( is_array( $usdtf_http_password ) && is_string( $usdtf_http_password[0] ), 'an application password must be issued for the admin user' );

$usdtf_http_item   = is_array( $usdtf_http_password ) ? $usdtf_http_password[1] : null;
$usdtf_http_header = 'Basic ' . base64_encode( $usdtf_http_user->user_login . ':' . $usdtf_http_password[0] );

try {
	// Authenticated state read.
	$state = usdtf_it_http( 'GET', '/state', null, $usdtf_http_header );

	usdtf_it_assert_same( 200, $state['code'], 'GET /state over real HTTP must answer 200, got ' . $state['code'] . ': ' . $state['body'] );
	usdtf_it_assert( isset( $state['json']['rate'] ), 'the state payload must carry the rate' );
	usdtf_it_assert( isset( $state['json']['preview_required'] ), 'the state payload must expose the dry run rule' );

	// Anonymous state read is refused.
	$anonymous = usdtf_it_http( 'GET', '/state' );

	usdtf_it_assert( in_array( $anonymous['code'], array( 401, 403 ), true ), 'GET /state without credentials must be refused, got ' . $anonymous['code'] );

	// A wrong application password is refused.
	$wrong = usdtf_it_http( 'GET', '/state', null, 'Basic ' . base64_encode( $usdtf_http_user->user_login . ':wrong-password' ) );

	usdtf_it_assert( in_array( $wrong['code'], array( 401, 403 ), true ), 'GET /state with a wrong password must be refused, got ' . $wrong['code'] );

	// A route that does not exist answers with the documented rest_no_route
	// error — this is the exact response shape behind the admin rest_no_route
	// reports, so the client can rely on the code.
	$no_route = usdtf_it_http( 'GET', '/does-not-exist', null, $usdtf_http_header );

	usdtf_it_assert_same( 404, $no_route['code'], 'an unknown route must answer 404 over real HTTP' );
	usdtf_it_assert_same( 'rest_no_route', (string) $no_route['json']['code'], 'the unknown route must answer with the rest_no_route error code' );

	// The dry-run-first rule over real HTTP: without a dry run the update is
	// refused with the documented error, after a matching dry run it runs.
	$settings->update( array( 'require_preview' => true ) );

	$rejected = usdtf_it_http( 'POST', '/update', array( 'scope' => array() ), $usdtf_http_header );

	usdtf_it_assert_same( 428, $rejected['code'], 'POST /update without a dry run must answer 428 over real HTTP, got ' . $rejected['code'] . ': ' . $rejected['body'] );
	usdtf_it_assert_same( 'usdtf_preview_required', (string) $rejected['json']['code'], 'the rejection must carry the documented error code' );

	$preview = usdtf_it_http( 'POST', '/preview', array( 'scope' => array() ), $usdtf_http_header );

	usdtf_it_assert_same( 200, $preview['code'], 'POST /preview over real HTTP must answer 200, got ' . $preview['code'] . ': ' . $preview['body'] );
	usdtf_it_assert( ! empty( $preview['json']['id'] ), 'the preview response must carry the job' );

	$preview_finished = usdtf_it_http_wait_job( (int) $preview['json']['id'], $usdtf_http_header );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, isset( $preview_finished['status'] ) ? $preview_finished['status'] : '', 'the preview started over REST must finish through the background queue without an admin refresh' );

	$update = usdtf_it_http( 'POST', '/update', array( 'scope' => array() ), $usdtf_http_header );

	usdtf_it_assert_same( 200, $update['code'], 'POST /update over real HTTP must answer 200, got ' . $update['code'] . ': ' . $update['body'] );

	$update_finished = usdtf_it_http_wait_job( (int) $update['json']['id'], $usdtf_http_header );
	usdtf_it_assert_same( Job::STATUS_COMPLETED, isset( $update_finished['status'] ) ? $update_finished['status'] : '', 'the update started over HTTP must complete without another admin page request' );
	usdtf_it_assert( isset( $update_finished['counters']['processed'] ) && (int) $update_finished['counters']['processed'] > 0, 'live job polling must observe processed products before/at completion' );

	$job_state = usdtf_it_http( 'GET', '/jobs/' . (int) $update['json']['id'], null, $usdtf_http_header, array( '_usdtf' => (string) microtime( true ) ) );
	usdtf_it_assert_same( 200, $job_state['code'], 'the cache-busted live job route must remain reachable' );

	// The product search endpoint answers over HTTP too.
	$search = usdtf_it_http( 'GET', '/products', null, $usdtf_http_header, array( 'search' => 'HTTP' ) );

	usdtf_it_assert_same( 200, $search['code'], 'GET /products over real HTTP must answer 200, got ' . $search['code'] . ' [' . substr( $search['body'], 0, 200 ) . ']' );
	usdtf_it_assert( false !== strpos( $search['body'], 'HTTP product' ), 'the product search must find the product' );
} finally {
	if ( $usdtf_http_item ) {
		WP_Application_Passwords::delete_application_password( $usdtf_http_user->ID, $usdtf_http_item['uuid'] );
	}

	remove_filter( 'wp_is_application_passwords_available', '__return_true' );
}

usdtf_it_pass( 'the admin REST namespace answers real HTTP requests' );
