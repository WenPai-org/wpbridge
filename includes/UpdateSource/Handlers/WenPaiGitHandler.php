<?php
/**
 * 菲码源库处理器
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
 * 菲码源库处理器（Gitea API）
 */
class WenPaiGitHandler extends AbstractHandler {

	/**
	 * API 基础 URL
	 *
	 * @var string
	 */
	const API_BASE = 'https://git.wenpai.org/api/v1';

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
		$headers               = parent::get_headers();
		$headers['Accept']     = 'application/json';
		$headers['User-Agent'] = 'WPBridge/' . WPBRIDGE_VERSION;
		return $headers;
	}

	/**
	 * 获取检查 URL
	 *
	 * @return string
	 */
	public function get_check_url(): string {
		$repo = $this->parse_repo_url( $this->source->api_url );
		if ( empty( $repo ) ) {
			return $this->source->api_url;
		}
		return $this->get_api_base() . '/repos/' . $repo . '/releases';
	}

	/**
	 * 检查更新
	 *
	 * @param string $slug    插件/主题 slug
	 * @param string $version 当前版本
	 * @return UpdateInfo|null
	 */
	public function check_update( string $slug, string $version ): ?UpdateInfo {
		$repo = $this->parse_repo_url( $this->source->api_url );

		if ( empty( $repo ) ) {
			Logger::warning( '菲码源库: 无效的仓库 URL', [ 'url' => $this->source->api_url ] );
			return null;
		}

		$url  = $this->get_api_base() . '/repos/' . $repo . '/releases';
		$data = $this->request( $url );

		if ( null === $data || empty( $data ) ) {
			return null;
		}

		$latest = $data[0] ?? null;
		if ( null === $latest ) {
			return null;
		}

		$remote_version = ltrim( $latest['tag_name'] ?? '', 'v' );
		if ( empty( $remote_version ) ) {
			Logger::warning( '菲码源库: 响应缺少版本信息', [ 'repo' => $repo ] );
			return null;
		}

		if ( ! $this->is_newer_version( $version, $remote_version ) ) {
			return null;
		}

		$download_url = $this->find_download_url( $latest, $slug, $repo );
		if ( empty( $download_url ) ) {
			Logger::warning( '菲码源库: 未找到下载 URL', [ 'repo' => $repo ] );
			return null;
		}

		$info               = new UpdateInfo();
		$info->slug         = $slug;
		$info->version      = $remote_version;
		$info->download_url = $download_url;
		$info->details_url  = $latest['html_url'] ?? '';
		$info->last_updated = $latest['published_at'] ?? $latest['created_at'] ?? '';
		$info->changelog    = $latest['body'] ?? '';

		return $info;
	}

	/**
	 * 获取项目信息
	 *
	 * @param string $slug 插件/主题 slug
	 * @return array|null
	 */
	public function get_info( string $slug ): ?array {
		$repo = $this->parse_repo_url( $this->source->api_url );
		if ( empty( $repo ) ) {
			return null;
		}

		$repo_url     = $this->get_api_base() . '/repos/' . $repo;
		$repo_data    = $this->request( $repo_url );
		$releases_url = $this->get_api_base() . '/repos/' . $repo . '/releases';
		$release_data = $this->request( $releases_url );

		if ( null === $repo_data ) {
			return null;
		}

		$latest  = $release_data[0] ?? [];
		$version = ltrim( $latest['tag_name'] ?? '', 'v' );

		return [
			'name'         => $repo_data['name'] ?? $slug,
			'slug'         => $slug,
			'version'      => $version,
			'download_url' => $this->find_download_url( $latest, $slug, $repo ),
			'details_url'  => $repo_data['html_url'] ?? '',
			'last_updated' => $latest['published_at'] ?? $latest['created_at'] ?? '',
			'sections'     => [
				'description' => $repo_data['description'] ?? '',
				'changelog'   => $latest['body'] ?? '',
			],
		];
	}

	/**
	 * 解析仓库 URL
	 *
	 * @param string $url URL
	 * @return string|null owner/repo 格式
	 */
	private function parse_repo_url( string $url ): ?string {
		$url = trim( $url );

		$parts = wp_parse_url( $url );
		if ( ! empty( $parts['host'] ) ) {
			$path = trim( $parts['path'] ?? '', '/' );
			$path = preg_replace( '#\.git$#', '', $path );

			if ( preg_match( '#^api/v1/repos/([\w.-]+/[\w.-]+)#', $path, $matches ) ) {
				return $matches[1];
			}

			if ( preg_match( '#^repos/([\w.-]+/[\w.-]+)#', $path, $matches ) ) {
				return $matches[1];
			}

			if (
				( preg_match( '#^api/v1(?:/|$)#', $path ) && ! preg_match( '#^api/v1/repos/[\w.-]+/[\w.-]+#', $path ) )
				|| ( preg_match( '#^repos(?:/|$)#', $path ) && ! preg_match( '#^repos/[\w.-]+/[\w.-]+#', $path ) )
			) {
				return null;
			}

			$segments = array_values( array_filter( explode( '/', $path ) ) );
			if ( count( $segments ) >= 2 ) {
				$repo = $segments[0] . '/' . $segments[1];
				if ( preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ) {
					return $repo;
				}
			}

			return null;
		}

		$path = preg_replace( '#^https?://#', '', $url );
		$path = preg_replace( '#^[^/]+/#', '', $path );
		$path = trim( $path, '/' );
		$path = preg_replace( '#\.git$#', '', $path );

		if ( preg_match( '#^api/v1/repos/([\w.-]+/[\w.-]+)#', $path, $matches ) ) {
			return $matches[1];
		}

		if ( preg_match( '#^repos/([\w.-]+/[\w.-]+)#', $path, $matches ) ) {
			return $matches[1];
		}

		if (
			( preg_match( '#^api/v1(?:/|$)#', $path ) && ! preg_match( '#^api/v1/repos/[\w.-]+/[\w.-]+#', $path ) )
			|| ( preg_match( '#^repos(?:/|$)#', $path ) && ! preg_match( '#^repos/[\w.-]+/[\w.-]+#', $path ) )
		) {
			return null;
		}

		$segments = array_values( array_filter( explode( '/', $path ) ) );
		if ( count( $segments ) >= 2 ) {
			$repo = $segments[0] . '/' . $segments[1];
			if ( preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ) {
				return $repo;
			}
		}

		return null;
	}

	/**
	 * 查找下载 URL
	 *
	 * @param array  $release Release 数据
	 * @param string $slug    插件 slug
	 * @param string $repo    仓库路径
	 * @return string|null
	 */
	private function find_download_url( array $release, string $slug, string $repo ): ?string {
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				$name = $asset['name'] ?? '';

				if ( preg_match( '/\.zip$/i', $name ) ) {
					if ( stripos( $name, $slug ) !== false ) {
						return $asset['browser_download_url'] ?? null;
					}
				}
			}

			foreach ( $release['assets'] as $asset ) {
				if ( preg_match( '/\.zip$/i', $asset['name'] ?? '' ) ) {
					return $asset['browser_download_url'] ?? null;
				}
			}
		}

		if ( ! empty( $release['zipball_url'] ) ) {
			return $release['zipball_url'];
		}

		$tag = $release['tag_name'] ?? '';
		if ( ! empty( $tag ) ) {
			return $this->get_api_base() . '/repos/' . $repo . '/archive/' . $tag . '.zip';
		}

		return null;
	}

	/**
	 * 获取 API 基础地址
	 *
	 * @return string
	 */
	private function get_api_base(): string {
		$parts = wp_parse_url( $this->source->api_url );
		if ( ! empty( $parts['host'] ) ) {
			$scheme    = $parts['scheme'] ?? 'https';
			$port      = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
			$path      = $parts['path'] ?? '';
			$base_path = '';

			if ( preg_match( '#^(.*?)/api/v1#', $path, $matches ) ) {
				$base_path = rtrim( $matches[1], '/' );
			} else {
				$repo = $this->parse_repo_url( $this->source->api_url );
				if ( ! empty( $repo ) ) {
					$repo_path = '/' . trim( $repo, '/' );
					$pos       = strpos( $path, $repo_path );
					if ( false !== $pos ) {
						$base_path = rtrim( substr( $path, 0, $pos ), '/' );
					}
				}
			}

			return $scheme . '://' . $parts['host'] . $port . $base_path . '/api/v1';
		}

		return self::API_BASE;
	}
}
