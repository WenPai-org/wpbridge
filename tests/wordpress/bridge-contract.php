<?php
/** WP-CLI eval-file contract test; requires an isolated WordPress runtime. */

$port = (int) ( $args[0] ?? 18765 );
$base = 'http://127.0.0.1:' . $port;
$GLOBALS['wpbridge_contract_pass'] = 0;
$GLOBALS['wpbridge_contract_fail'] = 0;

function wpbridge_contract_assert( bool $condition, string $label ): void {
	if ( $condition ) {
		++$GLOBALS['wpbridge_contract_pass'];
		echo "PASS: {$label}\n";
	} else {
		++$GLOBALS['wpbridge_contract_fail'];
		echo "FAIL: {$label}\n";
	}
}

function wpbridge_local_client( string $base, int $timeout = 2 ): \WPBridge\Commercial\BridgeClient {
	$client = new \WPBridge\Commercial\BridgeClient( 'https://example.test', 'test-key', $timeout );
	$property = new ReflectionProperty( $client, 'server_url' );
	$property->setAccessible( true );
	$property->setValue( $client, $base );
	return $client;
}

$client = wpbridge_local_client( $base );
wpbridge_contract_assert( $client->health_check(), 'Bridge success JSON' );
wpbridge_contract_assert( '2.0.0' === ( $client->get_plugin_info( 'mock-plugin' )['version'] ?? '' ), 'Bridge plugin response' );
wpbridge_contract_assert( null === $client->get_plugin_info( 'non-json' ), 'Bridge non-JSON fails closed' );
wpbridge_contract_assert( null === $client->get_plugin_info( 'unauthorized' ), 'Bridge 401 fails closed' );
wpbridge_contract_assert( null === $client->get_plugin_info( 'forbidden' ), 'Bridge 403 fails closed' );
$started = microtime( true );
wpbridge_contract_assert( null === wpbridge_local_client( $base, 1 )->get_plugin_info( 'timeout' ), 'Bridge timeout fails closed' );
wpbridge_contract_assert( microtime( true ) - $started < 4.5, 'Bridge timeout is bounded' );

function wpbridge_local_vendor( string $id, string $base, int $timeout = 2 ): \WPBridge\Commercial\Vendors\BridgeApiVendor {
	$vendor = new \WPBridge\Commercial\Vendors\BridgeApiVendor( $id, $id, [ 'api_url' => 'https://example.test' ] );
	$property = new ReflectionProperty( \WPBridge\Commercial\Vendors\AbstractVendor::class, 'config' );
	$property->setAccessible( true );
	$property->setValue( $vendor, [ 'api_url' => $base, 'api_key' => 'vendor-key', 'api_secret' => '', 'timeout' => $timeout, 'enabled' => true ] );
	return $vendor;
}

$local_https_adapter = static function ( $preempt, $request, $url ) use ( $base ) {
	$https_base = str_replace( 'http://', 'https://', $base );
	if ( 0 !== strpos( $url, $https_base ) ) {
		return $preempt;
	}
	$context = stream_context_create( [ 'http' => [ 'timeout' => (float) ( $request['timeout'] ?? 2 ), 'ignore_errors' => true ] ] );
	$body    = @file_get_contents( 'http://' . substr( $url, 8 ), false, $context );
	if ( false === $body ) {
		return new WP_Error( 'http_request_failed', 'Local mock timeout' );
	}
	$status = 0;
	foreach ( $http_response_header ?? [] as $header ) {
		if ( preg_match( '#^HTTP/\\S+\\s+(\\d+)#', $header, $matches ) ) {
			$status = (int) $matches[1];
		}
	}
	return [ 'headers' => [], 'body' => $body, 'response' => [ 'code' => $status, 'message' => 'mock' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $local_https_adapter, 10, 3 );

$vendor = wpbridge_local_vendor( 'mock-success', $base . '/success' );
wpbridge_contract_assert( $vendor->verify_credentials(), 'Vendor success' );
wpbridge_contract_assert( 1 === count( $vendor->get_plugins()['plugins'] ?? [] ), 'Vendor plugin normalization' );
foreach ( [ 'non-json', '401', '403' ] as $case ) {
	$vendor = wpbridge_local_vendor( 'mock-' . $case, $base . '/' . $case );
	wpbridge_contract_assert( ! $vendor->verify_credentials(), 'Vendor ' . $case . ' fails closed' );
}
$vendor = wpbridge_local_vendor( 'mock-timeout', $base . '/timeout', 1 );
wpbridge_contract_assert( ! $vendor->verify_credentials(), 'Vendor timeout fails closed' );
remove_filter( 'pre_http_request', $local_https_adapter, 10 );

// Update checker: controlled HTTP fixtures verify downgrade/insecure packages preserve prior data.
$updater  = new \WPBridge_Updater( 'wpbridge/wpbridge.php', '1.2.3' );
$previous = (object) [ 'version' => '9.9.9', 'package' => 'https://safe.example/previous.zip' ];
$fixture  = static function ( $preempt, $request, $url ) {
	if ( false === strpos( $url, 'updates.wenpai.net/api/v1/update-check' ) ) {
		return $preempt;
	}
	return [
		'headers'  => [],
		'body'     => wp_json_encode( [ 'plugins' => [ 'wpbridge/wpbridge.php' => [ 'version' => '1.0.0', 'package' => 'http://127.0.0.1/package.zip' ] ] ] ),
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'cookies'  => [],
		'filename' => null,
	];
};
add_filter( 'pre_http_request', $fixture, 10, 3 );
$result = $updater->check_update( $previous, [], 'wpbridge/wpbridge.php', [] );
remove_filter( 'pre_http_request', $fixture, 10 );
wpbridge_contract_assert( $previous === $result, 'Downgrade and insecure package preserve previous update' );

$pass = $GLOBALS['wpbridge_contract_pass'];
$fail = $GLOBALS['wpbridge_contract_fail'];
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
