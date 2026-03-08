<?php
/**
 * Bridge API 供应商
 *
 * 连接另一个 WPBridge 站点的 Bridge API（hub-spoke 架构）
 *
 * @package WPBridge
 * @since 1.1.0
 */

declare(strict_types=1);

namespace WPBridge\Commercial\Vendors;

use WPBridge\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BridgeApiVendor extends AbstractVendor {

	protected string $vendor_id;
	protected string $vendor_name;

	public function __construct( string $vendor_id, string $vendor_name, array $config = [] ) {
		$this->vendor_id   = $vendor_id;
		$this->vendor_name = $vendor_name;
		parent::__construct( $config );
	}

	protected function get_default_config(): array {
		return array_merge( parent::get_default_config(), [
			'api_key' => '',
		] );
	}

	public function get_id(): string {
		return $this->vendor_id;
	}

	public function get_info(): array {
		return [
			'id'           => $this->vendor_id,
			'name'         => $this->vendor_name,
			'url'          => $this->config['api_url'],
			'api_type'     => 'bridge_api',
			'requires_key' => true,
		];
	}

	protected function get_request_headers(): array {
		$headers = parent::get_request_headers();

		if ( ! empty( $this->config['api_key'] ) ) {
			$headers['X-WPBridge-API-Key'] = $this->config['api_key'];
		}

		return $headers;
	}

	public function verify_credentials(): bool {
		$cache_key = 'credentials_valid';
		$cached    = $this->get_cache( $cache_key );
		if ( $cached !== null ) {
			return (bool) $cached;
		}

		$response = $this->api_request( 'wp-json/bridge/v1/sources' );

		$valid = $response !== null;
		$this->set_cache( $cache_key, $valid, 300 );
		return $valid;
	}

	public function get_plugins( int $page = 1, int $limit = 100 ): array {
		$cache_key = "plugins_page_{$page}_limit_{$limit}";
		$cached    = $this->get_cache( $cache_key );
		if ( $cached !== null ) {
			return $cached;
		}

		// 获取所有源，筛选 plugin 类型
		$sources = $this->api_request( 'wp-json/bridge/v1/sources' );
		if ( $sources === null ) {
			return [ 'plugins' => [], 'total' => 0, 'pages' => 0 ];
		}

		$plugins = [];
		foreach ( $sources as $source ) {
			$item_type = $source['item_type'] ?? $source['type'] ?? '';
			if ( $item_type !== 'plugin' ) {
				continue;
			}

			$slug = $source['slug'] ?? '';
			if ( empty( $slug ) ) {
				continue;
			}

			// 直接用 source 数据标准化，避免 N+1 HTTP 请求
			// 详细信息在 get_plugin() 时按需懒加载
			$plugins[] = $this->normalize_plugin( $source );
		}

		$result = [
			'plugins' => $plugins,
			'total'   => count( $plugins ),
			'pages'   => 1,
		];

		$this->set_cache( $cache_key, $result );
		return $result;
	}

	public function get_plugin( string $slug ): ?array {
		$cache_key = 'plugin_' . $slug;
		$cached    = $this->get_cache( $cache_key );
		if ( $cached !== null ) {
			return $cached;
		}

		$plugin_info = $this->api_request( 'wp-json/bridge/v1/plugins/' . $slug . '/info' );
		if ( $plugin_info === null ) {
			return null;
		}

		$normalized = $this->normalize_plugin( $plugin_info );
		$this->set_cache( $cache_key, $normalized );
		return $normalized;
	}

	public function check_update( string $slug, string $current_version ): ?array {
		$plugin = $this->get_plugin( $slug );
		if ( $plugin === null ) {
			return null;
		}

		$latest_version = $plugin['version'] ?? '';
		if ( empty( $latest_version ) ) {
			return null;
		}

		if ( version_compare( $latest_version, $current_version, '<=' ) ) {
			return null;
		}

		return [
			'version'      => $latest_version,
			'download_url' => $plugin['download_url'] ?? '',
			'changelog'    => '',
			'tested'       => $plugin['tested'] ?? '',
			'requires'     => $plugin['requires'] ?? '',
			'requires_php' => $plugin['requires_php'] ?? '',
		];
	}

	public function get_download_url( string $slug, string $version = '' ): ?string {
		$plugin = $this->get_plugin( $slug );
		if ( $plugin === null ) {
			return null;
		}

		return $plugin['download_url'] ?? null;
	}
}
