<?php
/**
 * 商业插件桥接管理器
 *
 * 管理桥接插件列表，提供启用/禁用功能
 *
 * @package WPBridge
 * @since 0.9.7
 */

declare(strict_types=1);

namespace WPBridge\Commercial;

use WPBridge\Core\Settings;
use WPBridge\Core\RemoteConfig;
use WPBridge\Core\Logger;
use WPBridge\Commercial\Vendors\VendorManager;
use WPBridge\Security\Encryption;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BridgeManager 类
 */
class BridgeManager {

	/**
	 * 设置实例
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * 远程配置实例
	 *
	 * @var RemoteConfig
	 */
	private RemoteConfig $remote_config;

	/**
	 * GPL 验证器
	 *
	 * @var GPLValidator
	 */
	private GPLValidator $gpl_validator;

	/**
	 * 供应商管理器
	 *
	 * @var VendorManager
	 */
	private VendorManager $vendor_manager;

	/**
	 * Bridge Server 客户端
	 *
	 * @var BridgeClient|null
	 */
	private ?BridgeClient $bridge_client = null;

	/**
	 * 构造函数
	 *
	 * @param Settings     $settings      设置实例
	 * @param RemoteConfig $remote_config 远程配置实例
	 */
	public function __construct( Settings $settings, RemoteConfig $remote_config ) {
		$this->settings       = $settings;
		$this->remote_config  = $remote_config;
		$this->gpl_validator  = new GPLValidator();
		$this->vendor_manager = new VendorManager();

		// 初始化 Bridge Server 客户端
		$this->init_bridge_client();

		// 初始化已配置的供应商
		$this->init_vendors();
	}

	/**
	 * 初始化 Bridge Server 客户端
	 *
	 * @return void
	 */
	private function init_bridge_client(): void {
		$server_url = $this->settings->get( 'bridge_server_url', '' );
		// API Key 使用加密存储
		$api_key = Encryption::get_secure( 'bridge_server_api_key', '' );

		if ( ! empty( $server_url ) ) {
			$this->bridge_client = new BridgeClient( $server_url, $api_key );
		}
	}

	/**
	 * 获取 Bridge Server 客户端
	 *
	 * @return BridgeClient|null
	 */
	public function get_bridge_client(): ?BridgeClient {
		return $this->bridge_client;
	}

	/**
	 * 设置 Bridge Server 配置
	 *
	 * @param string $server_url 服务端 URL
	 * @param string $api_key    API Key
	 * @return array
	 */
	public function set_bridge_server( string $server_url, string $api_key ): array {
		// 验证连接
		$client = new BridgeClient( $server_url, $api_key );

		if ( ! $client->health_check() ) {
			return [
				'success' => false,
				'message' => __( '无法连接到 Bridge Server', 'wpbridge' ),
			];
		}

		// 保存配置（URL 明文存储，API Key 加密存储）
		$this->settings->set( 'bridge_server_url', $server_url );
		Encryption::store_secure( 'bridge_server_api_key', $api_key );

		$this->bridge_client = $client;

		Logger::info( 'Bridge server configured', [ 'url' => $server_url ] );

		return [
			'success' => true,
			'message' => __( 'Bridge Server 配置成功', 'wpbridge' ),
		];
	}

	/**
	 * 初始化供应商
	 *
	 * @return void
	 */
	private function init_vendors(): void {
		$vendor_configs = $this->settings->get( 'vendors', [] );

		foreach ( $vendor_configs as $vendor_id => $config ) {
			if ( empty( $config['enabled'] ) ) {
				continue;
			}

			$type = $config['type'] ?? 'woocommerce';

			switch ( $type ) {
				case 'woocommerce':
					$vendor = new Vendors\WooCommerceVendor(
						$vendor_id,
						$config['name'] ?? $vendor_id,
						$config['api_url'] ?? '',
						$config['consumer_key'] ?? '',
						$config['consumer_secret'] ?? ''
					);
					$this->vendor_manager->register( $vendor );
					break;
				// 未来可扩展其他供应商类型
			}
		}
	}

	/**
	 * 获取可桥接的商业插件列表（从服务端）
	 *
	 * 优先从 Bridge Server 获取，回退到 RemoteConfig
	 *
	 * @return array
	 */
	public function get_available_plugins(): array {
		// 优先使用 Bridge Server
		if ( $this->bridge_client && $this->bridge_client->is_configured() ) {
			$plugins = $this->bridge_client->list_plugins();
			if ( ! empty( $plugins ) ) {
				// 转换为 slug => info 格式
				$result = [];
				foreach ( $plugins as $plugin ) {
					$result[ $plugin['slug'] ] = $plugin;
				}
				return $result;
			}
		}

		// 回退到 RemoteConfig
		return $this->remote_config->get( 'bridgeable_plugins', [] );
	}

