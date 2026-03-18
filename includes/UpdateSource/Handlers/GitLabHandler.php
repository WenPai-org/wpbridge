<?php
/**
 * GitLab 处理器
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
 * GitLab 处理器类
 */
class GitLabHandler extends AbstractHandler {

	/**
	 * GitLab API 基础 URL
	 *
	 * @var string
	 */
	const API_BASE = 'https://gitlab.com/api/v4';

	/**
	 * 获取能力列表
	 *
	 * @return array
	 */
	public function get_capabilities(): array {
		return [
			'auth'     => 'token',
			'version'  => 'release',
			'download' => 'release',
		];
	}

	/**
	 * 获取请求头
	 *
	 * @return array
	 */
	public function get_headers(): array {
		$headers = [];

		$token = $this->get_auth_token();
		if ( ! empty( $token ) ) {
			// GitLab 使用 PRIVATE-TOKEN 头
			$headers['PRIVATE-TOKEN'] = $token;
		}

		return $headers;
	}

	/**
	 * 获取检查 URL
	 *
	 * @return string
	 */
	public function get_check_url(): string {
		$project_id = $this->get_project_id();
		if ( empty( $project_id ) ) {
			return $this->source->api_url;
		}
		return self::API_BASE . '/projects/' . $project_id . '/releases';
	}

	/**
	 * 检查更新
	 *
	 * @param string $slug    插件/主题 slug
	 * @param string $version 当前版本
	 * @return UpdateInfo|null
	 */
	public function check_update( string $slug, string $version ): ?UpdateInfo {
		$project_id = $this->get_project_id();

		if ( empty( $project_id ) ) {
			Logger::warning( 'GitLab: 无效的项目 URL', [ 'url' => $this->source->api_url ] );
			return null;
		}

		$url  = self::API_BASE . '/projects/' . $project_id . '/releases';
		$data = $this->request( $url );

		if ( null === $data || empty( $data ) ) {
			return null;
		}

		// 获取最新 Release（第一个）
		$latest = $data[0] ?? null;

		if ( null === $latest ) {
			return null;
		}

		// 解析版本号
		$remote_version = $latest['tag_name'] ?? '';
		$remote_version = ltrim( $remote_version, 'v' );

		if ( empty( $remote_version ) ) {
			Logger::warning( 'GitLab: 响应缺少版本信息', [ 'project' => $project_id ] );
			return null;
		}

		// 检查是否有更新
		if ( ! $this->is_newer_version( $version, $remote_version ) ) {
			Logger::debug(
				'GitLab: 无可用更新',
				[
					'project' => $project_id,
					'current' => $version,
					'remote'  => $remote_version,
				]
			);
			return null;
		}

		// 查找下载 URL
		$download_url = $this->find_download_url( $latest, $slug, $project_id );

		if ( empty( $download_url ) ) {
			Logger::warning( 'GitLab: 未找到下载 URL', [ 'project' => $project_id ] );
			return null;
		}

		// 构建更新信息
		$info               = new UpdateInfo();
		$info->slug         = $slug;
		$info->version      = $remote_version;
		$info->download_url = $download_url;
		$info->details_url  = $latest['_links']['self'] ?? '';
		$info->last_updated = $latest['released_at'] ?? '';
		$info->changelog    = $latest['description'] ?? '';

		Logger::info(
			'GitLab: 发现更新',
			[
				'project' => $project_id,
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
		$project_id = $this->get_project_id();

		if ( empty( $project_id ) ) {
			return null;
		}

		// 获取项目信息
		$project_url  = self::API_BASE . '/projects/' . $project_id;
		$project_data = $this->request( $project_url );

		// 获取 Releases
		$releases_url  = self::API_BASE . '/projects/' . $project_id . '/releases';
		$releases_data = $this->request( $releases_url );

		if ( null === $project_data ) {
			return null;
		}

		$latest  = $releases_data[0] ?? [];
		$version = ltrim( $latest['tag_name'] ?? '', 'v' );

		return [
			'name'         => $project_data['name'] ?? $slug,
			'slug'         => $slug,
			'version'      => $version,
			'download_url' => $this->find_download_url( $latest, $slug, $project_id ),
			'details_url'  => $project_data['web_url'] ?? '',
			'last_updated' => $latest['released_at'] ?? '',
			'sections'     => [
				'description' => $project_data['description'] ?? '',
				'changelog'   => $latest['description'] ?? '',
			],
		];
	}

	/**
	 * 获取项目 ID（URL 编码的路径）
	 *
	 * @return string|null
	 */
	private function get_project_id(): ?string {
		$url = trim( $this->source->api_url );

		// 移除协议
		$url = preg_replace( '#^https?://#', '', $url );

		// 移除 gitlab.com
		$url = preg_replace( '#^gitlab\.com/#', '', $url );

		// 移除 .git 后缀
		$url = preg_replace( '#\.git$#', '', $url );

		// URL 编码路径
		if ( preg_match( '#^[\w.-]+/[\w.-]+(?:/[\w.-]+)*$#', $url ) ) {
			return urlencode( $url );
		}

		return null;
	}

	/**
	 * 查找下载 URL
	 *
	 * @param array  $release    Release 数据
	 * @param string $slug       插件 slug
	 * @param string $project_id 项目 ID
	 * @return string|null
	 */
	private function find_download_url( array $release, string $slug, string $project_id ): ?string {
		// 查找 assets 中的链接
		if ( ! empty( $release['assets']['links'] ) ) {
			foreach ( $release['assets']['links'] as $link ) {
				$name = $link['name'] ?? '';

				if ( preg_match( '/\.zip$/i', $name ) ) {
					if ( stripos( $name, $slug ) !== false ) {
						return $link['url'] ?? null;
					}
				}
			}

			// 返回第一个 zip
			foreach ( $release['assets']['links'] as $link ) {
				if ( preg_match( '/\.zip$/i', $link['name'] ?? '' ) ) {
					return $link['url'] ?? null;
				}
			}
		}

		// 使用归档 URL 作为后备
		$tag = $release['tag_name'] ?? '';
		if ( ! empty( $tag ) ) {
			return self::API_BASE . '/projects/' . $project_id . '/repository/archive.zip?sha=' . $tag;
		}

		return null;
	}
}
