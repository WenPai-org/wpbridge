<?php
/**
 * GitHub 处理器
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
 * GitHub 处理器类
 */
class GitHubHandler extends AbstractHandler {

    /**
     * GitHub API 基础 URL
     *
     * @var string
     */
    const API_BASE = 'https://api.github.com';

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
        $headers = parent::get_headers();
        $headers['Accept'] = 'application/vnd.github.v3+json';
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
        return self::API_BASE . '/repos/' . $repo . '/releases/latest';
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
            Logger::warning( 'GitHub: 无效的仓库 URL', [ 'url' => $this->source->api_url ] );
            return null;
        }

        $url = self::API_BASE . '/repos/' . $repo . '/releases/latest';
        $data = $this->request( $url );

        if ( null === $data ) {
            return null;
        }

        // 解析版本号（去除 v 前缀）
        $remote_version = $data['tag_name'] ?? '';
        $remote_version = ltrim( $remote_version, 'v' );

        if ( empty( $remote_version ) ) {
            Logger::warning( 'GitHub: 响应缺少版本信息', [ 'repo' => $repo ] );
            return null;
        }

        // 检查是否有更新
        if ( ! $this->is_newer_version( $version, $remote_version ) ) {
            Logger::debug( 'GitHub: 无可用更新', [
                'repo'    => $repo,
                'current' => $version,
                'remote'  => $remote_version,
            ] );
            return null;
        }

        // 查找下载 URL
        $download_url = $this->find_download_url( $data, $slug );

        if ( empty( $download_url ) ) {
            Logger::warning( 'GitHub: 未找到下载 URL', [ 'repo' => $repo ] );
            return null;
        }

        // 构建更新信息
        $info = new UpdateInfo();
        $info->slug         = $slug;
        $info->version      = $remote_version;
        $info->download_url = $download_url;
        $info->details_url  = $data['html_url'] ?? '';
        $info->last_updated = $data['published_at'] ?? '';
        $info->changelog    = $data['body'] ?? '';

        Logger::info( 'GitHub: 发现更新', [
            'repo'    => $repo,
            'current' => $version,
            'new'     => $remote_version,
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
        $repo = $this->parse_repo_url( $this->source->api_url );

        if ( empty( $repo ) ) {
            return null;
        }

        // 获取仓库信息
        $repo_url = self::API_BASE . '/repos/' . $repo;
        $repo_data = $this->request( $repo_url );

        // 获取最新 Release
        $release_url = self::API_BASE . '/repos/' . $repo . '/releases/latest';
        $release_data = $this->request( $release_url );

        if ( null === $repo_data || null === $release_data ) {
            return null;
        }

        $version = ltrim( $release_data['tag_name'] ?? '', 'v' );

        return [
            'name'         => $repo_data['name'] ?? $slug,
            'slug'         => $slug,
            'version'      => $version,
            'download_url' => $this->find_download_url( $release_data, $slug ),
            'details_url'  => $release_data['html_url'] ?? '',
            'last_updated' => $release_data['published_at'] ?? '',
            'sections'     => [
                'description' => $repo_data['description'] ?? '',
                'changelog'   => $release_data['body'] ?? '',
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
        // 支持多种格式
        // https://github.com/owner/repo
        // github.com/owner/repo
        // owner/repo

        $url = trim( $url );

        // 移除协议
        $url = preg_replace( '#^https?://#', '', $url );

        // 移除 github.com
        $url = preg_replace( '#^github\.com/#', '', $url );

        // 移除 .git 后缀
        $url = preg_replace( '#\.git$#', '', $url );

        // 验证格式
        if ( preg_match( '#^[\w.-]+/[\w.-]+$#', $url ) ) {
            return $url;
        }

        return null;
    }

    /**
     * 查找下载 URL
     *
     * @param array  $release Release 数据
     * @param string $slug    插件 slug
     * @return string|null
     */
    private function find_download_url( array $release, string $slug ): ?string {
        // 优先查找 assets 中的 zip 文件
        if ( ! empty( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                $name = $asset['name'] ?? '';

                // 匹配 slug.zip 或 slug-version.zip
                if ( preg_match( '/\.zip$/i', $name ) ) {
                    if ( stripos( $name, $slug ) !== false ) {
                        return $asset['browser_download_url'] ?? null;
                    }
                }
            }

            // 如果没有匹配 slug 的，返回第一个 zip
            foreach ( $release['assets'] as $asset ) {
                if ( preg_match( '/\.zip$/i', $asset['name'] ?? '' ) ) {
                    return $asset['browser_download_url'] ?? null;
                }
            }
        }

        // 使用 zipball_url 作为后备
        return $release['zipball_url'] ?? null;
    }
}
