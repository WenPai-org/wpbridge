<?php
/**
 * 商业插件授权代理
 *
 * 拦截商业插件的授权验证请求，转发到文派授权服务
 *
 * @package WPBridge
 * @since 0.9.7
 */

declare(strict_types=1);

namespace WPBridge\Commercial;

use WPBridge\Core\Logger;
use WPBridge\Core\Settings;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LicenseProxy 类
 */
class LicenseProxy {

	/**
	 * 支持的授权系统配置
	 */
	private const VENDORS = [
		'edd'      => [
			'name'            => 'EDD Software Licensing',
			'patterns'        => [
				'/edd-sl/',
				'/edd-api/',
				'action=activate_license',
				'action=check_license',
				'action=deactivate_license',
			],
			'response_format' => 'edd',
		],
		'freemius' => [
			'name'            => 'Freemius',
			'patterns'        => [
				'api.freemius.com',
				'wp-json/freemius',
			],
			'response_format' => 'freemius',
		],
		'wc_am'    => [
			'name'            => 'WooCommerce API Manager',
			'patterns'        => [
				'wc-api/wc-am-api',
				'wc-api/am-software-api',
			],
			'response_format' => 'wc_am',
		],
	];

	/**
	 * 敏感参数列表（用于日志过滤）
	 */
	private const SENSITIVE_PARAMS = [
		'license_key',
		'license',
		'key',
		'password',
		'secret',
		'token',
		'api_key',
		'apikey',
	];

	/**
	 * 设置实例
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * 已桥接的插件列表
	 *
	 * @var array
	 */
	private array $bridged_plugins = [];

	/**
	 * 构造函数
	 *
	 * @param Settings $settings 设置实例
	 */
	public function __construct( Settings $settings ) {
		$this->settings        = $settings;
		$this->bridged_plugins = $this->settings->get( 'bridged_plugins', [] );
	}

	/**
	 * 初始化钩子
	 */
	public function init(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		add_filter( 'pre_http_request', [ $this, 'intercept_request' ], 5, 3 );
	}

	/**
	 * 检查是否启用
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		return (bool) $this->settings->get( 'license_proxy_enabled', false );
	}

	/**
	 * 拦截 HTTP 请求
	 *
	 * @param false|array|\WP_Error $preempt 预处理结果
	 * @param array                 $args    请求参数
	 * @param string                $url     请求 URL
	 * @return false|array|\WP_Error
	 */
	public function intercept_request( $preempt, array $args, string $url ) {
		// 1. 检测授权系统
		$vendor = $this->detect_vendor( $url );
		if ( $vendor === null ) {
			return $preempt;
		}

		// 2. 提取插件标识
		$plugin_slug = $this->extract_plugin_slug( $url, $args, $vendor );
		if ( $plugin_slug === null ) {
			return $preempt;
		}

		// 3. 检查是否在桥接列表
		if ( ! $this->is_bridged( $plugin_slug ) ) {
			return $preempt;
		}

		// H2 修复: 过滤敏感信息后再记录日志
		Logger::debug( 'License proxy intercepting', [
			'vendor' => $vendor,
			'plugin' => $plugin_slug,
			'url'    => $this->sanitize_url_for_log( $url ),
		] );

		// 4. 代理到文派服务
		return $this->proxy_request( $vendor, $plugin_slug, $url, $args );
	}

	/**
	 * H2 修复: 过滤 URL 中的敏感参数
	 *
	 * @param string $url 原始 URL
	 * @return string 过滤后的 URL
	 */
	private function sanitize_url_for_log( string $url ): string {
		$pattern = '/(' . implode( '|', self::SENSITIVE_PARAMS ) . ')=[^&]+/i';
		return preg_replace( $pattern, '$1=[REDACTED]', $url );
	}

	/**
	 * H2 修复: 过滤请求体中的敏感参数
	 *
	 * @param array $body 请求体
	 * @return array 过滤后的请求体
	 */
	private function sanitize_body_for_log( array $body ): array {
		$sanitized = [];
		foreach ( $body as $key => $value ) {
			if ( in_array( strtolower( $key ), self::SENSITIVE_PARAMS, true ) ) {
				$sanitized[ $key ] = '[REDACTED]';
			} else {
				$sanitized[ $key ] = $value;
			}
		}
		return $sanitized;
	}

