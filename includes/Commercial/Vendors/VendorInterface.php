<?php
/**
 * 供应商接口
 *
 * 定义第三方 GPL 插件供应商的标准接口
 *
 * @package WPBridge
 * @since 0.9.7
 */

declare(strict_types=1);

namespace WPBridge\Commercial\Vendors;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VendorInterface 接口
 */
interface VendorInterface {

	/**
	 * 获取供应商唯一标识
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * 获取供应商信息
	 *
	 * @return array {
	 *     @type string $id          供应商 ID
	 *     @type string $name        供应商名称
	 *     @type string $url         供应商网站
	 *     @type string $api_type    API 类型 (wc_am, edd, custom)
	 *     @type bool   $requires_key 是否需要 API Key
	 * }
	 */
	public function get_info(): array;

	/**
	 * 检查供应商是否可用
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * 获取可用插件列表
	 *
	 * @param int $page  页码
	 * @param int $limit 每页数量
	 * @return array {
	 *     @type array[] $plugins 插件列表
	 *     @type int     $total   总数
	 *     @type int     $pages   总页数
	 * }
	 */
	public function get_plugins( int $page = 1, int $limit = 100 ): array;

	/**
	 * 搜索插件
	 *
	 * @param string $keyword 关键词
	 * @return array
	 */
	public function search_plugins( string $keyword ): array;

	/**
	 * 获取插件详情
	 *
	 * @param string $slug 插件 slug
	 * @return array|null
	 */
	public function get_plugin( string $slug ): ?array;

	/**
	 * 检查插件更新
	 *
	 * @param string $slug            插件 slug
	 * @param string $current_version 当前版本
	 * @return array|null {
	 *     @type string $version      最新版本
	 *     @type string $download_url 下载链接
	 *     @type string $changelog    更新日志
	 *     @type string $tested       测试的 WP 版本
	 *     @type string $requires     最低 WP 版本
	 *     @type string $requires_php 最低 PHP 版本
	 * }
	 */
	public function check_update( string $slug, string $current_version ): ?array;

	/**
	 * 获取下载链接
	 *
	 * @param string $slug    插件 slug
	 * @param string $version 版本号（可选，默认最新）
	 * @return string|null
	 */
	public function get_download_url( string $slug, string $version = '' ): ?string;

	/**
	 * 验证供应商授权
	 *
	 * @return bool
	 */
	public function verify_credentials(): bool;

	/**
	 * 清除该供应商的所有缓存
	 *
	 * @return int 删除条数
	 */
	public function clear_all_cache(): int;
}
