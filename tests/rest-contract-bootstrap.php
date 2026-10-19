<?php
/**
 * Shared WordPress-independent stubs for the REST contract tests.
 *
 * The including test defines WPBRIDGE_HUB_SPOKE_ENABLED before requiring this file.
 */

declare(strict_types=1);

namespace WPBridge\Core {
	class Settings {
		public array $data = [];
		public function get( string $key, $default = null ) {
			return $this->data[ $key ] ?? $default;
		}
		public function get_request_timeout(): int {
			return 5;
		}
	}
	class Logger {
		public static array $records = [];
		public static function __callStatic( string $name, array $args ): void {
			self::$records[] = [ $name, (string) ( $args[0] ?? '' ) ];
		}
	}
}

namespace WPBridge\Cache {
	class CacheManager {
		public array $items = [];
		public function get( string $key ) {
			return $this->items[ $key ] ?? false;
		}
		public function set( string $key, $value, int $ttl = 0 ): bool {
			unset( $ttl );
			$this->items[ $key ] = $value;
			return true;
		}
	}
	class HealthChecker {
		public function check( $source, bool $force = false ) {
			unset( $source, $force );
			return (object) [ 'status' => 'healthy', 'response_time' => 12, 'error' => '' ];
		}
	}
}

namespace WPBridge\UpdateSource {
	class SourceManager {
		public array $sources = [];
		public function get( string $id ) {
			return $this->sources[ $id ] ?? null;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	define( 'AUTH_KEY', 'wpbridge-rest-contract-test-key' );
	define( 'WPBRIDGE_VERSION', '9.9.9-contract' );
	define( 'WPBRIDGE_HUB_LINK_ACTIVE_KEY_VERSION', 'test-v1' );
	define( 'WPBRIDGE_HUB_LINK_MASTER_KEYS', [ 'test-v1' => rtrim( strtr( base64_encode( str_repeat( 'K', 32 ) ), '+/', '-_' ), '=' ) ] );
	define( 'WPBRIDGE_HUB_ORIGIN_ALLOWLIST', [ 'https://hub.example' ] );

	$GLOBALS['ct_routes']       = [];
	$GLOBALS['ct_options']      = [];
	$GLOBALS['ct_site_options'] = [];
	$GLOBALS['ct_transients']   = [];
	$GLOBALS['ct_uuid']         = 0;
	$GLOBALS['ct_user']         = [ 'logged_in' => false, 'caps' => [], 'super' => false, 'id' => 0, 'pass' => '' ];
	$GLOBALS['ct_multisite']    = false;
	$GLOBALS['wpdb']            = new class() {
		public function prepare( string $sql, ...$args ): string {
			return vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $sql ), $args );
		}
		public function get_var( string $sql ): string {
			unset( $sql );
			return '1';
		}
	};