	/**
	 * 检测授权系统供应商
	 *
	 * @param string $url 请求 URL
	 * @return string|null
	 */
	private function detect_vendor( string $url ): ?string {
		foreach ( self::VENDORS as $vendor_key => $config ) {
			foreach ( $config['patterns'] as $pattern ) {
				if ( stripos( $url, $pattern ) !== false ) {
					return $vendor_key;
				}
			}
		}
		return null;
	}

	/**
	 * 提取插件 slug
	 *
	 * @param string $url    请求 URL
	 * @param array  $args   请求参数
	 * @param string $vendor 供应商
	 * @return string|null
	 */
	private function extract_plugin_slug( string $url, array $args, string $vendor ): ?string {
		// 从 URL 参数提取
		$parsed = wp_parse_url( $url );
		if ( isset( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query );

			// EDD 格式
			if ( isset( $query['item_name'] ) ) {
				return sanitize_title( $query['item_name'] );
			}
			if ( isset( $query['item_id'] ) ) {
				return $this->resolve_item_id( (string) $query['item_id'] );
			}
		}

		// 从 POST body 提取
		if ( isset( $args['body'] ) && is_array( $args['body'] ) ) {
			if ( isset( $args['body']['item_name'] ) ) {
				return sanitize_title( $args['body']['item_name'] );
			}
			if ( isset( $args['body']['product_id'] ) ) {
				return $this->resolve_item_id( (string) $args['body']['product_id'] );
			}
		}

		// Freemius 格式: /v1/plugins/{id}/...
		if ( $vendor === 'freemius' && preg_match( '#/plugins/(\d+)/#', $url, $matches ) ) {
			return $this->resolve_freemius_id( $matches[1] );
		}

		return null;
	}

	/**
	 * 解析 item_id 到 slug
	 *
	 * @param string $item_id 项目 ID
	 * @return string|null
	 */
	private function resolve_item_id( string $item_id ): ?string {
		// 从远程配置获取 ID 到 slug 的映射
		$mapping = $this->settings->get( 'item_id_mapping', [] );
		return $mapping[ $item_id ] ?? null;
	}

	/**
	 * 解析 Freemius ID 到 slug
	 *
	 * @param string $freemius_id Freemius ID
	 * @return string|null
	 */
	private function resolve_freemius_id( string $freemius_id ): ?string {
		$mapping = $this->settings->get( 'freemius_id_mapping', [] );
		return $mapping[ $freemius_id ] ?? null;
	}

	/**
	 * 检查插件是否在桥接列表
	 *
	 * @param string $plugin_slug 插件 slug
	 * @return bool
	 */
	private function is_bridged( string $plugin_slug ): bool {
		return in_array( $plugin_slug, $this->bridged_plugins, true );
	}

	/**
	 * H1 修复: 生成安全的站点指纹
	 *
	 * 使用多因素生成站点指纹，防止伪造
	 *
	 * @return string
	 */
	private function generate_site_fingerprint(): string {
		$factors = [
			home_url(),
			defined( 'DB_NAME' ) ? DB_NAME : '',
			defined( 'AUTH_KEY' ) ? AUTH_KEY : '',
			defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '',
			php_uname( 'n' ), // 主机名
		];

		return hash( 'sha256', implode( '|', $factors ) );
	}

	/**
	 * H3 修复: 生成请求签名
	 *
	 * @param string $api_key     API Key
	 * @param string $plugin_slug 插件 slug
	 * @param string $action      操作类型
	 * @param string $timestamp   时间戳
	 * @return string
	 */
	private function generate_request_signature( string $api_key, string $plugin_slug, string $action, string $timestamp ): string {
		$data = implode( '|', [
			$plugin_slug,
			$this->generate_site_fingerprint(),
			$action,
			$timestamp,
		] );

		return hash_hmac( 'sha256', $data, $api_key );
	}

