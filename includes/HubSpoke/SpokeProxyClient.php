<?php
/** Runtime client for an active Spoke link. */

declare(strict_types=1);

namespace WPBridge\HubSpoke;

use WPBridge\Security\PackageIntegrityVerifier;
use WPBridge\Security\SafeHttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SpokeProxyClient {
	private array $link;
	/** @var callable */
	private $transport;

	/** @return self|\WP_Error */
	public static function from_link( string $link_id, ?callable $transport = null ) {
		$link = ( new HubSpokeStore() )->active_spoke_link( $link_id );
		return null === $link ? new \WP_Error( 'wpbridge_spoke_link_unavailable', __( 'Spoke link credential 不可用。', 'wpbridge' ) ) : new self( $link, $transport );
	}

	private function __construct( array $link, ?callable $transport ) {
		$this->link = $link;
		$this->transport = $transport ?? [ SafeHttpClient::class, 'request' ];
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function sources() {
		$data = $this->json_get( '/wp-json/wpbridge/v2/hub-proxy/sources' );
		return is_wp_error( $data ) || ! isset( $data['sources'] ) || ! is_array( $data['sources'] ) ? ( is_wp_error( $data ) ? $data : new \WP_Error( 'wpbridge_spoke_response_invalid', __( 'Hub source 响应无效。', 'wpbridge' ) ) ) : $data['sources'];
	}

	/** @return array<string,mixed>|\WP_Error */
	public function metadata( string $slug ) {
		if ( ! $this->allowed_slug( $slug ) ) {
			return new \WP_Error( 'wpbridge_spoke_slug_forbidden', __( 'Slug 不在 Spoke allowlist。', 'wpbridge' ) );
		}
		$data = $this->json_get( '/wp-json/wpbridge/v2/hub-proxy/plugins/' . rawurlencode( $slug ) . '/metadata' );
		if ( is_wp_error( $data ) || ( $data['slug'] ?? '' ) !== $slug || isset( $data['grant'], $data['authorization'], $data['upstream_url'] ) ) {
			return is_wp_error( $data ) ? $data : new \WP_Error( 'wpbridge_spoke_response_invalid', __( 'Hub metadata 响应无效。', 'wpbridge' ) );
		}
		$data['package'] = $this->package_url( $slug );
		return $data;
	}

	/** @return string|\WP_Error */
	public function download( string $slug, array $integrity ) {
		if ( ! $this->allowed_slug( $slug ) ) {
			return new \WP_Error( 'wpbridge_spoke_slug_forbidden', __( 'Slug 不在 Spoke allowlist。', 'wpbridge' ) );
		}
		$file = wp_tempnam( $slug . '.zip' );
		if ( ! is_string( $file ) || '' === $file ) {
			return new \WP_Error( 'wpbridge_spoke_temp_failed', __( '无法创建更新包临时文件。', 'wpbridge' ) );
		}
		$response = call_user_func( $this->transport, $this->package_url( $slug ), [ 'method' => 'GET', 'timeout' => 300, 'redirection' => 0, 'stream' => true, 'filename' => $file, 'headers' => $this->headers( 'application/zip' ) ] );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $file );
			return is_wp_error( $response ) ? $response : new \WP_Error( 'wpbridge_spoke_package_rejected', __( 'Hub package 代理失败。', 'wpbridge' ) );
		}
		$verified = PackageIntegrityVerifier::verify_downloaded_file( $file, $integrity );
		if ( is_wp_error( $verified ) ) {
			wp_delete_file( $file );
			return $verified;
		}
		return $file;
	}

	public function package_url( string $slug ): string {
		return $this->origin() . '/wp-json/wpbridge/v2/hub-proxy/plugins/' . rawurlencode( $slug ) . '/package';
	}

	/** @return array<string,mixed>|\WP_Error */
	private function json_get( string $route ) {
		$response = call_user_func( $this->transport, $this->origin() . $route, [ 'method' => 'GET', 'timeout' => 15, 'redirection' => 0, 'headers' => $this->headers( 'application/json' ) ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return 200 === (int) wp_remote_retrieve_response_code( $response ) && is_array( $data ) ? $data : new \WP_Error( 'wpbridge_spoke_request_rejected', __( 'Hub 拒绝了 Spoke 请求。', 'wpbridge' ) );
	}

	private function origin(): string {
		return rtrim( (string) $this->link['hub_origin'], '/' );
	}

	private function allowed_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{1,99}$/', $slug ) && in_array( $slug, (array) $this->link['slug_allowlist'], true );
	}

	private function headers( string $accept ): array {
		return [ 'Accept' => $accept, 'Authorization' => 'WPBridge-Link ' . $this->link['credential'] ];
	}
}
