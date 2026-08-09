<?php
/** Request-time DNS/SSRF contract; run with wp eval-file. */

if ( ! defined( 'WPBRIDGE_FILE' ) ) {
	require dirname( __DIR__, 2 ) . '/wpbridge.php';
}

$GLOBALS['wpbridge_ssrf_pass'] = 0;
$GLOBALS['wpbridge_ssrf_fail'] = 0;

function wpbridge_ssrf_assert( bool $condition, string $label ): void {
	if ( $condition ) {
		++$GLOBALS['wpbridge_ssrf_pass'];
		echo "PASS: {$label}\n";
	} else {
		++$GLOBALS['wpbridge_ssrf_fail'];
		echo "FAIL: {$label}\n";
	}
}

$dns = [];
$requests = [];
$resolver = static function ( $resolved, $host ) use ( &$dns ) {
	$value = $dns[ $host ] ?? $resolved;
	if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
		return array_shift( $dns[ $host ] );
	}
	return $value;
};
$http = static function ( $preempt, $args, $url ) use ( &$requests ) {
	$requests[] = [ 'url' => $url, 'args' => $args ];
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( '/redirect-private' === $path ) {
		return [ 'headers' => [ 'location' => 'https://private.test/final' ], 'body' => '', 'response' => [ 'code' => 302 ] ];
	}
	if ( '/rebind' === $path ) {
		return [ 'headers' => [ 'location' => '/final' ], 'body' => '', 'response' => [ 'code' => 302 ] ];
	}
	if ( '/redirect-origin' === $path ) {
		return [ 'headers' => [ 'location' => 'https://other.test/final' ], 'body' => '', 'response' => [ 'code' => 302 ] ];
	}
	return [ 'headers' => [], 'body' => '{}', 'response' => [ 'code' => 200 ], 'cookies' => [] ];
};
add_filter( 'wpbridge_pre_resolve_host', $resolver, 10, 2 );
add_filter( 'pre_http_request', $http, 10, 3 );

$dns['private.test'] = [ '127.0.0.1' ];
$result = \WPBridge\Security\SafeHttpClient::request( 'https://private.test/' );
wpbridge_ssrf_assert( is_wp_error( $result ) && 'wpbridge_private_address' === $result->get_error_code(), 'loopback rejected before request' );
wpbridge_ssrf_assert( 0 === count( $requests ), 'blocked target opens no HTTP request' );

$dns['mixed.test'] = [ '93.184.216.34', '10.0.0.8' ];
$result = \WPBridge\Security\SafeHttpClient::request( 'https://mixed.test/' );
wpbridge_ssrf_assert( is_wp_error( $result ) && 'wpbridge_private_address' === $result->get_error_code(), 'mixed public/private DNS rejected' );

$dns['public.test'] = [ '93.184.216.34' ];
$result = \WPBridge\Security\SafeHttpClient::request( 'https://public.test/ok' );
wpbridge_ssrf_assert( ! is_wp_error( $result ) && 200 === wp_remote_retrieve_response_code( $result ), 'public address accepted' );
wpbridge_ssrf_assert( '93.184.216.34' === ( $requests[0]['args']['_wpbridge_resolved_ip'] ?? '' ), 'resolved address carried to pinned request' );

$requests = [];
$dns['redirect.test'] = [ '93.184.216.34' ];
$dns['private.test']  = [ '169.254.169.254' ];
$result = \WPBridge\Security\SafeHttpClient::request( 'https://redirect.test/redirect-private' );
wpbridge_ssrf_assert( is_wp_error( $result ) && 'wpbridge_private_address' === $result->get_error_code(), 'redirect hop to metadata address rejected' );
wpbridge_ssrf_assert( 1 === count( $requests ), 'blocked redirect target opens no second request' );

$requests = [];
$dns['rebind.test'] = [ [ '93.184.216.34' ], [ '127.0.0.1' ] ];
$result = \WPBridge\Security\SafeHttpClient::request( 'https://rebind.test/rebind' );
wpbridge_ssrf_assert( is_wp_error( $result ) && 'wpbridge_private_address' === $result->get_error_code(), 'same-host DNS rebinding rejected on next hop' );
wpbridge_ssrf_assert( 1 === count( $requests ), 'rebinding target is not contacted' );

$requests = [];
$dns['origin.test'] = [ '93.184.216.34' ];
$dns['other.test']  = [ '93.184.216.35' ];
$result = \WPBridge\Security\SafeHttpClient::request(
	'https://origin.test/redirect-origin',
	[ 'headers' => [ 'X-API-Key' => 'not-a-real-secret', 'Accept' => 'application/json' ] ]
);
wpbridge_ssrf_assert( ! is_wp_error( $result ) && 2 === count( $requests ), 'public cross-origin redirect followed' );
wpbridge_ssrf_assert( ! isset( $requests[1]['args']['headers']['X-API-Key'] ), 'credentials stripped on cross-origin redirect' );
wpbridge_ssrf_assert( isset( $requests[1]['args']['headers']['Accept'] ), 'non-sensitive header retained' );

remove_filter( 'pre_http_request', $http, 10 );
remove_filter( 'wpbridge_pre_resolve_host', $resolver, 10 );

$pass = $GLOBALS['wpbridge_ssrf_pass'];
$fail = $GLOBALS['wpbridge_ssrf_fail'];
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