	/**
	 * 获取插件下载 URL
	 *
	 * @param string $slug 插件 slug
	 * @return string|null
	 */
	public function get_plugin_download_url( string $slug ): ?string {
		if ( $this->bridge_client && $this->bridge_client->is_configured() ) {
			return $this->bridge_client->get_download_url( $slug );
		}

		return null;
	}

	/**
	 * 获取所有可用插件（混合模式）
	 *
	 * 合并三个来源：
	 * 1. 官方优化列表（服务端）
	 * 2. 供应商渠道插件
	 * 3. 用户自定义插件
	 *
	 * @return array
	 */
	public function get_all_available_plugins(): array {
		$plugins = [];

		// 1. 官方优化列表
		$official = $this->get_available_plugins();
		foreach ( $official as $slug => $info ) {
			$plugins[ $slug ] = array_merge( $info, [
				'source' => 'official',
				'vendor' => null,
			] );
		}

		// 2. 供应商渠道插件
		$vendor_plugins = $this->vendor_manager->get_all_plugins();
		foreach ( $vendor_plugins as $slug => $info ) {
			if ( ! isset( $plugins[ $slug ] ) ) {
				$plugins[ $slug ] = $info;
			} else {
				// 官方列表优先，但记录供应商也有
				$plugins[ $slug ]['also_available_from'] = $info['vendor'];
			}
		}

		// 3. 用户自定义插件
		$custom = $this->settings->get( 'custom_plugins', [] );
		foreach ( $custom as $slug => $info ) {
			if ( ! isset( $plugins[ $slug ] ) ) {
				$plugins[ $slug ] = array_merge( $info, [
					'source' => 'custom',
					'vendor' => null,
				] );
			}
		}

		return $plugins;
	}

	/**
	 * 获取供应商管理器
	 *
	 * @return VendorManager
	 */
	public function get_vendor_manager(): VendorManager {
		return $this->vendor_manager;
	}

	/**
	 * 获取已启用桥接的插件
	 *
	 * @return array
	 */
	public function get_bridged_plugins(): array {
		return $this->settings->get( 'bridged_plugins', [] );
	}

	/**
	 * 启用插件桥接
	 *
	 * @param string $plugin_slug 插件 slug
	 * @param string $plugin_file 插件文件路径（可选，用于 GPL 验证）
	 * @return array 包含 success 和 message 的结果
	 */
	public function enable_bridge( string $plugin_slug, string $plugin_file = '' ): array {
		// 1. 检查是否在可桥接列表（混合模式）
		$all_available = $this->get_all_available_plugins();
		if ( ! isset( $all_available[ $plugin_slug ] ) ) {
			return [
				'success' => false,
				'message' => __( '该插件不在可桥接列表中', 'wpbridge' ),
				'code'    => 'not_available',
			];
		}

		$plugin_info = $all_available[ $plugin_slug ];

		// 2. H5 修复: GPL 合规验证
		$gpl_result = $this->gpl_validator->validate( $plugin_slug, $plugin_file );
		if ( $gpl_result['is_gpl'] === false ) {
			Logger::warning( 'GPL validation failed', [
				'plugin' => $plugin_slug,
				'result' => $gpl_result,
			] );
			return [
				'success' => false,
				'message' => __( '该插件不是 GPL 授权，无法桥接', 'wpbridge' ),
				'code'    => 'not_gpl',
				'license' => $gpl_result['license'],
			];
		}

		if ( $gpl_result['is_gpl'] === null && $gpl_result['confidence'] < 50 ) {
			// 无法确定，但置信度低，警告用户
			Logger::info( 'GPL validation uncertain', [
				'plugin' => $plugin_slug,
				'result' => $gpl_result,
			] );
		}

		// 3. 检查订阅限制
		$limit_check = $this->check_subscription_limit();
		if ( ! $limit_check['allowed'] ) {
			return [
				'success' => false,
				'message' => $limit_check['message'],
				'code'    => 'limit_exceeded',
			];
		}

		// 4. 添加到桥接列表
		$bridged = $this->get_bridged_plugins();
		if ( ! in_array( $plugin_slug, $bridged, true ) ) {
			$bridged[] = $plugin_slug;
			$this->settings->set( 'bridged_plugins', $bridged );

			Logger::info( 'Plugin bridge enabled', [
				'plugin'     => $plugin_slug,
				'gpl_result' => $gpl_result,
			] );
		}

		return [
			'success'     => true,
			'message'     => __( '桥接已启用', 'wpbridge' ),
			'code'        => 'enabled',
			'gpl_result'  => $gpl_result,
			'source'      => $plugin_info['source'] ?? 'official',
			'vendor'      => $plugin_info['vendor'] ?? null,
		];
	}

