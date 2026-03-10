<?php
/**
 * 供应商处理器
 *
 * 将 VendorManager 适配为标准更新源处理器
 *
 * @package WPBridge
 * @since 1.3.0
 */

namespace WPBridge\UpdateSource\Handlers;

use WPBridge\Commercial\Vendors\VendorManager;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 供应商处理器
 *
 * 委托 VendorManager 完成更新检查和信息获取
 */
class VendorHandler extends AbstractHandler {

	/**
	 * 供应商 ID
	 *
	 * @var string
	 */
	private string $vendor_id = '';

	/**
	 * 获取能力列表
	 *
	 * @return array
	 */
	public function get_capabilities(): array {
		return [
			'auth'     => 'vendor',
			'version'  => 'vendor_api',
			'download' => 'vendor_api',
		];
	}

	/**
	 * 设置供应商 ID
	 *
	 * @param string $vendor_id 供应商 ID
	 */
	public function set_vendor_id( string $vendor_id ): void {
		$this->vendor_id = $vendor_id;
	}

	/**
	 * 获取供应商 ID
	 *
	 * 优先使用显式设置的 vendor_id，否则从 metadata 中读取
	 *
	 * @return string
	 */
	private function get_vendor_id(): string {
		if ( ! empty( $this->vendor_id ) ) {
			return $this->vendor_id;
		}

		return $this->source->metadata['vendor_id'] ?? '';
	}

	/**
	 * 检查更新
	 *
	 * @param string $slug    插件/主题 slug
	 * @param string $version 当前版本
	 * @return UpdateInfo|null
	 */
	public function check_update( string $slug, string $version ): ?UpdateInfo {
		$vendor_id = $this->get_vendor_id();
		if ( empty( $vendor_id ) ) {
			Logger::warning( '供应商处理器缺少 vendor_id', [ 'source' => $this->source->id ] );
			return null;
		}

		$vm = VendorManager::get_instance();
		$vendor = $vm->get_vendor( $vendor_id );
		if ( ! $vendor ) {
			Logger::warning( '供应商不存在', [ 'vendor_id' => $vendor_id ] );
			return null;
		}

		$result = $vendor->check_update( $slug, $version );
		if ( ! $result ) {
			return null;
		}

		// 转换为 UpdateInfo（WP 标准格式）
		$info = UpdateInfo::from_array( [
			'slug'         => $slug,
			'version'      => $result['version'] ?? '',
			'download_url' => $result['download_url'] ?? '',
			'tested'       => $result['tested'] ?? '',
			'requires'     => $result['requires'] ?? '',
			'requires_php' => $result['requires_php'] ?? '',
			'changelog'    => $result['changelog'] ?? '',
		] );

		Logger::info( '供应商发现更新', [
			'slug'      => $slug,
			'vendor_id' => $vendor_id,
			'current'   => $version,
			'new'       => $result['version'] ?? '',
		] );

		return $info;
	}

	/**
	 * 获取项目信息
	 *
	 * @param string $slug 插件/主题 slug
	 * @return array|null
	 */
	public function get_info( string $slug ): ?array {
		$vendor_id = $this->get_vendor_id();
		if ( empty( $vendor_id ) ) {
			return null;
		}

		$vm = VendorManager::get_instance();
		$vendor = $vm->get_vendor( $vendor_id );
		if ( ! $vendor ) {
			return null;
		}

		return $vendor->get_plugin( $slug );
	}

	/**
	 * 测试连通性
	 *
	 * @return HealthStatus
	 */
	public function test_connection(): HealthStatus {
		$vendor_id = $this->get_vendor_id();
		if ( empty( $vendor_id ) ) {
			return HealthStatus::failed( '未配置供应商 ID' );
		}

		$start = microtime( true );
		$vm = VendorManager::get_instance();
		$vendor = $vm->get_vendor( $vendor_id );

		if ( ! $vendor ) {
			return HealthStatus::failed( '供应商不存在: ' . $vendor_id );
		}

		$available = $vendor->is_available();
		$elapsed   = (int) ( ( microtime( true ) - $start ) * 1000 );

		if ( $available ) {
			return HealthStatus::healthy( $elapsed );
		}

		return HealthStatus::failed( '供应商不可用' );
	}
}
