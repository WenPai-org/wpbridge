<?php
/**
 * ZIP 处理器
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
 * ZIP 处理器（直接下载地址）
 *
 * 需要 metadata 中提供 version/new_version，或从 URL 文件名推断版本
 */
class ZipHandler extends AbstractHandler {

	/**
	 * 获取能力列表
	 *
	 * @return array
	 */
	public function get_capabilities(): array {
		return [
			'auth'     => 'token',
			'version'  => 'zip',
			'download' => 'direct',
		];
	}

	/**
	 * 检查更新
	 *
	 * @param string $slug    插件/主题 slug
	 * @param string $version 当前版本
	 * @return UpdateInfo|null
	 */
	public function check_update( string $slug, string $version ): ?UpdateInfo {
		$remote_version = $this->resolve_version();

		if ( empty( $remote_version ) ) {
			Logger::debug( 'ZIP: 无法解析版本号', [ 'url' => $this->source->api_url ] );
			return null;
		}

		if ( ! $this->is_newer_version( $version, $remote_version ) ) {
			return null;
		}

		$info               = new UpdateInfo();
		$info->slug         = $slug;
		$info->version      = $remote_version;
		$info->download_url = $this->source->api_url;
		$info->details_url  = $this->source->api_url;

		return $info;
	}

	/**
	 * 获取项目信息
	 *
	 * @param string $slug 插件/主题 slug
	 * @return array|null
	 */
	public function get_info( string $slug ): ?array {
		$remote_version = $this->resolve_version();

		if ( empty( $remote_version ) ) {
			return null;
		}

		return [
			'name'         => $this->source->name ?: $slug,
			'slug'         => $slug,
			'version'      => $remote_version,
			'download_url' => $this->source->api_url,
			'package'      => $this->source->api_url,
			'details_url'  => $this->source->api_url,
		];
	}

	/**
	 * 解析版本号
	 *
	 * @return string
	 */
	private function resolve_version(): string {
		$metadata = $this->source->metadata ?? [];

		if ( ! empty( $metadata['version'] ) ) {
			return (string) $metadata['version'];
		}

		if ( ! empty( $metadata['new_version'] ) ) {
			return (string) $metadata['new_version'];
		}

		$path = wp_parse_url( $this->source->api_url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return '';
		}

		$filename = basename( $path );
		if ( preg_match( '/(\d+\.\d+\.\d+(?:[-+][\w\.]+)?)/', $filename, $matches ) ) {
			return $matches[1];
		}

		return '';
	}
}
