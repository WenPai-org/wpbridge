<?php
/**
 * 更新日志管理器
 *
 * 聚合显示插件/主题的更新日志
 *
 * @package WPBridge
 * @since 0.9.0
 */

namespace WPBridge\Core;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 更新日志管理类
 */
class ChangelogManager {

    /**
     * 单例实例
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * 缓存前缀
     */
    const CACHE_PREFIX = 'wpbridge_changelog_';

    /**
     * 缓存时间（秒）
     */
    const CACHE_TTL = 3600;

    /**
     * 获取单例实例
     *
     * @return self
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取插件更新日志
     *
     * @param string $slug 插件 slug
     * @param string $source_type 源类型（wporg, custom, git）
     * @param string $source_url 自定义源 URL（可选）
     * @return array
     */
    public function get_plugin_changelog( string $slug, string $source_type = 'wporg', string $source_url = '' ): array {
        $cache_key = self::CACHE_PREFIX . 'plugin_' . md5( $slug . $source_type . $source_url );
        $cached = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $changelog = [];

        switch ( $source_type ) {
            case 'wporg':
                $changelog = $this->fetch_wporg_plugin_changelog( $slug );
                break;
            case 'git':
            case 'github':
            case 'gitea':
                $changelog = $this->fetch_git_changelog( $source_url );
                break;
            case 'json':
            case 'custom':
                $changelog = $this->fetch_custom_changelog( $source_url, 'plugin', $slug );
                break;
            default:
                $changelog = $this->fetch_wporg_plugin_changelog( $slug );
        }

        if ( ! empty( $changelog ) ) {
            set_transient( $cache_key, $changelog, self::CACHE_TTL );
        }

        return $changelog;
    }

    /**
     * 获取主题更新日志
     *
     * @param string $slug 主题 slug
     * @param string $source_type 源类型
     * @param string $source_url 自定义源 URL
     * @return array
     */
    public function get_theme_changelog( string $slug, string $source_type = 'wporg', string $source_url = '' ): array {
        $cache_key = self::CACHE_PREFIX . 'theme_' . md5( $slug . $source_type . $source_url );
        $cached = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $changelog = [];

        switch ( $source_type ) {
            case 'wporg':
                $changelog = $this->fetch_wporg_theme_changelog( $slug );
                break;
            case 'git':
            case 'github':
            case 'gitea':
                $changelog = $this->fetch_git_changelog( $source_url );
                break;
            case 'json':
            case 'custom':
                $changelog = $this->fetch_custom_changelog( $source_url, 'theme', $slug );
                break;
            default:
                $changelog = $this->fetch_wporg_theme_changelog( $slug );
        }

        if ( ! empty( $changelog ) ) {
            set_transient( $cache_key, $changelog, self::CACHE_TTL );
        }

        return $changelog;
    }

