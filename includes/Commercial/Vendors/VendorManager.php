<?php
/**
 * 供应商管理器
 *
 * 管理所有第三方 GPL 插件供应商
 *
 * @package WPBridge
 * @since 0.9.7
 */

declare(strict_types=1);

namespace WPBridge\Commercial\Vendors;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VendorManager 类
 */
class VendorManager {

	/**
	 * 设置实例
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * 已注册的供应商
	 *
	 * @var VendorInterface[]
	 */
	private array $vendors = [];

	/**
	 * 单例实例
	 *
	 * @var VendorManager|null
	 */
	private static ?VendorManager $instance = null;

	/**
	 * 获取单例实例
	 *
	 * @param Settings|null $settings 设置实例
	 * @return VendorManager
	 */
	public static function get_instance( ?Settings $settings = null ): VendorManager {
		if ( self::$instance === null ) {
			self::$instance = new self( $settings ?? new Settings() );
		}
		return self::$instance;
	}

	/**
	 * 构造函数
	 *
	 * @param Settings $settings 设置实例
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->load_vendors();
	}

	/**
	 * 加载已配置的供应商
	 */
	private function load_vendors(): void {
		$vendor_configs = $this->settings->get( 'vendors', [] );

		foreach ( $vendor_configs as $vendor_id => $config ) {
			if ( empty( $config['enabled'] ) ) {
				continue;
			}

			$vendor = $this->create_vendor( $vendor_id, $config );
			if ( $vendor !== null ) {
				$this->vendors[ $vendor_id ] = $vendor;
			}
		}

		// 允许通过 hook 注册额外供应商
		do_action( 'wpbridge_register_vendors', $this );
	}

	/**
	 * 创建供应商实例
	 *
	 * @param string $vendor_id 供应商 ID
	 * @param array  $config    配置
	 * @return VendorInterface|null
	 */
	private function create_vendor( string $vendor_id, array $config ): ?VendorInterface {
		$type = $config['type'] ?? 'woocommerce';
		$name = $config['name'] ?? $vendor_id;

		switch ( $type ) {
			case 'woocommerce':
			case 'wc_am':
				return new WooCommerceVendor( $vendor_id, $name, $config );

			// 未来可以添加更多类型
			// case 'edd':
			//     return new EDDVendor($vendor_id, $name, $config);

			default:
				Logger::warning( 'Unknown vendor type', [
					'vendor_id' => $vendor_id,
					'type'      => $type,
				] );
				return null;
		}
	}

	/**
	 * 注册供应商
	 *
	 * @param VendorInterface $vendor 供应商实例
	 */
	public function register( VendorInterface $vendor ): void {
		$this->vendors[ $vendor->get_id() ] = $vendor;
	}

	/**
	 * 获取供应商
	 *
	 * @param string $vendor_id 供应商 ID
	 * @return VendorInterface|null
	 */
	public function get_vendor( string $vendor_id ): ?VendorInterface {
		return $this->vendors[ $vendor_id ] ?? null;
	}

	/**
	 * 获取所有供应商
	 *
	 * @param bool $only_available 只返回可用的供应商
	 * @return VendorInterface[]
	 */
	public function get_vendors( bool $only_available = false ): array {
		if ( ! $only_available ) {
			return $this->vendors;
		}

		return array_filter(
			$this->vendors,
			fn( VendorInterface $vendor ) => $vendor->is_available()
		);
	}

	/**
	 * 获取所有供应商信息
	 *
	 * @return array
	 */
	public function get_vendors_info(): array {
		$info = [];
		foreach ( $this->vendors as $vendor ) {
			$info[ $vendor->get_id() ] = array_merge(
				$vendor->get_info(),
				[ 'available' => $vendor->is_available() ]
			);
		}
		return $info;
	}

	/**
	 * 从所有供应商搜索插件
	 *
	 * @param string $keyword   关键词
	 * @param string $vendor_id 指定供应商（可选）
	 * @return array
	 */
	public function search_plugins( string $keyword, string $vendor_id = '' ): array {
		$results = [];

		$vendors = ! empty( $vendor_id )
			? [ $this->get_vendor( $vendor_id ) ]
			: $this->get_vendors( true );

		foreach ( $vendors as $vendor ) {
			if ( $vendor === null ) {
				continue;
			}

			try {
				$vendor_results = $vendor->search_plugins( $keyword );
				foreach ( $vendor_results as $plugin ) {
					$plugin['vendor_id']   = $vendor->get_id();
					$plugin['vendor_name'] = $vendor->get_info()['name'] ?? '';
					$results[]             = $plugin;
				}
			} catch ( \Exception $e ) {
				Logger::error( 'Vendor search failed', [
					'vendor' => $vendor->get_id(),
					'error'  => $e->getMessage(),
				] );
			}
		}

		return $results;
	}

