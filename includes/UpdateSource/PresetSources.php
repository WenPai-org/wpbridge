<?php
/**
 * 预置更新源配置
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 预置更新源配置类
 */
class PresetSources {

    /**
     * 文派开源更新源（默认预置）
     */
    const WENPAI_OPEN = [
        'id'        => 'wenpai-open',
        'name'      => '文派开源更新源',
        'type'      => SourceType::ARKPRESS,
        'api_url'   => 'https://api.wenpai.net/v1',
        'enabled'   => true,
        'priority'  => 10,
        'is_preset' => true,
    ];

    /**
     * ArkPress（文派自托管方案）
     */
    const ARKPRESS = [
        'id'        => 'arkpress',
        'name'      => 'ArkPress',
        'type'      => SourceType::ARKPRESS,
        'api_url'   => '', // 用户自定义
        'enabled'   => false,
        'priority'  => 20,
        'is_preset' => true,
    ];

    /**
     * AspireCloud
     */
    const ASPIRECLOUD = [
        'id'        => 'aspirecloud',
        'name'      => 'AspireCloud',
        'type'      => SourceType::ASPIRECLOUD,
        'api_url'   => 'https://api.aspirepress.org',
        'enabled'   => false,
        'priority'  => 30,
        'is_preset' => true,
    ];

    /**
     * FAIR Package Manager
     */
    const FAIR = [
        'id'        => 'fair',
        'name'      => 'FAIR Package Manager',
        'type'      => SourceType::FAIR,
        'api_url'   => 'https://api.fairpm.org',
        'enabled'   => false,
        'priority'  => 40,
        'is_preset' => true,
    ];

    /**
     * 获取所有预置源
     *
     * @return array
     */
    public static function get_all(): array {
        return [
            self::WENPAI_OPEN,
            // 以下预置源默认不添加，用户可手动启用
            // self::ARKPRESS,
            // self::ASPIRECLOUD,
            // self::FAIR,
        ];
    }

    /**
     * 获取可用的预置源模板
     *
     * @return array
     */
    public static function get_templates(): array {
        return [
            'arkpress'    => self::ARKPRESS,
            'aspirecloud' => self::ASPIRECLOUD,
            'fair'        => self::FAIR,
        ];
    }

    /**
     * 根据 ID 获取预置源
     *
     * @param string $id 预置源 ID
     * @return array|null
     */
    public static function get_by_id( string $id ): ?array {
        $all = [
            'wenpai-open' => self::WENPAI_OPEN,
            'arkpress'    => self::ARKPRESS,
            'aspirecloud' => self::ASPIRECLOUD,
            'fair'        => self::FAIR,
        ];

        return $all[ $id ] ?? null;
    }

    /**
     * 检查是否是预置源 ID
     *
     * @param string $id 源 ID
     * @return bool
     */
    public static function is_preset_id( string $id ): bool {
        return in_array( $id, [ 'wenpai-open', 'arkpress', 'aspirecloud', 'fair' ], true );
    }
}
