<?php
/**
 * 设置管理
 *
 * @package WPBridge
 */

namespace WPBridge\Core;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 设置管理类
 */
class Settings {

    /**
     * 选项名称
     */
    const OPTION_SOURCES     = 'wpbridge_sources';
    const OPTION_SETTINGS    = 'wpbridge_settings';
    const OPTION_AI_SETTINGS = 'wpbridge_ai_settings';

    /**
     * 默认设置
     *
     * @var array
     */
    private array $defaults = [
        'debug_mode'       => false,
        'cache_ttl'        => 43200, // 12 小时
        'request_timeout'  => 10,    // 秒
        'fallback_enabled' => true,
    ];

    /**
     * 缓存的设置
     *
     * @var array|null
     */
    private ?array $cached_settings = null;

    /**
     * 缓存的更新源
     *
     * @var array|null
     */
    private ?array $cached_sources = null;

    /**
     * 初始化默认设置
     */
    public function init_defaults(): void {
        // 初始化基础设置
        if ( false === get_option( self::OPTION_SETTINGS ) ) {
            update_option( self::OPTION_SETTINGS, $this->defaults );
        }

        // 初始化更新源（包含预置源）
        if ( false === get_option( self::OPTION_SOURCES ) ) {
            update_option( self::OPTION_SOURCES, $this->get_preset_sources() );
        }

        // 初始化 AI 设置
        if ( false === get_option( self::OPTION_AI_SETTINGS ) ) {
            update_option( self::OPTION_AI_SETTINGS, [
                'enabled'         => false,
                'mode'            => 'disabled',
                'whitelist'       => [ 'api.openai.com', 'api.anthropic.com' ],
                'custom_endpoint' => '',
            ] );
        }
    }

    /**
     * 获取预置更新源
     *
     * @return array
     */
    private function get_preset_sources(): array {
        return [
            [
                'id'         => 'wenpai-open',
                'name'       => __( '文派开源更新源', 'wpbridge' ),
                'type'       => 'json',
                'api_url'    => 'https://updates.wenpai.net/api/v1/plugins/{slug}/info',
                'slug'       => '',
                'item_type'  => 'plugin',
                'auth_token' => '',
                'enabled'    => true,
                'priority'   => 10,
                'is_preset'  => true,
                'metadata'   => [],
            ],
        ];
    }

    /**
     * 获取所有设置
     *
     * @return array
     */
    public function get_all(): array {
        if ( null === $this->cached_settings ) {
            $this->cached_settings = wp_parse_args(
                get_option( self::OPTION_SETTINGS, [] ),
                $this->defaults
            );
        }
        return $this->cached_settings;
    }

    /**
     * 获取单个设置
     *
     * @param string $key     设置键
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function get( string $key, $default = null ) {
        $settings = $this->get_all();
        return $settings[ $key ] ?? $default;
    }

    /**
     * 更新设置
     *
     * @param string $key   设置键
     * @param mixed  $value 设置值
     * @return bool
     */
    public function set( string $key, $value ): bool {
        $settings         = $this->get_all();
        $settings[ $key ] = $value;

        $this->cached_settings = $settings;
        return update_option( self::OPTION_SETTINGS, $settings );
    }

    /**
     * 批量更新设置
     *
     * @param array $settings 设置数组
     * @return bool
     */
    public function update( array $settings ): bool {
        $current = $this->get_all();
        $merged  = wp_parse_args( $settings, $current );

        $this->cached_settings = $merged;
        return update_option( self::OPTION_SETTINGS, $merged );
    }

    /**
     * 获取所有更新源
     *
     * @return array
     */
    public function get_sources(): array {
        if ( null === $this->cached_sources ) {
            $this->cached_sources = get_option( self::OPTION_SOURCES, [] );
        }
        return $this->cached_sources;
    }

    /**
     * 获取启用的更新源
     *
     * @return array
     */
    public function get_enabled_sources(): array {
        $sources = $this->get_sources();
        return array_filter( $sources, function( $source ) {
            return ! empty( $source['enabled'] );
        } );
    }

