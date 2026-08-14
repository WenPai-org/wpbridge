<?php
/** Feature-off cleanup proof scope regression. */
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['cleanup_transients'] = [];
	final class WP_Error {
		private string $code;
		public function __construct( string $code ) { $this->code = $code; }
		public function get_error_code(): string { return $this->code; }
	}
	final class WP_REST_Request {
		private array $body;
		private array $headers;
		public function __construct( array $body = [], array $headers = [] ) { $this->body = $body; $this->headers = $headers; }
		public function get_json_params(): array { return $this->body; }
		public function get_header( string $name ): string { return (string) ( $this->headers[ $name ] ?? '' ); }
	}
	final class WP_REST_Response {
		private $data;
		private int $status;
		public function __construct( $data, int $status ) { $this->data = $data; $this->status = $status; }
		public function header( string $name, string $value ): void { unset( $name, $value ); }
		public function get_data() { return $this->data; }
		public function get_status(): int { return $this->status; }
	}
	function __( string $message, string $domain = '' ): string { unset( $domain ); return $message; }
	function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
	function is_multisite(): bool { return false; }
	function is_user_logged_in(): bool { return true; }
	function current_user_can( string $capability ): bool { return 'manage_options' === $capability; }
	function is_super_admin(): bool { return true; }
	function wp_verify_nonce( string $nonce, string $action ): bool { return 'nonce' === $nonce && 'wp_rest' === $action; }
	function home_url( string $path = '' ): string { unset( $path ); return 'https://site.example/'; }
	function wp_parse_url( string $url ) { return parse_url( $url ); }
	function wp_get_current_user(): object { return (object) [ 'ID' => 7, 'user_pass' => 'hash' ]; }
	function wp_check_password( string $password, string $hash, int $id ): bool { return 'password' === $password && 'hash' === $hash && 7 === $id; }
	function get_current_user_id(): int { return 7; }
	function wp_get_session_token(): string { return 'session'; }
	function set_transient( string $key, $value, int $ttl ): bool { unset( $ttl ); $GLOBALS['cleanup_transients'][ $key ] = $value; return true; }
	function get_transient( string $key ) { return $GLOBALS['cleanup_transients'][ $key ] ?? false; }
}

namespace WPBridge\HubSpoke {
	final class InstallationIdentity {
		public static function base64url( string $value ): string { return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
	}
	final class LinkAuthorizer {
		public static function enabled(): bool { return false; }
	}
	require_once dirname( __DIR__ ) . '/includes/HubSpoke/StepUpVerifier.php';
	require_once dirname( __DIR__ ) . '/includes/HubSpoke/HubSpokeController.php';
	$verifier = new StepUpVerifier();
	$base_headers = [ 'X-WP-Nonce' => 'nonce', 'Origin' => 'https://site.example' ];
	$controller = ( new \ReflectionClass( HubSpokeController::class ) )->newInstanceWithoutConstructor();
	$property = ( new \ReflectionClass( HubSpokeController::class ) )->getProperty( 'step_up' );
	$property->setAccessible( true );
	$property->setValue( $controller, $verifier );
	$issued_response = $controller->issue_step_up( new \WP_REST_Request( [ 'password' => 'password' ], $base_headers ) );
	$issued = $issued_response instanceof \WP_REST_Response ? $issued_response->get_data() : [];
	$proof  = is_array( $issued ) ? (string) ( $issued['step_up_proof'] ?? '' ) : '';
	$request = new \WP_REST_Request( [], $base_headers + [ 'X-WPBridge-Step-Up' => $proof ] );
	$cleanup = $controller->cleanup_permission( $request );
	$expansion = $controller->admin_permission( $request );
	$pass = $issued_response instanceof \WP_REST_Response && 201 === $issued_response->get_status() && true === $cleanup && $expansion instanceof \WP_Error && 'wpbridge_hub_spoke_disabled' === $expansion->get_error_code();
	echo ( $pass ? '[PASS] ' : '[FAIL] ' ) . "Feature-off step-up is usable only by authority-reducing cleanup routes\n";
	exit( $pass ? 0 : 1 );
}
