<?php
/**
 * AspireCloud 处理器
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource\Handlers;

use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AspireCloud 处理器
 */
class AspireCloudHandler extends AbstractHandler {

	/**
	 * 获取能力列表
	 *
	 * @return array
	 */
	public function get_capabilities(): array {
		return [
			'auth'       => 'token',
			'version'    => 'json',
			'download'   => 'direct',
			'federation' => true,
			'cdn'        => true,
		];
	}

	/**
	 * 获取检查 URL
	 *
	 * @return string
	 */
	public function get_check_url(): string {
		return rtrim( $this->source->api_url, '/' ) . '/plugins/update-check/1.1';
	}

	/**
	 * 检查更新
	 *
	 * @param string $slug    插件/主题 slug
	 * @param string $version 当前版本
	 * @return UpdateInfo|null
	 */
	public function check_update( string $slug, string $version ): ?UpdateInfo {
		$url  = $this->build_plugin_url( $slug );
		$data = $this->request( $url );

		if ( null === $data ) {
			return null;
		}

		// AspireCloud API 响应格式
		$remote_version = $data['version'] ?? $data['new_version'] ?? '';

		if ( empty( $remote_version ) ) {
			Logger::warning(
				'AspireCloud 响应缺少版本信息',
				[
					'url'  => $url,
					'slug' => $slug,
				]
			);
			return null;
		}

		// 检查是否有更新
		if ( ! $this->is_newer_version( $version, $remote_version ) ) {
			Logger::debug(
				'AspireCloud: 无可用更新',
				[
					'slug'    => $slug,
					'current' => $version,
					'remote'  => $remote_version,
				]
			);
			return null;
		}

		// 构建更新信息
		$info       = UpdateInfo::from_array( $data );
		$info->slug = $slug;

		Logger::info(
			'AspireCloud: 发现更新',
			[
				'slug'    => $slug,
				'current' => $version,
				'new'     => $remote_version,
			]
		);

		return $info;
	}

	/**
	 * 获取项目信息
	 *
	 * @param string $slug 插件/主题 slug
	 * @return array|null
	 */
	public function get_info( string $slug ): ?array {
		$url = $this->build_plugin_url( $slug );
		return $this->request( $url );
	}

	/**
	 * 构建插件 URL
	 *
	 * @param string $slug 插件 slug
	 * @return string
	 */
	protected function build_plugin_url( string $slug ): string {
		return rtrim( $this->source->api_url, '/' ) . '/plugins/info/1.2?slug=' . urlencode( $slug );
	}
}