    /**
     * 获取单个更新源
     *
     * @param string $id 源 ID
     * @return array|null
     */
    public function get_source( string $id ): ?array {
        $sources = $this->get_sources();
        foreach ( $sources as $source ) {
            if ( $source['id'] === $id ) {
                return $source;
            }
        }
        return null;
    }

    /**
     * 添加更新源
     *
     * @param array $source 源数据
     * @return bool
     */
    public function add_source( array $source ): bool {
        $sources = $this->get_sources();

        // 生成唯一 ID
        if ( empty( $source['id'] ) ) {
            $source['id'] = 'source_' . wp_generate_uuid4();
        }

        // 设置默认值
        $source = wp_parse_args( $source, [
            'name'       => '',
            'type'       => 'json',
            'api_url'    => '',
            'slug'       => '',
            'item_type'  => 'plugin',
            'auth_token' => '',
            'enabled'    => true,
            'priority'   => 50,
            'is_preset'  => false,
            'metadata'   => [],
        ] );

        $sources[] = $source;

        $this->cached_sources = $sources;
        return update_option( self::OPTION_SOURCES, $sources );
    }

    /**
     * 更新更新源
     *
     * @param string $id     源 ID
     * @param array  $data   更新数据
     * @return bool
     */
    public function update_source( string $id, array $data ): bool {
        $sources = $this->get_sources();

        foreach ( $sources as $index => $source ) {
            if ( $source['id'] === $id ) {
                $sources[ $index ] = wp_parse_args( $data, $source );
                $this->cached_sources = $sources;
                return update_option( self::OPTION_SOURCES, $sources );
            }
        }

        return false;
    }

    /**
     * 删除更新源
     *
     * @param string $id 源 ID
     * @return bool
     */
    public function delete_source( string $id ): bool {
        $sources = $this->get_sources();

        foreach ( $sources as $index => $source ) {
            if ( $source['id'] === $id ) {
                // 不允许删除预置源
                if ( ! empty( $source['is_preset'] ) ) {
                    return false;
                }

                unset( $sources[ $index ] );
                $sources = array_values( $sources ); // 重新索引

                $this->cached_sources = $sources;
                return update_option( self::OPTION_SOURCES, $sources );
            }
        }

        return false;
    }

    /**
     * 启用/禁用更新源
     *
     * @param string $id      源 ID
     * @param bool   $enabled 是否启用
     * @return bool
     */
    public function toggle_source( string $id, bool $enabled ): bool {
        return $this->update_source( $id, [ 'enabled' => $enabled ] );
    }

    /**
     * 获取 AI 设置
     *
     * @return array
     */
    public function get_ai_settings(): array {
        return get_option( self::OPTION_AI_SETTINGS, [
            'enabled'         => false,
            'mode'            => 'disabled',
            'whitelist'       => [ 'api.openai.com', 'api.anthropic.com' ],
            'custom_endpoint' => '',
        ] );
    }

    /**
     * 更新 AI 设置
     *
     * @param array $settings AI 设置
     * @return bool
     */
    public function update_ai_settings( array $settings ): bool {
        $current = $this->get_ai_settings();
        $merged  = wp_parse_args( $settings, $current );
        return update_option( self::OPTION_AI_SETTINGS, $merged );
    }

    /**
     * 是否启用调试模式
     *
     * @return bool
     */
    public function is_debug(): bool {
        return (bool) $this->get( 'debug_mode', false );
    }

    /**
     * 获取缓存 TTL
     *
     * @return int
     */
    public function get_cache_ttl(): int {
        return (int) $this->get( 'cache_ttl', 43200 );
    }

    /**
     * 获取请求超时时间
     *
     * @return int
     */
    public function get_request_timeout(): int {
        return (int) $this->get( 'request_timeout', 10 );
    }

    /**
     * 清除设置缓存
     */
    public function clear_cache(): void {
        $this->cached_settings = null;
        $this->cached_sources  = null;
    }
}
