<?php
/**
 * FAIR 处理器
 *
 * FAIR 协议尚在开发中，当前版本仅注册 handler 类型，
 * 实际调用时返回 null 以避免 Fatal Error。
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
 * FAIR 协议处理器（占位）
 */
class FairHandler extends AbstractHandler {

    /**
     * 获取能力列表
     *
     * @return array
     */
    public function get_capabilities(): array {
        return [
            'auth'      => 'token',
            'version'   => 'fair',
            'download'  => 'direct',
            'signature' => 'ed25519',
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
        Logger::debug( 'FAIR handler not yet implemented', [ 'slug' => $slug ] );
        return null;
    }

    /**
     * 获取项目信息
     *
     * @param string $slug 插件/主题 slug
     * @return array|null
     */
    public function get_info( string $slug ): ?array {
        Logger::debug( 'FAIR handler not yet implemented', [ 'slug' => $slug ] );
        return null;
    }
}
