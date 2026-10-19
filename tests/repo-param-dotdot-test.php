<?php
/**
 * bridge/v1 wenpai-git/{repo}/releases: the repo parameter must not accept
 * "." or ".." as its second segment (path traversal into the git.wenpai.org
 * API URL). Checks both the route regex (as WP_REST_Server matches it) and
 * the validate_callback, and that the two agree. WordPress-independent.
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	if ( ! class_exists( 'WP_REST_Server' ) ) {
		class WP_REST_Server {
			const READABLE   = 'GET';
			const CREATABLE  = 'POST';
			const EDITABLE   = 'POST, PUT, PATCH';
			const DELETABLE  = 'DELETE';
			const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
		}
	}

	$GLOBALS['rd_routes'] = [];
	function add_action(): bool {
		return true;
	}
	function register_rest_route( string $namespace, string $route, array $args ): bool {
		$GLOBALS['rd_routes'][] = [ $namespace, $route, $args ];
		return true;
	}

	require_once dirname( __DIR__ ) . '/includes/API/RestController.php';

	$failures = 0;
	$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
		echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			++$failures;
		}
	};

	$controller = ( new ReflectionClass( \WPBridge\API\RestController::class ) )->newInstanceWithoutConstructor();
	$controller->register_routes();

	$entry = null;
	foreach ( $GLOBALS['rd_routes'] as $r ) {
		if ( false !== strpos( $r[1], '/wenpai-git/' ) ) {
			$entry = $r;
		}
	}
	$assert( null !== $entry, 'wenpai-git releases route is registered' );
	if ( null === $entry ) {
		exit( 1 );
	}
	[ $namespace, $route, $args ] = $entry;
	$validate = $args['args']['repo']['validate_callback'] ?? null;
	$assert( is_callable( $validate ), 'repo arg declares a validate_callback' );

	// Same full-route construction and match expression as WP_REST_Server.
	$full_route    = '/' . trim( $namespace, '/' ) . '/' . trim( $route, '/' );
	$route_accepts = static function ( string $repo ) use ( $full_route ): ?string {
		$path = '/bridge/v1/wenpai-git/' . $repo . '/releases';
		if ( 1 !== preg_match( '@^' . $full_route . '$@i', $path, $m ) ) {
			return null;
		}
		return $m['repo'] ?? '';
	};
	$validate_accepts = static function ( string $repo ) use ( $validate ): bool {
		return true === (bool) call_user_func( $validate, $repo, null, 'repo' );
	};

	$rejected = [ 'owner/..', 'owner/.' ];
	$allowed  = [ 'owner/a..b', 'owner/repo.name', 'owner/.hidden', 'WenPai-org/wpbridge', 'owner/v1.2.3' ];

	foreach ( $rejected as $repo ) {
		$assert( null === $route_accepts( $repo ), "route regex does not match repo '{$repo}'" );
		$assert( false === $validate_accepts( $repo ), "validate_callback rejects repo '{$repo}'" );
	}
	foreach ( $allowed as $repo ) {
		$assert( $repo === $route_accepts( $repo ), "route regex matches repo '{$repo}' and captures it whole" );
		$assert( true === $validate_accepts( $repo ), "validate_callback accepts repo '{$repo}'" );
	}

	// Both checks must agree on every probe (incl. pre-existing rejections).
	$probes   = array_merge( $rejected, $allowed, [ 'own.er/repo', 'owner', 'owner/', 'owner/a/b', 'owner/..x', 'owner/...', 'owner/a b' ] );
	$mismatch = [];
	foreach ( $probes as $repo ) {
		if ( ( null !== $route_accepts( $repo ) ) !== $validate_accepts( $repo ) ) {
			$mismatch[] = $repo;
		}
	}
	$assert( [] === $mismatch, 'route regex and validate_callback agree on all probes' . ( $mismatch ? ' (mismatch: ' . implode( ', ', $mismatch ) . ')' : '' ) );
	$assert( is_bool( call_user_func( $validate, 'owner/repo', null, 'repo' ) ), 'validate_callback returns a bool' );

	echo $failures ? "{$failures} assertion(s) failed" . PHP_EOL : 'All repo-param assertions passed.' . PHP_EOL;
	exit( $failures ? 1 : 0 );
}
