<?php
/**
 * JSON API 处理器
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
 * JSON API 处理器
 * 兼容 Plugin Update Checker 格式
 */
class JsonHandler extends AbstractHandler {

    /**
     * 获取能力列表
     *
     * @return array
     */
    public function get_capabilities(): array {
        return [
            'auth'     => 'token',
            'version'  => 'json',
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
        $url = $this->build_check_url( $slug );
        $data = $this->request( $url );

        if ( null === $data ) {
            return null;
        }

        // 处理 Plugin Update Checker 格式
        $remote_version = $data['version'] ?? '';

        if ( empty( $remote_version ) ) {
            Logger::warning( 'JSON 响应缺少版本信息', [ 'url' => $url ] );
            return null;
        }

        // 检查是否有更新
        if ( ! $this->is_newer_version( $version, $remote_version ) ) {
            Logger::debug( '无可用更新', [
                'slug'    => $slug,
                'current' => $version,
                'remote'  => $remote_version,
            ] );
            return null;
        }

        // 构建更新信息
        $info = UpdateInfo::from_array( $data );
        $info->slug = $slug;

        Logger::info( '发现更新', [
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
        $url = $this->build_check_url( $slug );
        return $this->request( $url );
    }

    /**
     * 构建检查 URL
     *
     * @param string $slug 插件/主题 slug
     * @return string
     */
    protected function build_check_url( string $slug ): string {
        $url = $this->source->api_url;

        // 如果 URL 包含占位符，替换它
        if ( strpos( $url, '{slug}' ) !== false ) {
            return str_replace( '{slug}', $slug, $url );
        }

        // 如果 URL 包含查询参数占位符
        if ( strpos( $url, '?' ) !== false ) {
            return add_query_arg( 'slug', $slug, $url );
        }

        return $url;
    }
}