	/**
	 * 禁用插件桥接
	 *
	 * @param string $plugin_slug 插件 slug
	 * @return array
	 */
	public function disable_bridge( string $plugin_slug ): array {
		$bridged = $this->get_bridged_plugins();
		$bridged = array_diff( $bridged, [ $plugin_slug ] );
		$result  = $this->settings->set( 'bridged_plugins', array_values( $bridged ) );

		if ( $result ) {
			Logger::info( 'Plugin bridge disabled', [ 'plugin' => $plugin_slug ] );
			return [
				'success' => true,
				'message' => __( '桥接已禁用', 'wpbridge' ),
			];
		}

		return [
			'success' => false,
			'message' => __( '禁用失败', 'wpbridge' ),
		];
	}

	/**
	 * 检查插件是否已桥接
	 *
	 * @param string $plugin_slug 插件 slug
	 * @return bool
	 */
	public function is_bridged( string $plugin_slug ): bool {
		return in_array( $plugin_slug, $this->get_bridged_plugins(), true );
	}

	/**
	 * 检查订阅限制
	 *
	 * @return array
	 */
	private function check_subscription_limit(): array {
		$subscription = $this->get_subscription();

		// Agency 计划无限制
		if ( $subscription['plan'] === 'agency' ) {
			return [ 'allowed' => true ];
		}

		$current_count = count( $this->get_bridged_plugins() );
		$limit         = $subscription['plugins_limit'] ?? 5;

		if ( $current_count >= $limit ) {
			return [
				'allowed' => false,
				'message' => sprintf(
					/* translators: %d: plugin limit */
					__( '已达到插件数量限制 (%d)，请升级订阅', 'wpbridge' ),
					$limit
				),
			];
		}

		return [ 'allowed' => true ];
	}

	/**
	 * 获取订阅信息
	 *
	 * @return array
	 */
	public function get_subscription(): array {
		$default = [
			'plan'          => 'free',
			'plugins_limit' => 0,
			'site_limit'    => 1,
			'status'        => 'active',
			'expires_at'    => null,
		];

		$subscription = $this->settings->get( 'subscription', [] );
		return array_merge( $default, $subscription );
	}

	/**
	 * 获取桥接状态统计
	 *
	 * @return array
	 */
	public function get_stats(): array {
		$subscription = $this->get_subscription();
		$bridged      = $this->get_bridged_plugins();
		$available    = $this->get_available_plugins();

		return [
			'bridged_count'   => count( $bridged ),
			'available_count' => count( $available ),
			'plan'            => $subscription['plan'],
			'plugins_limit'   => $subscription['plugins_limit'],
			'plugins_used'    => count( $bridged ),
			'can_add_more'    => $subscription['plan'] === 'agency' || count( $bridged ) < $subscription['plugins_limit'],
		];
	}

	/**
	 * 获取已安装的可桥接插件
	 *
	 * @return array
	 */
	public function get_installed_bridgeable_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$available   = $this->get_available_plugins();
		$bridged     = $this->get_bridged_plugins();
		$result      = [];

		foreach ( $all_plugins as $file => $data ) {
			$slug = dirname( $file );
			if ( $slug === '.' ) {
				$slug = basename( $file, '.php' );
			}

			if ( isset( $available[ $slug ] ) ) {
				$gpl_result = $this->gpl_validator->validate( $slug, $file );

				$result[ $slug ] = [
					'file'       => $file,
					'name'       => $data['Name'],
					'version'    => $data['Version'],
					'is_bridged' => in_array( $slug, $bridged, true ),
					'gpl_status' => $gpl_result,
					'available'  => $available[ $slug ],
				];
			}
		}