    /**
     * 从 WordPress.org 获取插件更新日志
     *
     * @param string $slug 插件 slug
     * @return array
     */
    private function fetch_wporg_plugin_changelog( string $slug ): array {
        $api_url = 'https://api.wordpress.org/plugins/info/1.2/';
        $response = wp_remote_post( $api_url, [
            'timeout' => 15,
            'body' => [
                'action' => 'plugin_information',
                'request' => serialize( (object) [
                    'slug' => $slug,
                    'fields' => [
                        'sections' => true,
                        'versions' => true,
                    ],
                ] ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = maybe_unserialize( $body );

        if ( ! is_object( $data ) || isset( $data->error ) ) {
            return $this->error_response( __( '无法获取插件信息', 'wpbridge' ) );
        }

        return $this->format_wporg_changelog( $data, 'plugin' );
    }

    /**
     * 从 WordPress.org 获取主题更新日志
     *
     * @param string $slug 主题 slug
     * @return array
     */
    private function fetch_wporg_theme_changelog( string $slug ): array {
        $api_url = 'https://api.wordpress.org/themes/info/1.2/';
        $response = wp_remote_post( $api_url, [
            'timeout' => 15,
            'body' => [
                'action' => 'theme_information',
                'request' => serialize( (object) [
                    'slug' => $slug,
                    'fields' => [
                        'sections' => true,
                        'versions' => true,
                    ],
                ] ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = maybe_unserialize( $body );

        if ( ! is_object( $data ) || isset( $data->error ) ) {
            return $this->error_response( __( '无法获取主题信息', 'wpbridge' ) );
        }

        return $this->format_wporg_changelog( $data, 'theme' );
    }

    /**
     * 格式化 WordPress.org 更新日志
     *
     * @param object $data API 返回数据
     * @param string $type 类型（plugin/theme）
     * @return array
     */
    private function format_wporg_changelog( object $data, string $type ): array {
        $result = [
            'success' => true,
            'source' => 'WordPress.org',
            'name' => $data->name ?? '',
            'slug' => $data->slug ?? '',
            'version' => $data->version ?? '',
            'last_updated' => $data->last_updated ?? '',
            'changelog_html' => '',
            'versions' => [],
        ];

        // 提取 changelog 部分
        if ( isset( $data->sections['changelog'] ) ) {
            $result['changelog_html'] = $data->sections['changelog'];
        }

        // 提取版本历史
        if ( isset( $data->versions ) && is_array( $data->versions ) ) {
            $versions = array_keys( $data->versions );
            rsort( $versions, SORT_NATURAL );
            $result['versions'] = array_slice( $versions, 0, 20 );
        }

        return $result;
    }

    /**
     * 从 Git 仓库获取更新日志
     *
     * @param string $url 仓库 URL
     * @return array
     */
    private function fetch_git_changelog( string $url ): array {
        $parsed = $this->parse_git_url( $url );

        if ( ! $parsed ) {
            return $this->error_response( __( '无效的 Git 仓库 URL', 'wpbridge' ) );
        }

        switch ( $parsed['platform'] ) {
            case 'github':
                return $this->fetch_github_releases( $parsed['owner'], $parsed['repo'] );
            case 'gitea':
            case 'wenpai':
                return $this->fetch_gitea_releases( $parsed['base_url'], $parsed['owner'], $parsed['repo'] );
            default:
                return $this->error_response( __( '不支持的 Git 平台', 'wpbridge' ) );
        }
    }

    /**
     * 解析 Git URL
     *
     * @param string $url URL
     * @return array|null
     */
    private function parse_git_url( string $url ): ?array {
        // GitHub
        if ( preg_match( '#github\.com/([^/]+)/([^/]+)#', $url, $matches ) ) {
            return [
                'platform' => 'github',
                'owner' => $matches[1],
                'repo' => rtrim( $matches[2], '.git' ),
                'base_url' => 'https://api.github.com',
            ];
        }

        // 菲码源库 (git.wenpai.org)
        if ( preg_match( '#git\.wenpai\.org/([^/]+)/([^/]+)#', $url, $matches ) ) {
            return [
                'platform' => 'gitea',
                'owner' => $matches[1],
                'repo' => rtrim( $matches[2], '.git' ),
                'base_url' => 'https://git.wenpai.org',
            ];
        }

        // 通用 Gitea
        if ( preg_match( '#(https?://[^/]+)/([^/]+)/([^/]+)#', $url, $matches ) ) {
            return [
                'platform' => 'gitea',
                'owner' => $matches[2],
                'repo' => rtrim( $matches[3], '.git' ),
                'base_url' => $matches[1],
            ];
        }

        return null;
    }

    /**
     * 从 GitHub 获取 Releases
     *
     * @param string $owner 仓库所有者
     * @param string $repo 仓库名
     * @return array
     */
    private function fetch_github_releases( string $owner, string $repo ): array {
        $api_url = "https://api.github.com/repos/{$owner}/{$repo}/releases";
        $response = wp_remote_get( $api_url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WPBridge/' . WPBRIDGE_VERSION,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return $this->error_response( sprintf( __( 'GitHub API 返回错误: %d', 'wpbridge' ), $status_code ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $releases = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $releases ) ) {
            return $this->error_response( __( 'JSON 解析失败', 'wpbridge' ) );
        }

        return $this->format_git_releases( $releases, 'GitHub', "{$owner}/{$repo}" );
    }

    /**
     * 从 Gitea 获取 Releases
     *
     * @param string $base_url API 基础 URL
     * @param string $owner 仓库所有者
     * @param string $repo 仓库名
     * @return array
     */
    private function fetch_gitea_releases( string $base_url, string $owner, string $repo ): array {
        $api_url = "{$base_url}/api/v1/repos/{$owner}/{$repo}/releases";
        $response = wp_remote_get( $api_url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return $this->error_response( sprintf( __( 'Gitea API 返回错误: %d', 'wpbridge' ), $status_code ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $releases = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $releases ) ) {
            return $this->error_response( __( 'JSON 解析失败', 'wpbridge' ) );
        }

        $source_name = strpos( $base_url, 'wenpai' ) !== false ? '菲码源库' : 'Gitea';
        return $this->format_git_releases( $releases, $source_name, "{$owner}/{$repo}" );
    }

    /**
     * 格式化 Git Releases
     *
     * @param array $releases Releases 数据
     * @param string $source 来源名称
     * @param string $repo 仓库标识
     * @return array
     */
    private function format_git_releases( array $releases, string $source, string $repo ): array {
        $changelog_html = '<div class="wpbridge-changelog-releases">';
        $versions = [];

        foreach ( array_slice( $releases, 0, 10 ) as $release ) {
            $tag = $release['tag_name'] ?? '';
            $name = $release['name'] ?? $tag;
            $body = $release['body'] ?? '';
            $date = $release['published_at'] ?? $release['created_at'] ?? '';
            $prerelease = $release['prerelease'] ?? false;

            $versions[] = $tag;

            $changelog_html .= '<div class="wpbridge-release-item">';
            $changelog_html .= '<h4 class="wpbridge-release-title">';
            $changelog_html .= esc_html( $name );
            if ( $prerelease ) {
                $changelog_html .= ' <span class="wpbridge-badge wpbridge-badge-warning">' . esc_html__( '预发布', 'wpbridge' ) . '</span>';
            }
            $changelog_html .= '</h4>';

            if ( $date ) {
                $formatted_date = wp_date( get_option( 'date_format' ), strtotime( $date ) );
                $changelog_html .= '<p class="wpbridge-release-date">' . esc_html( $formatted_date ) . '</p>';
            }

            if ( $body ) {
                $body_html = $this->markdown_to_html( $body );
                $changelog_html .= '<div class="wpbridge-release-body">' . wp_kses_post( $body_html ) . '</div>';
            }

            $changelog_html .= '</div>';
        }

        $changelog_html .= '</div>';

        return [
            'success' => true,
            'source' => $source,
            'name' => $repo,
            'slug' => $repo,
            'version' => $versions[0] ?? '',
            'last_updated' => $releases[0]['published_at'] ?? '',
            'changelog_html' => $changelog_html,
            'versions' => $versions,
        ];
    }

    /**
     * 从自定义源获取更新日志
     *
     * @param string $url 源 URL
     * @param string $type 类型
     * @param string $slug Slug
     * @return array
     */
    private function fetch_custom_changelog( string $url, string $type, string $slug ): array {
        if ( empty( $url ) ) {
            return $this->error_response( __( '未配置更新源 URL', 'wpbridge' ) );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->error_response( $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
            return $this->error_response( __( '无法解析更新源数据', 'wpbridge' ) );
        }

        $changelog_html = '';
        $version = '';
        $versions = [];

        if ( isset( $data['sections']['changelog'] ) ) {
            $changelog_html = $data['sections']['changelog'];
            $version = $data['version'] ?? '';
        } elseif ( isset( $data['changelog'] ) ) {
            $changelog_html = is_array( $data['changelog'] )
                ? $this->format_changelog_array( $data['changelog'] )
                : $data['changelog'];
            $version = $data['version'] ?? '';
        } elseif ( isset( $data['releases'] ) && is_array( $data['releases'] ) ) {
            return $this->format_git_releases( $data['releases'], __( '自定义源', 'wpbridge' ), $slug );
        }

        return [
            'success' => true,
            'source' => __( '自定义源', 'wpbridge' ),
            'name' => $data['name'] ?? $slug,
            'slug' => $slug,
            'version' => $version,
            'last_updated' => $data['last_updated'] ?? '',
            'changelog_html' => $changelog_html,
            'versions' => $versions,
        ];
    }

    /**
     * 格式化 changelog 数组
     *
     * @param array $changelog Changelog 数组
     * @return string
     */
    private function format_changelog_array( array $changelog ): string {
        $html = '<ul class="wpbridge-changelog-list">';
        foreach ( $changelog as $version => $changes ) {
            $html .= '<li>';
            $html .= '<strong>' . esc_html( $version ) . '</strong>';
            if ( is_array( $changes ) ) {
                $html .= '<ul>';
                foreach ( $changes as $change ) {
                    $html .= '<li>' . esc_html( $change ) . '</li>';
                }
                $html .= '</ul>';
            } else {
                $html .= '<p>' . esc_html( $changes ) . '</p>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /**
     * 简单的 Markdown 转 HTML
     *
     * @param string $markdown Markdown 文本
     * @return string
     */
    private function markdown_to_html( string $markdown ): string {
        $html = esc_html( $markdown );

        $html = preg_replace( '/^### (.+)$/m', '<h5>$1</h5>', $html );
        $html = preg_replace( '/^## (.+)$/m', '<h4>$1</h4>', $html );
        $html = preg_replace( '/^# (.+)$/m', '<h3>$1</h3>', $html );

        $html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
        $html = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $html );

        $html = preg_replace( '/^[\-\*] (.+)$/m', '<li>$1</li>', $html );
        $html = preg_replace( '/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html );

        $html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );

        $html = nl2br( $html );

        return $html;
    }

    /**
     * 返回错误响应
     *
     * @param string $message 错误消息
     * @return array
     */
    private function error_response( string $message ): array {
        return [
            'success' => false,
            'error' => $message,
            'source' => '',
            'name' => '',
            'slug' => '',
            'version' => '',
            'last_updated' => '',
            'changelog_html' => '',
            'versions' => [],
        ];
    }

    /**
     * 清除缓存
     *
     * @param string $type 类型（plugin/theme）
     * @param string $slug Slug
     */
    public function clear_cache( string $type, string $slug ): void {
        global $wpdb;

        $like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX . $type . '_' ) . '%';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );
    }

    /**
     * 清除所有缓存
     */
    public function clear_all_cache(): void {
        global $wpdb;

        $like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );
    }
}