	/**
	 * 从所有供应商获取插件
	 *
	 * @param string $slug 插件 slug
	 * @return array|null 包含插件信息和供应商信息
	 */
	public function get_plugin( string $slug ): ?array {
		foreach ( $this->get_vendors( true ) as $vendor ) {
			$plugin = $vendor->get_plugin( $slug );
			if ( $plugin !== null ) {
				$plugin['vendor_id']   = $vendor->get_id();
				$plugin['vendor_name'] = $vendor->get_info()['name'] ?? '';
				return $plugin;
			}
		}
		return null;
	}

	/**
	 * 检查插件更新（从所有供应商）
	 *
	 * @param string $slug            插件 slug
	 * @param string $current_version 当前版本
	 * @return array|null
	 */
	public function check_update( string $slug, string $current_version ): ?array {
		foreach ( $this->get_vendors( true ) as $vendor ) {
			$update = $vendor->check_update( $slug, $current_version );
			if ( $update !== null ) {
				$update['vendor_id']   = $vendor->get_id();
				$update['vendor_name'] = $vendor->get_info()['name'] ?? '';
				return $update;
			}
		}
		return null;
	}

	/**
	 * 获取下载链接
	 *
	 * @param string $slug      插件 slug
	 * @param string $vendor_id 供应商 ID（可选，自动查找）
	 * @param string $version   版本号（可选）
	 * @return string|null
	 */
	public function get_download_url( string $slug, string $vendor_id = '', string $version = '' ): ?string {
		if ( ! empty( $vendor_id ) ) {
			$vendor = $this->get_vendor( $vendor_id );
			if ( $vendor !== null ) {
				return $vendor->get_download_url( $slug, $version );
			}
		}

		// 自动查找
		foreach ( $this->get_vendors( true ) as $vendor ) {
			$plugin = $vendor->get_plugin( $slug );
			if ( $plugin !== null ) {
				return $vendor->get_download_url( $slug, $version );
			}
		}

		return null;
	}

	/**
	 * 添加供应商配置
	 *
	 * @param string $vendor_id 供应商 ID
	 * @param array  $config    配置
	 * @return bool
	 */
	public function add_vendor_config( string $vendor_id, array $config ): bool {
		$vendors              = $this->settings->get( 'vendors', [] );
		$vendors[ $vendor_id ] = $config;

		$result = $this->settings->set( 'vendors', $vendors );

		if ( $result ) {
			// 重新加载
			$vendor = $this->create_vendor( $vendor_id, $config );
			if ( $vendor !== null ) {
				$this->vendors[ $vendor_id ] = $vendor;
			}
		}

		return $result;
	}

	/**
	 * 移除供应商配置
	 *
	 * @param string $vendor_id 供应商 ID
	 * @return bool
	 */
	public function remove_vendor_config( string $vendor_id ): bool {
		$vendors = $this->settings->get( 'vendors', [] );

		if ( ! isset( $vendors[ $vendor_id ] ) ) {
			return false;
		}

		unset( $vendors[ $vendor_id ] );
		unset( $this->vendors[ $vendor_id ] );

		return $this->settings->set( 'vendors', $vendors );
	}

	/**
	 * 测试供应商连接
	 *
	 * @param string $vendor_id 供应商 ID
	 * @return array
	 */
	public function test_vendor( string $vendor_id ): array {
		$vendor = $this->get_vendor( $vendor_id );

		if ( $vendor === null ) {
			return [
				'success' => false,
				'message' => __( '供应商不存在', 'wpbridge' ),
			];
		}

		$available = $vendor->is_available();

		if ( ! $available ) {
			return [
				'success' => false,
				'message' => __( '供应商连接失败，请检查配置', 'wpbridge' ),
			];
		}

		// 尝试获取插件列表
		$plugins = $vendor->get_plugins( 1, 10 );

		return [
			'success'      => true,
			'message'      => __( '连接成功', 'wpbridge' ),
			'plugin_count' => $plugins['total'] ?? count( $plugins['plugins'] ),
		];
	}

	/**
	 * 获取统计信息
	 *
	 * @return array
	 */
	public function get_stats(): array {
		$total_vendors   = count( $this->vendors );
		$active_vendors  = count( $this->get_vendors( true ) );
		$total_plugins   = 0;

		foreach ( $this->get_vendors( true ) as $vendor ) {
			$plugins       = $vendor->get_plugins( 1, 1 );
			$total_plugins += $plugins['total'] ?? 0;
		}

		return [
			'total_vendors'  => $total_vendors,
			'active_vendors' => $active_vendors,
			'total_plugins'  => $total_plugins,
		];
	}
}
