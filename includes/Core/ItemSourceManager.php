<?php
/**
 * 项目配置管理
 *
 * 方案 B：项目优先架构 - 项目配置层
 *
 * @package WPBridge
 * @since 0.6.0
 */

namespace WPBridge\Core;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 项目配置管理类
 *
 * 管理项目（插件/主题）与更新源的绑定关系
 */
class ItemSourceManager {

    /**
     * 选项名称
     */
    const OPTION_NAME = 'wpbridge_item_sources';

    /**
     * 项目类型
     */
    const TYPE_PLUGIN   = 'plugin';
    const TYPE_THEME    = 'theme';
    const TYPE_MUPLUGIN = 'mu-plugin';
    const TYPE_DROPIN   = 'dropin';

    /**
     * 配置模式
     */
    const MODE_DEFAULT  = 'default';
    const MODE_CUSTOM   = 'custom';
    const MODE_DISABLED = 'disabled';

    /**
     * 缓存的配置
     *
     * @var array|null
     */
    private ?array $cached_configs = null;

    /**
     * 源注册表
     *
     * @var SourceRegistry
     */
    private SourceRegistry $source_registry;

    /**
     * 构造函数
     *
     * @param SourceRegistry $source_registry 源注册表
     */
    public function __construct( SourceRegistry $source_registry ) {
        $this->source_registry = $source_registry;
    }

    /**
     * 获取所有项目配置
     *
     * @return array
     */
    public function get_all(): array {
        if ( null === $this->cached_configs ) {
            $this->cached_configs = get_option( self::OPTION_NAME, [] );
        }
        return $this->cached_configs;
    }

    /**
     * 按类型获取项目配置
     *
     * @param string $type 项目类型
     * @return array
     */
    public function get_by_type( string $type ): array {
        return array_filter( $this->get_all(), fn( $c ) => ( $c['item_type'] ?? '' ) === $type );
    }

    /**
     * 获取单个项目配置
     *
     * @param string $item_key 项目键（plugin_basename 或主题目录名）
     * @return array|null
     */
    public function get( string $item_key ): ?array {
        foreach ( $this->get_all() as $config ) {
            if ( ( $config['item_key'] ?? '' ) === $item_key ) {
                return $config;
            }
        }
        return null;
    }

    /**
     * 通过 DID 获取项目配置
     *
     * @param string $did 项目 DID
     * @return array|null
     */
    public function get_by_did( string $did ): ?array {
        foreach ( $this->get_all() as $config ) {
            if ( ( $config['item_did'] ?? '' ) === $did ) {
                return $config;
            }
        }
        return null;
    }