		return $result;
	}

	/**
	 * 同步可桥接插件列表
	 *
	 * @return bool
	 */
	public function sync_available_plugins(): bool {
		return $this->remote_config->refresh();
	}

	/**
	 * 添加供应商
	 *
	 * @param string $vendor_id       供应商 ID
	 * @param string $name            供应商名称
	 * @param string $type            供应商类型 (woocommerce)
	 * @param string $api_url         API 地址
	 * @param string $consumer_key    Consumer Key
	 * @param string $consumer_secret Consumer Secret
	 * @return array
	 */
	public function add_vendor(
		string $vendor_id,
		string $name,
		string $type,
		string $api_url,
		string $consumer_key,
		string $consumer_secret
	): array {
		$vendors = $this->settings->get( 'vendors', [] );

		if ( isset( $vendors[ $vendor_id ] ) ) {
			return [
				'success' => false,
				'message' => __( '供应商 ID 已存在', 'wpbridge' ),
			];
		}

		$vendors[ $vendor_id ] = [
			'name'            => $name,
			'type'            => $type,
			'api_url'         => $api_url,
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
			'enabled'         => true,
			'created_at'      => time(),
		];

		$this->settings->set( 'vendors', $vendors );

		// 立即注册供应商
		if ( $type === 'woocommerce' ) {
			$vendor = new Vendors\WooCommerceVendor(
				$vendor_id,
				$name,
				$api_url,
				$consumer_key,
				$consumer_secret
			);
			$this->vendor_manager->register( $vendor );
		}

		Logger::info( 'Vendor added', [ 'vendor_id' => $vendor_id, 'type' => $type ] );

		return [
			'success' => true,
			'message' => __( '供应商已添加', 'wpbridge' ),
		];
	}

	/**
	 * 移除供应商
	 *
	 * @param string $vendor_id 供应商 ID
	 * @return array
	 */
	public function remove_vendor( string $vendor_id ): array {
		$vendors = $this->settings->get( 'vendors', [] );

		if ( ! isset( $vendors[ $vendor_id ] ) ) {
			return [
				'success' => false,
				'message' => __( '供应商不存在', 'wpbridge' ),
			];
		}

		unset( $vendors[ $vendor_id ] );
		$this->settings->set( 'vendors', $vendors );
		$this->vendor_manager->unregister( $vendor_id );

		Logger::info( 'Vendor removed', [ 'vendor_id' => $vendor_id ] );

		return [
			'success' => true,
			'message' => __( '供应商已移除', 'wpbridge' ),
		];
	}

	/**
	 * 获取所有供应商
	 *
	 * @return array
	 */
	public function get_vendors(): array {
		return $this->settings->get( 'vendors', [] );
	}

	/**
	 * 测试供应商连接
	 *
	 * @param string $vendor_id 供应商 ID
	 * @return array
	 */
	public function test_vendor_connection( string $vendor_id ): array {
		$vendor = $this->vendor_manager->get( $vendor_id );

		if ( ! $vendor ) {
			return [
				'success' => false,
				'message' => __( '供应商不存在或未启用', 'wpbridge' ),
			];
		}

		$result = $vendor->test_connection();

		return [
			'success' => $result,
			'message' => $result
				? __( '连接成功', 'wpbridge' )
				: __( '连接失败', 'wpbridge' ),
		];
	}

	/**
	 * 添加自定义插件
	 *
	 * @param string $plugin_slug 插件 slug
	 * @param array  $info        插件信息
	 * @return array
	 */
	public function add_custom_plugin( string $plugin_slug, array $info ): array {
		$custom = $this->settings->get( 'custom_plugins', [] );

		$custom[ $plugin_slug ] = array_merge( $info, [
			'added_at' => time(),
		] );

		$this->settings->set( 'custom_plugins', $custom );

		Logger::info( 'Custom plugin added', [ 'plugin' => $plugin_slug ] );

		return [
			'success' => true,
			'message' => __( '自定义插件已添加', 'wpbridge' ),
		];
	}

	/**
	 * 移除自定义插件
	 *
	 * @param string $plugin_slug 插件 slug
	 * @return array
	 */
	public function remove_custom_plugin( string $plugin_slug ): array {
		$custom = $this->settings->get( 'custom_plugins', [] );

		if ( ! isset( $custom[ $plugin_slug ] ) ) {
			return [
				'success' => false,
				'message' => __( '自定义插件不存在', 'wpbridge' ),
			];
		}

		unset( $custom[ $plugin_slug ] );
		$this->settings->set( 'custom_plugins', $custom );

		return [
			'success' => true,
			'message' => __( '自定义插件已移除', 'wpbridge' ),
		];
	}
}
