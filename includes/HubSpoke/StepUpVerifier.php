<?php
/**
 * Local administrator step-up verifier.
 *
 * @package WPBridge
 */

declare(strict_types=1);

namespace WPBridge\HubSpoke;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Issues five-minute, session-bound opaque proofs after password verification. */
final class StepUpVerifier {
	/** @return array<string,mixed>|\WP_Error */
	public function issue( \WP_REST_Request $request ) {
		$base = $this->check_admin_request( $request, false );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$body = $request->get_json_params();
		if ( ! self::exact_keys( $body, [ 'password' ] ) || ! is_string( $body['password'] ) || '' === $body['password'] ) {
			return new \WP_Error( 'wpbridge_step_up_invalid', __( '重新认证请求无效。', 'wpbridge' ), [ 'status' => 400 ] );
		}
		$user = wp_get_current_user();
		if ( ! $user || ! wp_check_password( $body['password'], $user->user_pass, $user->ID ) ) {
			return new \WP_Error( 'wpbridge_step_up_failed', __( '重新认证失败。', 'wpbridge' ), [ 'status' => 401 ] );
		}
		$proof = InstallationIdentity::base64url( random_bytes( 32 ) );
		set_transient(
			$this->proof_key( $proof ),
			[
				'user_id'       => (int) $user->ID,
				'session_sha256' => $this->session_hash(),
				'cleanup_only'   => ! LinkAuthorizer::enabled(),
			],
			300
		);
		return [ 'step_up_proof' => $proof, 'expires_in' => 300 ];
	}

	/** @return true|\WP_Error */
	public function verify( \WP_REST_Request $request, bool $cleanup = false ) {
		$base = $this->check_admin_request( $request, true );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$proof  = (string) $request->get_header( 'X-WPBridge-Step-Up' );
		$record = 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $proof ) ? get_transient( $this->proof_key( $proof ) ) : false;
		if ( ! is_array( $record ) || get_current_user_id() !== (int) ( $record['user_id'] ?? 0 ) || ! hash_equals( $this->session_hash(), (string) ( $record['session_sha256'] ?? '' ) ) ) {
			return new \WP_Error( 'wpbridge_step_up_required', __( '此操作需要最近五分钟内重新认证。', 'wpbridge' ), [ 'status' => 401 ] );
		}
		if ( ! $cleanup && ! empty( $record['cleanup_only'] ) ) {
			return new \WP_Error( 'wpbridge_cleanup_proof_scope_invalid', __( '此凭证仅可用于降低权限的清理操作。', 'wpbridge' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function check_admin_request( \WP_REST_Request $request, bool $state_change ) {
		unset( $state_change );
		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! is_user_logged_in() || ! current_user_can( $capability ) || ( is_multisite() && ! is_super_admin() ) ) {
			return new \WP_Error( 'wpbridge_admin_forbidden', __( '仅管理员可执行此操作。', 'wpbridge' ), [ 'status' => 403 ] );
		}
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'wpbridge_nonce_invalid', __( '请求 nonce 无效。', 'wpbridge' ), [ 'status' => 403 ] );
		}
		$origin = (string) $request->get_header( 'Origin' );
		$home   = wp_parse_url( home_url( '/' ) );
		$actual = wp_parse_url( $origin );
		if ( ! is_array( $home ) || ! is_array( $actual ) || strtolower( (string) ( $home['scheme'] ?? '' ) ) !== strtolower( (string) ( $actual['scheme'] ?? '' ) ) || strtolower( (string) ( $home['host'] ?? '' ) ) !== strtolower( (string) ( $actual['host'] ?? '' ) ) || (int) ( $home['port'] ?? ( 'https' === ( $home['scheme'] ?? '' ) ? 443 : 80 ) ) !== (int) ( $actual['port'] ?? ( 'https' === ( $actual['scheme'] ?? '' ) ? 443 : 80 ) ) ) {
			return new \WP_Error( 'wpbridge_origin_invalid', __( '请求 origin 无效。', 'wpbridge' ), [ 'status' => 403 ] );
		}
		return true;
	}

	private function proof_key( string $proof ): string {
		return 'wpbridge_step_up_' . hash( 'sha256', $proof );
	}

	private function session_hash(): string {
		return hash( 'sha256', (string) wp_get_session_token() );
	}

	private static function exact_keys( $value, array $expected ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $keys === $expected;
	}
}