	function __( string $message, string $domain = '' ): string {
		unset( $domain );
		return $message;
	}
	function add_action(): bool {
		return true;
	}
	function add_filter(): bool {
		return true;
	}
	function apply_filters( string $tag, $value ) {
		unset( $tag );
		return $value;
	}
	function register_rest_route( string $namespace, string $route, array $args ): bool {
		$GLOBALS['ct_routes'][] = [ $namespace, $route, $args ];
		return true;
	}
	function rest_url( string $path = '' ): string {
		return 'https://site.example/wp-json/' . ltrim( $path, '/' );
	}
	function home_url( string $path = '' ): string {
		return 'https://site.example' . $path;
	}
	function current_time( string $type ): string {
		unset( $type );
		return '2026-10-19T00:00:00+08:00';
	}
	function sanitize_text_field( $value ): string {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
	}
	function sanitize_title( $value ): string {
		return strtolower( trim( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $value ), '-' ) );
	}
	function wp_unslash( $value ) {
		return $value;
	}
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
	function is_multisite(): bool {
		return $GLOBALS['ct_multisite'];
	}
	function is_super_admin(): bool {
		return $GLOBALS['ct_user']['super'];
	}
	function is_user_logged_in(): bool {
		return $GLOBALS['ct_user']['logged_in'];
	}
	function current_user_can( string $cap ): bool {
		return in_array( $cap, $GLOBALS['ct_user']['caps'], true );
	}
	function get_current_user_id(): int {
		return $GLOBALS['ct_user']['id'];
	}
	function wp_get_current_user() {
		return (object) [ 'ID' => $GLOBALS['ct_user']['id'], 'user_pass' => $GLOBALS['ct_user']['pass'] ];
	}
	function wp_check_password( string $password, string $hash, $user_id = '' ): bool {
		unset( $user_id );
		return '' !== $hash && password_verify( $password, $hash );
	}
	function wp_verify_nonce( string $nonce, string $action ) {
		return 'valid-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
	}
	function wp_get_session_token(): string {
		return 'session-token-a';
	}
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		unset( $ttl );
		$GLOBALS['ct_transients'][ $key ] = $value;
		return true;
	}
	function get_transient( string $key ) {
		return $GLOBALS['ct_transients'][ $key ] ?? false;
	}
	function get_option( string $key, $default = false ) {
		return $GLOBALS['ct_options'][ $key ] ?? $default;
	}
	function update_option( string $key, $value, $autoload = null ): bool {
		unset( $autoload );
		$GLOBALS['ct_options'][ $key ] = $value;
		return true;
	}
	function delete_option( string $key ): bool {
		unset( $GLOBALS['ct_options'][ $key ] );
		return true;
	}
	function get_site_option( string $key, $default = false ) {
		return $GLOBALS['ct_site_options'][ $key ] ?? $default;
	}
	function update_site_option( string $key, $value ): bool {
		$GLOBALS['ct_site_options'][ $key ] = $value;
		return true;
	}
	function wp_generate_uuid4(): string {
		++$GLOBALS['ct_uuid'];
		return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['ct_uuid'] );
	}
	function wp_next_scheduled(): bool {
		return true;
	}
	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	class WP_Error {
		private string $code;
		private string $message;
		private $data;
		public function __construct( string $code = '', string $message = '', $data = [] ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}

	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
		const EDITABLE  = 'POST, PUT, PATCH';
		const DELETABLE = 'DELETE';
	}

	class WP_REST_Request implements ArrayAccess {
		private array $headers = [];
		private array $params;
		private array $query;
		private $json;
		public function __construct( array $headers = [], array $params = [], $json = null, array $query = [] ) {
			foreach ( $headers as $name => $value ) {
				$this->headers[ strtolower( $name ) ] = $value;
			}
			$this->params = array_merge( $query, $params );
			$this->query  = $query;
			$this->json   = $json;
		}
		public function get_header( string $name ) {
			return $this->headers[ strtolower( $name ) ] ?? null;
		}
		public function get_param( string $name ) {
			return $this->params[ $name ] ?? null;
		}
		public function get_json_params() {
			return $this->json;
		}
		public function get_query_params(): array {
			return $this->query;
		}
		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}
		#[\ReturnTypeWillChange]
		public function offsetGet( $offset ) {
			return $this->params[ $offset ] ?? null;
		}
		public function offsetSet( $offset, $value ): void {
			$this->params[ $offset ] = $value;
		}
		public function offsetUnset( $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}

	class WP_REST_Response {
		private $data;
		private int $status;
		private array $headers = [];
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
		public function header( string $name, string $value ): void {
			$this->headers[ $name ] = $value;
		}
		public function get_headers(): array {
			return $this->headers;
		}
		public function get_data() {
			return $this->data;
		}
		public function get_status(): int {
			return $this->status;
		}
	}

	$ct_root = dirname( __DIR__ );
	require_once $ct_root . '/includes/Security/Encryption.php';
	require_once $ct_root . '/includes/HubSpoke/InstallationIdentity.php';
	require_once $ct_root . '/includes/HubSpoke/CredentialEnvelope.php';
	require_once $ct_root . '/includes/HubSpoke/HubSpokeStore.php';
	require_once $ct_root . '/includes/HubSpoke/HostCanonicalizer.php';
	require_once $ct_root . '/includes/HubSpoke/LinkAuthorizer.php';
	require_once $ct_root . '/includes/HubSpoke/StepUpVerifier.php';
	require_once $ct_root . '/includes/HubSpoke/HubSpokeController.php';
	require_once $ct_root . '/includes/API/RestController.php';

	/** Builds a HubSpokeController without its WordPress-bound constructor. */
	function ct_hub_controller(): \WPBridge\HubSpoke\HubSpokeController {
		$reflection = new ReflectionClass( \WPBridge\HubSpoke\HubSpokeController::class );
		$controller = $reflection->newInstanceWithoutConstructor();
		$store      = new \WPBridge\HubSpoke\HubSpokeStore();
		$values     = [
			'store'      => $store,
			'step_up'    => new \WPBridge\HubSpoke\StepUpVerifier(),
			'authorizer' => new \WPBridge\HubSpoke\LinkAuthorizer( $store ),
		];
		foreach ( $values as $name => $value ) {
			$property = $reflection->getProperty( $name );
			$property->setAccessible( true );
			$property->setValue( $controller, $value );
		}
		return $controller;
	}

	/** Builds a RestController with stub settings, cache and source manager. */
	function ct_rest_controller( array $api_settings, array $sources = [] ): \WPBridge\API\RestController {
		$reflection = new ReflectionClass( \WPBridge\API\RestController::class );
		$controller = $reflection->newInstanceWithoutConstructor();
		$settings   = new \WPBridge\Core\Settings();
		$settings->data['api'] = $api_settings;
		$manager          = new \WPBridge\UpdateSource\SourceManager();
		$manager->sources = $sources;
		$values           = [
			'settings'       => $settings,
			'source_manager' => $manager,
			'cache'          => new \WPBridge\Cache\CacheManager(),
		];
		foreach ( $values as $name => $value ) {
			$property = $reflection->getProperty( $name );
			$property->setAccessible( true );
			$property->setValue( $controller, $value );
		}
		return $controller;
	}

	/** Returns [code, status] for a WP_Error, or [null, null] for anything else. */
	function ct_error( $value ): array {
		if ( ! $value instanceof WP_Error ) {
			return [ null, null ];
		}
		$data = $value->get_error_data();
		return [ $value->get_error_code(), is_array( $data ) ? ( $data['status'] ?? null ) : null ];
	}

	/** Normalises captured register_rest_route calls into "NS METHOD route" => "callback|permission". */
	function ct_route_table( string $namespace ): array {
		$name  = static function ( $callable ): string {
			return is_array( $callable ) ? (string) $callable[1] : (string) $callable;
		};
		$table = [];
		foreach ( $GLOBALS['ct_routes'] as [ $ns, $route, $args ] ) {
			if ( $ns !== $namespace ) {
				continue;
			}
			$endpoints = isset( $args['methods'] ) ? [ $args ] : $args;
			foreach ( $endpoints as $endpoint ) {
				$table[ $endpoint['methods'] . ' ' . $route ] = $name( $endpoint['callback'] ) . '|' . $name( $endpoint['permission_callback'] ?? 'MISSING' );
			}
		}
		ksort( $table );
		return $table;
	}
}
