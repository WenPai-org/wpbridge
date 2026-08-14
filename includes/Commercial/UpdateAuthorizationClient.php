<?php
/**
 * Site-bound WenPai update authorization client.
 *
 * @package WPBridge
 */

declare(strict_types=1);

namespace WPBridge\Commercial;

use WPBridge\Security\Encryption;
use WPBridge\Security\SafeHttpClient;
use WPBridge\Security\Validator;
use WPBridge\HubSpoke\HubSpokeStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UpdateAuthorizationClient {
	/** @var array<string,string> */
	private array $credentials;

	/** @var callable */
	private $transport;

	/** @var callable */
	private $clock;

	/** @param array<string,string> $credentials */
	public function __construct( array $credentials, ?callable $transport = null, ?callable $clock = null ) {
		$this->credentials = $credentials;
		$this->transport = $transport ?? [ SafeHttpClient::class, 'request' ];
		$this->clock     = $clock ?? 'time';
	}

	/**
	 * Redeem a one-time pairing code and return encrypted source metadata.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	public static function pair( string $license_url, string $pairing_code, string $site_url, ?callable $transport = null, ?callable $keypair_factory = null ) {
		if ( class_exists( HubSpokeStore::class ) && ( new HubSpokeStore() )->has_active_spoke_link() ) {
			return new \WP_Error( 'wpbridge_spoke_pairing_forbidden', __( 'Spoke 不能消费 pairing code；请在 Hub 完成配对。', 'wpbridge' ) );
		}
		$license_url = rtrim( $license_url, '/' );
		if ( ! Validator::is_valid_url( $license_url ) || ! Validator::is_valid_url( $site_url ) || ! preg_match( '/^WPB1-[A-Za-z0-9_-]{43}$/', $pairing_code ) ) {
			return new \WP_Error( 'wpbridge_pairing_invalid', __( '配对信息无效。', 'wpbridge' ) );
		}
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) || ! function_exists( 'sodium_memzero' ) || ! defined( 'SODIUM_CRYPTO_SIGN_KEYPAIRBYTES' ) ) {
			return new \WP_Error( 'wpbridge_pairing_sodium_required', __( '站点缺少更新授权所需的 Sodium 扩展。', 'wpbridge' ) );
		}
		$factory = $keypair_factory ?? 'sodium_crypto_sign_keypair';
		$keypair = $factory();
		if ( ! is_string( $keypair ) || SODIUM_CRYPTO_SIGN_KEYPAIRBYTES !== strlen( $keypair ) ) {
			return new \WP_Error( 'wpbridge_pairing_key_failed', __( '无法生成站点更新密钥。', 'wpbridge' ) );
		}
		$public_key = sodium_crypto_sign_publickey( $keypair );
		$secret_key = sodium_crypto_sign_secretkey( $keypair );
		sodium_memzero( $keypair );
		$body       = wp_json_encode(
			[
				'pairing_code' => $pairing_code,
				'site_url'     => $site_url,
				'public_key'   => self::base64url( $public_key ),
			]
		);
		if ( ! is_string( $body ) ) {
			sodium_memzero( $secret_key );
			return new \WP_Error( 'wpbridge_pairing_encode_failed', __( '无法生成配对请求。', 'wpbridge' ) );
		}
		$request = $transport ?? [ SafeHttpClient::class, 'request' ];
		$response = $request(
			$license_url . '/api/v1/updates/pair',
			[
				'method'      => 'POST',
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
				'body'        => $body,
			]
		);
		if ( is_wp_error( $response ) ) {
			sodium_memzero( $secret_key );
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 201 !== $status || ! is_array( $data ) || ! self::valid_pair_response( $data, $site_url ) ) {
			sodium_memzero( $secret_key );
			return new \WP_Error( 'wpbridge_pairing_rejected', __( '授权服务拒绝了配对请求。', 'wpbridge' ), [ 'status' => $status ] );
		}
		try {
			$encrypted = Encryption::encrypt( self::base64url( $secret_key ) );
		} catch ( \Throwable $error ) {
			sodium_memzero( $secret_key );
			return new \WP_Error( 'wpbridge_pairing_storage_failed', __( '站点更新密钥无法安全保存。', 'wpbridge' ) );
		}
		sodium_memzero( $secret_key );
		if ( '' === $encrypted ) {
			return new \WP_Error( 'wpbridge_pairing_storage_failed', __( '站点更新密钥无法安全保存。', 'wpbridge' ) );
		}
		return [
			'license_server_url' => $license_url,
			'update_device_id'   => (string) $data['device_id'],
			'update_private_key' => $encrypted,
			'update_site_url'    => (string) $data['site_url'],
			'update_product_slug' => (string) $data['product_slug'],
		];
	}

	/** @return string|\WP_Error */
	public function issue_grant( string $slug, string $action ) {
		if ( class_exists( HubSpokeStore::class ) && ( new HubSpokeStore() )->has_active_spoke_link() ) {
			return new \WP_Error( 'wpbridge_spoke_grant_forbidden', __( 'Active Spoke 不能直接请求上游更新授权。', 'wpbridge' ) );
		}
		if ( ! in_array( $action, [ 'metadata', 'package' ], true ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{1,99}$/', $slug ) ) {
			return new \WP_Error( 'wpbridge_update_grant_invalid', __( '更新授权范围无效。', 'wpbridge' ) );
		}
		$license_url = rtrim( (string) ( $this->credentials['license_server_url'] ?? '' ), '/' );
		$device_id  = (string) ( $this->credentials['update_device_id'] ?? '' );
		$encrypted  = (string) ( $this->credentials['update_private_key'] ?? '' );
		if ( ! Validator::is_valid_url( $license_url ) || ! preg_match( '/^dev_[A-Za-z0-9_-]{16,64}$/', $device_id ) || '' === $encrypted ) {
			return new \WP_Error( 'wpbridge_update_not_paired', __( '此更新源尚未与文派账户配对。', 'wpbridge' ) );
		}
		$encoded_secret = Encryption::decrypt( $encrypted );
		$secret_key     = self::base64url_decode( $encoded_secret );
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) || ! function_exists( 'sodium_memzero' ) || ! defined( 'SODIUM_CRYPTO_SIGN_SECRETKEYBYTES' ) || ! is_string( $secret_key ) || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret_key ) ) {
			return new \WP_Error( 'wpbridge_update_key_unavailable', __( '站点更新密钥不可用。', 'wpbridge' ) );
		}
		$body = wp_json_encode( [ 'slug' => $slug, 'action' => $action ] );
		if ( ! is_string( $body ) ) {
			sodium_memzero( $secret_key );
			return new \WP_Error( 'wpbridge_update_grant_invalid', __( '无法生成更新授权请求。', 'wpbridge' ) );
		}
		$timestamp = (string) call_user_func( $this->clock );
		$path      = '/api/v1/updates/grants';
		$canonical = "POST\n{$path}\n{$timestamp}\n" . hash( 'sha256', $body );
		$signature = sodium_crypto_sign_detached( $canonical, $secret_key );
		sodium_memzero( $secret_key );
		$response = call_user_func(
			$this->transport,
			$license_url . $path,
			[
				'method'      => 'POST',
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => [
					'Accept'              => 'application/json',
					'Content-Type'        => 'application/json',
					'X-WenPai-Device'     => $device_id,
					'X-WenPai-Timestamp'  => $timestamp,
					'X-WenPai-Signature'  => self::base64url( $signature ),
				],
				'body' => $body,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$expected_scope = 'metadata' === $action ? 'updates:read' : 'packages:read';
		if ( 200 !== $status || ! is_array( $data ) || ! isset( $data['grant'], $data['scope'], $data['product_slug'], $data['expires_at'] ) || ! is_string( $data['grant'] ) || 0 !== strpos( $data['grant'], 'wpg1.' ) || $data['product_slug'] !== $slug || $data['scope'] !== $expected_scope || ! is_string( $data['expires_at'] ) ) {
			return new \WP_Error( 'wpbridge_update_grant_rejected', __( '授权服务未签发更新许可。', 'wpbridge' ), [ 'status' => $status ] );
		}
		return $data['grant'];
	}

	/** @param array<string,mixed> $data */
	private static function valid_pair_response( array $data, string $site_url ): bool {
		foreach ( [ 'device_id', 'entitlement_id', 'site_url', 'product_slug', 'paired_at' ] as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || '' === $data[ $key ] ) {
				return false;
			}
		}
		return preg_match( '/^dev_[A-Za-z0-9_-]{16,64}$/', $data['device_id'] ) === 1
			&& hash_equals( rtrim( strtolower( $site_url ), '/' ), rtrim( strtolower( $data['site_url'] ), '/' ) )
			&& preg_match( '/^[a-z0-9][a-z0-9-]{1,99}$/', $data['product_slug'] ) === 1;
	}

	private static function base64url( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/** @return string|false */
	private static function base64url_decode( string $value ) {
		$padding = ( 4 - strlen( $value ) % 4 ) % 4;
		return base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', $padding ), true );
	}
}
