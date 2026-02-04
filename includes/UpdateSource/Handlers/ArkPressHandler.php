<?php
/**
 * ArkPress 处理器
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
 * ArkPress 处理器
 * 文派自托管方案，AspireCloud 分叉版本
 */
class ArkPressHandler extends AbstractHandler {

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
            'mirror'     => true,
        ];
    }

    /**
     * 获取检查 URL
     *
     * @return string
     */
    public function get_check_url(): string {
        return rtrim( $this->source->api_url, '/' ) . '/plugins/update-check';
    }

    /**
     * 检查更新
     *
     * @param string $slug    插件/主题 slug
     * @param string $version 当前版本
     * @return UpdateInfo|null
     */
    public function check_update( string $slug, string $version ): ?UpdateInfo {
        $url = $this->build_plugin_url( $slug );
        $data = $this->request( $url );

        if ( null === $data ) {
            return null;
        }

        // ArkPress API 响应格式
        $remote_version = $data['version'] ?? $data['new_version'] ?? '';

        if ( empty( $remote_version ) ) {
            Logger::warning( 'ArkPress 响应缺少版本信息', [ 'url' => $url, 'slug' => $slug ] );
            return null;
        }

        // 检查是否有更新
        if ( ! $this->is_newer_version( $version, $remote_version ) ) {
            Logger::debug( 'ArkPress: 无可用更新', [
                'slug'    => $slug,
                'current' => $version,
                'remote'  => $remote_version,
            ] );
            return null;
        }

        // 构建更新信息
        $info = new UpdateInfo();
        $info->slug         = $slug;
        $info->version      = $remote_version;
        $info->download_url = $data['download_url'] ?? $data['package'] ?? '';
        $info->details_url  = $data['details_url'] ?? $data['url'] ?? '';
        $info->requires     = $data['requires'] ?? '';
        $info->tested       = $data['tested'] ?? '';
        $info->requires_php = $data['requires_php'] ?? '';
        $info->last_updated = $data['last_updated'] ?? '';
        $info->icons        = $data['icons'] ?? [];
        $info->banners      = $data['banners'] ?? [];

        if ( isset( $data['sections'] ) ) {
            $info->changelog   = $data['sections']['changelog'] ?? '';
            $info->description = $data['sections']['description'] ?? '';
        }

        Logger::info( 'ArkPress: 发现更新', [
            'slug'    => $slug,
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
        $url = $this->build_plugin_url( $slug );
        return $this->request( $url );
    }

    /**
     * 批量检查更新
     *
     * @param array $plugins 插件列表 [ slug => version ]
     * @return array<string, UpdateInfo>
     */
    public function check_updates_batch( array $plugins ): array {
        $url = rtrim( $this->source->api_url, '/' ) . '/plugins/update-check';

        $response = wp_remote_post( $url, [
            'timeout' => $this->timeout,
            'headers' => array_merge( $this->get_headers(), [
                'Content-Type' => 'application/json',
            ] ),
            'body'    => wp_json_encode( [
                'plugins' => $plugins,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            Logger::error( 'ArkPress 批量检查失败', [
                'error' => $response->get_error_message(),
            ] );
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) || ! isset( $data['plugins'] ) ) {
            return [];
        }

        $updates = [];
        foreach ( $data['plugins'] as $slug => $plugin_data ) {
            if ( empty( $plugin_data['version'] ) ) {
                continue;
            }

            $current = $plugins[ $slug ] ?? '0.0.0';
            if ( $this->is_newer_version( $current, $plugin_data['version'] ) ) {
                $info = UpdateInfo::from_array( $plugin_data );
                $info->slug = $slug;
                $updates[ $slug ] = $info;
            }
        }

        return $updates;
    }

    /**
     * 构建插件 URL
     *
     * @param string $slug 插件 slug
     * @return string
     */
    protected function build_plugin_url( string $slug ): string {
        return rtrim( $this->source->api_url, '/' ) . '/plugins/' . $slug;
    }
}