	/**
	 * 代理请求到文派服务
	 *
	 * @param string $vendor       供应商
	 * @param string $plugin_slug  插件 slug
	 * @param string $original_url 原始 URL
	 * @param array  $args         请求参数
	 * @return array|false
	 */
	private function proxy_request( string $vendor, string $plugin_slug, string $original_url, array $args ) {
		$proxy_url = $this->settings->get(
			'license_proxy_url',
			'https://updates.wenpai.net/api/v1/license/proxy'
		);

		$api_key   = $this->get_api_key();
		$timestamp = (string) time();
		$action    = $this->extract_action( $original_url, $args );

		// H1 + H3 修复: 使用站点指纹和请求签名
		$site_fingerprint = $this->generate_site_fingerprint();
		$signature        = $this->generate_request_signature( $api_key, $plugin_slug, $action, $timestamp );

		$response = wp_remote_post( $proxy_url, [
			'timeout' => 15,
			'headers' => [
				'Content-Type'           => 'application/json',
				'X-WPBridge-Key'         => $api_key,
				'X-WPBridge-Signature'   => $signature,
				'X-WPBridge-Timestamp'   => $timestamp,
				'X-WPBridge-Fingerprint' => $site_fingerprint,
			],
			'body'    => wp_json_encode( [
				'vendor'           => $vendor,
				'plugin_slug'      => $plugin_slug,
				'action'           => $action,
				'site_url'         => home_url(),
				'site_fingerprint' => $site_fingerprint,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( 'License proxy failed', [
				'error' => $response->get_error_message(),
			] );
			// 失败时不拦截，让原始请求继续
			return false;
		}

		// 转换响应格式
		return $this->transform_response( $vendor, $plugin_slug, $response );
	}

	/**
	 * H4 修复: 转换响应格式以匹配原厂 API
	 *
	 * @param string $vendor       供应商
	 * @param string $plugin_slug  插件 slug
	 * @param array  $response     响应
	 * @return array|false
	 */
	private function transform_response( string $vendor, string $plugin_slug, array $response ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['success'] ) || ! $body['success'] ) {
			return false; // 让原始请求继续
		}

		$license = $body['license'] ?? [];

		// 根据不同授权系统返回不同格式
		switch ( $vendor ) {
			case 'edd':
				return $this->format_edd_response( $license, $plugin_slug );
			case 'freemius':
				return $this->format_freemius_response( $license, $plugin_slug );
			case 'wc_am':
				return $this->format_wc_am_response( $license );
			default:
				return $this->format_generic_response( $license );
		}
	}

	/**
	 * H4 修复: 格式化 EDD 响应（完整字段）
	 *
	 * @param array  $license     授权信息
	 * @param string $plugin_slug 插件 slug
	 * @return array
	 */
	private function format_edd_response( array $license, string $plugin_slug ): array {
		// 获取插件特定的响应配置
		$plugin_config = $this->get_plugin_response_config( $plugin_slug, 'edd' );

		$body = wp_json_encode( array_merge( [
			'success'          => true,
			'license'          => $license['status'] ?? 'valid',
			'item_id'          => $license['item_id'] ?? '',
			'item_name'        => $license['item_name'] ?? $plugin_config['item_name'] ?? '',
			'license_limit'    => $license['license_limit'] ?? 0,
			'site_count'       => $license['site_count'] ?? 1,
			'expires'          => $license['expires'] ?? 'lifetime',
			'activations_left' => $license['activations_left'] ?? 'unlimited',
			'checksum'         => $license['checksum'] ?? $this->generate_checksum( $license ),
			'payment_id'       => $license['payment_id'] ?? 0,
			'customer_name'    => $license['customer_name'] ?? '',
			'customer_email'   => $license['customer_email'] ?? '',
			'price_id'         => $license['price_id'] ?? false,
		], $plugin_config['extra_fields'] ?? [] ) );

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => $body,
			'headers'  => [ 'content-type' => 'application/json' ],
		];
	}