    /**
     * 设置项目配置
     *
     * @param string $item_key  项目键
     * @param array  $config    配置数据
     * @return bool
     */
    public function set( string $item_key, array $config ): bool {
        $configs = $this->get_all();
        $found   = false;

        foreach ( $configs as $index => $existing ) {
            if ( ( $existing['item_key'] ?? '' ) === $item_key ) {
                $configs[ $index ] = array_merge( $existing, $config );
                $configs[ $index ]['item_key']    = $item_key;
                $configs[ $index ]['updated_at']  = current_time( 'mysql' );
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            $config = $this->normalize_config( $config );
            $config['item_key']   = $item_key;
            $config['created_at'] = current_time( 'mysql' );
            $config['updated_at'] = current_time( 'mysql' );
            $configs[] = $config;
        }

        $this->cached_configs = $configs;
        return update_option( self::OPTION_NAME, $configs, false );
    }

    /**
     * 删除项目配置
     *
     * @param string $item_key 项目键
     * @return bool
     */
    public function delete( string $item_key ): bool {
        $configs = $this->get_all();

        foreach ( $configs as $index => $config ) {
            if ( ( $config['item_key'] ?? '' ) === $item_key ) {
                unset( $configs[ $index ] );
                $configs = array_values( $configs );
                $this->cached_configs = $configs;
                return update_option( self::OPTION_NAME, $configs, false );
            }
        }
        return false;
    }

    /**
     * 设置项目的更新源
     *
     * @param string $item_key    项目键
     * @param string $source_key  源键
     * @param int    $priority    优先级
     * @return bool
     */
    public function set_source( string $item_key, string $source_key, int $priority = 50 ): bool {
        $config = $this->get( $item_key ) ?? [];
        $source_ids = $config['source_ids'] ?? [];

        // 检查源是否存在
        if ( ! $this->source_registry->get( $source_key ) ) {
            return false;
        }

        // 添加或更新源
        $source_ids[ $source_key ] = $priority;
        arsort( $source_ids ); // 按优先级排序

        return $this->set( $item_key, [
            'mode'       => self::MODE_CUSTOM,
            'source_ids' => $source_ids,
        ] );
    }

    /**
     * 移除项目的更新源
     *
     * @param string $item_key   项目键
     * @param string $source_key 源键
     * @return bool
     */
    public function remove_source( string $item_key, string $source_key ): bool {
        $config = $this->get( $item_key );
        if ( ! $config ) {
            return false;
        }

        $source_ids = $config['source_ids'] ?? [];
        unset( $source_ids[ $source_key ] );

        // 如果没有自定义源了，切回默认模式
        $mode = empty( $source_ids ) ? self::MODE_DEFAULT : self::MODE_CUSTOM;

        return $this->set( $item_key, [
            'mode'       => $mode,
            'source_ids' => $source_ids,
        ] );
    }

    /**
     * 禁用项目更新
     *
     * @param string $item_key 项目键
     * @return bool
     */
    public function disable_updates( string $item_key ): bool {
        return $this->set( $item_key, [ 'mode' => self::MODE_DISABLED ] );
    }

    /**
     * 启用项目更新（切回默认）
     *
     * @param string $item_key 项目键
     * @return bool
     */
    public function enable_updates( string $item_key ): bool {
        return $this->set( $item_key, [ 'mode' => self::MODE_DEFAULT ] );
    }

    /**
     * 固定项目到特定源
     *
     * @param string $item_key   项目键
     * @param string $source_key 源键
     * @return bool
     */
    public function pin_to_source( string $item_key, string $source_key ): bool {
        return $this->set( $item_key, [
            'mode'       => self::MODE_CUSTOM,
            'source_ids' => [ $source_key => 100 ],
            'pinned'     => true,
        ] );
    }

    /**
     * 获取项目的有效更新源列表
     *
     * @param string         $item_key 项目键
     * @param DefaultsManager $defaults 默认规则管理器
     * @return array 源列表（按优先级排序）
     */
    public function get_effective_sources( string $item_key, DefaultsManager $defaults ): array {
        $config = $this->get( $item_key );

        // 如果禁用更新，返回空
        if ( $config && ( $config['mode'] ?? '' ) === self::MODE_DISABLED ) {
            return [];
        }

        // 如果有自定义配置，使用自定义源
        if ( $config && ( $config['mode'] ?? '' ) === self::MODE_CUSTOM ) {
            $source_ids = $config['source_ids'] ?? [];
            $sources    = [];

            foreach ( $source_ids as $source_key => $priority ) {
                $source = $this->source_registry->get( $source_key );
                if ( $source && ! empty( $source['enabled'] ) ) {
                    $source['priority'] = $priority;
                    $sources[] = $source;
                }
            }

            // 按优先级排序
            usort( $sources, fn( $a, $b ) => ( $b['priority'] ?? 0 ) - ( $a['priority'] ?? 0 ) );
            return $sources;
        }

        // 使用默认源 - 从配置或 item_key 前缀推断类型
        $item_type = $this->resolve_item_type( $item_key, $config );
        return $defaults->get_default_sources( $item_type, $this->source_registry );
    }

    /**
     * 解析项目类型
     *
     * 优先从配置获取，否则从 item_key 前缀推断
     *
     * @param string     $item_key 项目键
     * @param array|null $config   项目配置（可能为 null）
     * @return string 项目类型
     */
    private function resolve_item_type( string $item_key, ?array $config ): string {
        // 优先使用配置中的类型
        if ( $config && ! empty( $config['item_type'] ) ) {
            return $config['item_type'];
        }

        // 从 item_key 前缀推断类型
        // 格式: "type:identifier" 例如 "plugin:hello-dolly/hello.php" 或 "theme:flavor"
        if ( strpos( $item_key, 'theme:' ) === 0 ) {
            return self::TYPE_THEME;
        }

        if ( strpos( $item_key, 'mu-plugin:' ) === 0 ) {
            return self::TYPE_MUPLUGIN;
        }

        if ( strpos( $item_key, 'dropin:' ) === 0 ) {
            return self::TYPE_DROPIN;
        }

        // 默认为插件类型
        return self::TYPE_PLUGIN;
    }

    /**
     * 批量设置项目配置
     *
     * @param array  $item_keys  项目键列表
     * @param string $source_key 源键
     * @param int    $priority   优先级
     * @return int 成功数量
     */
    public function batch_set_source( array $item_keys, string $source_key, int $priority = 50 ): int {
        $success = 0;
        foreach ( $item_keys as $item_key ) {
            if ( $this->set_source( $item_key, $source_key, $priority ) ) {
                $success++;
            }
        }
        return $success;
    }

    /**
     * 批量重置为默认
     *
     * @param array $item_keys 项目键列表
     * @return int 成功数量
     */
    public function batch_reset_to_default( array $item_keys ): int {
        $success = 0;
        foreach ( $item_keys as $item_key ) {
            if ( $this->delete( $item_key ) ) {
                $success++;
            }
        }
        return $success;
    }

    /**
     * 规范化配置数据
     *
     * @param array $config 配置数据
     * @return array
     */
    private function normalize_config( array $config ): array {
        return wp_parse_args( $config, [
            'item_key'           => '',
            'item_type'          => self::TYPE_PLUGIN,
            'item_slug'          => '',
            'item_did'           => '',
            'label'              => '',
            'mode'               => self::MODE_DEFAULT,
            'source_ids'         => [],
            'pinned'             => false,
            'signature_required' => false,
            'allow_unsigned'     => true,
            'allow_prerelease'   => false,
            'min_version'        => '',
            'max_version'        => '',
            'last_good_version'  => '',
            'metadata'           => [
                'preconfigured' => false,
                'installed'     => true,
            ],
            'created_at'         => current_time( 'mysql' ),
            'updated_at'         => current_time( 'mysql' ),
        ] );
    }

    /**
     * 清除缓存
     */
    public function clear_cache(): void {
        $this->cached_configs = null;
    }
}