	/**
	 * H4 修复: 格式化 Freemius 响应（完整字段）
	 *
	 * @param array  $license     授权信息
	 * @param string $plugin_slug 插件 slug
	 * @return array
	 */
	private function format_freemius_response( array $license, string $plugin_slug ): array {
		$plugin_config = $this->get_plugin_response_config( $plugin_slug, 'freemius' );

		$body = wp_json_encode( array_merge( [
			'id'                => $license['id'] ?? 0,
			'plugin_id'         => $license['plugin_id'] ?? $plugin_config['plugin_id'] ?? 0,
			'user_id'           => $license['user_id'] ?? 0,
			'plan_id'           => $license['plan_id'] ?? $plugin_config['plan_id'] ?? 0,
			'pricing_id'        => $license['pricing_id'] ?? 0,
			'quota'             => $license['license_limit'] ?? null,
			'activated'         => $license['site_count'] ?? 1,
			'activated_local'   => 1,
			'expiration'        => $license['expires'] ?? null,
			'secret_key'        => $license['secret_key'] ?? $this->generate_secret_key(),
			'public_key'        => $license['public_key'] ?? $plugin_config['public_key'] ?? '',
			'is_free_localhost' => false,
			'is_block_features' => false,
			'is_cancelled'      => false,
			'is_whitelabeled'   => $license['is_whitelabeled'] ?? false,
		], $plugin_config['extra_fields'] ?? [] ) );

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => $body,
			'headers'  => [ 'content-type' => 'application/json' ],
		];
	}

	/**
	 * 格式化 WC API Manager 响应
	 *
	 * @param array $license 授权信息
	 * @return array
	 */
	private function format_wc_am_response( array $license ): array {
		$body = wp_json_encode( [
			'success'              => true,
			'status_check'         => 'active',
			'data'                 => 'active',
			'activations'          => (string) ( $license['site_count'] ?? 1 ),
			'activations_limit'    => (string) ( $license['license_limit'] ?? 'unlimited' ),
			'activations_remaining'=> (string) ( $license['activations_left'] ?? 'unlimited' ),
		] );

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => $body,
			'headers'  => [ 'content-type' => 'application/json' ],
		];
	}

	/**
	 * 格式化通用响应
	 *
	 * @param array $license 授权信息
	 * @return array
	 */
	private function format_generic_response( array $license ): array {
		$body = wp_json_encode( [
			'success' => true,
			'license' => $license['status'] ?? 'valid',
			'expires' => $license['expires'] ?? 'lifetime',
		] );

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => $body,
			'headers'  => [ 'content-type' => 'application/json' ],
		];
	}

	/**
	 * H4 修复: 获取插件特定的响应配置
	 *
	 * @param string $plugin_slug 插件 slug
	 * @param string $vendor      供应商
	 * @return array
	 */
	private function get_plugin_response_config( string $plugin_slug, string $vendor ): array {
		$configs = $this->settings->get( 'plugin_response_configs', [] );
		return $configs[ $plugin_slug ][ $vendor ] ?? [];
	}

	/**
	 * 生成校验和
	 *
	 * @param array $license 授权信息
	 * @return string
	 */
	private function generate_checksum( array $license ): string {
		return md5( wp_json_encode( $license ) . home_url() );
	}

	/**
	 * 生成 Freemius secret_key
	 *
	 * @return string
	 */
	private function generate_secret_key(): string {
		return 'sk_' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * 获取 API Key
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		return $this->settings->get( 'wenpai_api_key', '' );
	}

	/**
	 * 提取操作类型
	 *
	 * @param string $url  请求 URL
	 * @param array  $args 请求参数
	 * @return string
	 */
	private function extract_action( string $url, array $args ): string {
		// 从 URL 提取
		if ( preg_match( '/action=(\w+)/', $url, $matches ) ) {
			return $matches[1];
		}

		// 从 body 提取
		if ( isset( $args['body']['edd_action'] ) ) {
			return $args['body']['edd_action'];
		}
		if ( isset( $args['body']['wc-api'] ) ) {
			return $args['body']['request'] ?? 'status';
		}

		return 'check_license';
	}
}
