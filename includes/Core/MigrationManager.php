<?php
/**
 * 数据迁移管理
 *
 * 方案 B：从方案 A 迁移到项目优先架构
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
 * 数据迁移管理类
 */
class MigrationManager {

    /**
     * 迁移版本选项
     */
    const OPTION_VERSION = 'wpbridge_migration_version';

    /**
     * 备份选项前缀
     */
    const BACKUP_PREFIX = 'wpbridge_backup_';

    /**
     * 当前迁移版本
     */
    const CURRENT_VERSION = '0.6.0';

    /**
     * 旧选项名称（方案 A）
     */
    const OLD_SOURCES  = 'wpbridge_sources';
    const OLD_SETTINGS = 'wpbridge_settings';

    /**
     * 源注册表
     *
     * @var SourceRegistry
     */
    private SourceRegistry $source_registry;

    /**
     * 项目配置管理器
     *
     * @var ItemSourceManager
     */
    private ItemSourceManager $item_manager;

    /**
     * 默认规则管理器
     *
     * @var DefaultsManager
     */
    private DefaultsManager $defaults_manager;

    /**
     * 迁移日志
     *
     * @var array
     */
    private array $log = [];

    /**
     * 源 ID 映射表（旧 ID → 新 key）
     *
     * @var array<string, string>
     */
    private array $source_id_map = [];

    /**
     * 构造函数
     *
     * @param SourceRegistry    $source_registry 源注册表
     * @param ItemSourceManager $item_manager    项目配置管理器
     * @param DefaultsManager   $defaults_manager 默认规则管理器
     */
    public function __construct(
        SourceRegistry $source_registry,
        ItemSourceManager $item_manager,
        DefaultsManager $defaults_manager
    ) {
        $this->source_registry  = $source_registry;
        $this->item_manager     = $item_manager;
        $this->defaults_manager = $defaults_manager;
    }

    /**
     * 检查是否需要迁移
     *
     * @return bool
     */
    public function needs_migration(): bool {
        $current = get_option( self::OPTION_VERSION, '0.0.0' );
        return version_compare( $current, self::CURRENT_VERSION, '<' );
    }

    /**
     * 执行迁移
     *
     * @return array 迁移结果
     */
    public function migrate(): array {
        $this->log = [];
        $this->source_id_map = [];
        $this->log( 'info', '开始迁移到方案 B (v' . self::CURRENT_VERSION . ')' );

        try {
            // 1. 备份旧数据
            $this->backup_old_data();

            // 2. 迁移源数据
            $this->migrate_sources();

            // 3. 迁移项目配置
            $this->migrate_item_configs();

            // 4. 设置默认规则
            $this->setup_defaults();

            // 5. 验证迁移
            $validation = $this->validate_migration();
            if ( ! $validation['success'] ) {
                throw new \Exception( '迁移验证失败: ' . implode( ', ', $validation['errors'] ) );
            }

            // 6. 更新版本号
            update_option( self::OPTION_VERSION, self::CURRENT_VERSION );

            // 7. 清理旧数据（保留备份以便回滚）
            $this->cleanup_old_data();

            $this->log( 'success', '迁移完成' );

            return [
                'success'        => true,
                'log'            => $this->log,
                'source_id_map'  => $this->source_id_map,
            ];

        } catch ( \Exception $e ) {
            $this->log( 'error', '迁移失败: ' . $e->getMessage() );

            // 尝试回滚
            $this->rollback();

            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'log'     => $this->log,
            ];
        }
    }

    /**
     * 备份旧数据
     */
    private function backup_old_data(): void {
        $this->log( 'info', '备份旧数据...' );

        $old_sources  = get_option( self::OLD_SOURCES, [] );
        $old_settings = get_option( self::OLD_SETTINGS, [] );

        update_option( self::BACKUP_PREFIX . 'sources', $old_sources, false );
        update_option( self::BACKUP_PREFIX . 'settings', $old_settings, false );
        update_option( self::BACKUP_PREFIX . 'timestamp', time(), false );

        $this->log( 'info', '已备份 ' . count( $old_sources ) . ' 个源配置' );
    }

    /**
     * 迁移源数据
     */
    private function migrate_sources(): void {
        $this->log( 'info', '迁移源数据...' );

        $old_sources = get_option( self::OLD_SOURCES, [] );
        $migrated    = 0;
        $skipped     = 0;

        foreach ( $old_sources as $old_source ) {
            $source_key = $this->convert_source( $old_source );
            if ( $source_key ) {
                $migrated++;
            } else {
                $skipped++;
            }
        }

        $this->log( 'info', "源迁移完成: {$migrated} 成功, {$skipped} 跳过" );
    }

    /**
     * 转换单个源
     *
     * @param array $old_source 旧源数据
     * @return string|false 成功返回 source_key
     */
    private function convert_source( array $old_source ) {
        $old_id = $old_source['id'] ?? '';

        // 跳过预置源（新系统会自动创建）
        if ( ! empty( $old_source['is_preset'] ) ) {
            // 预置源映射到新的预置源 key
            $preset_map = [
                'wporg'           => 'wporg',
                'wenpai'          => 'wenpai-mirror',
                'wenpai-mirror'   => 'wenpai-mirror',
                'fair'            => 'fair-aspirecloud',
                'fair-aspirecloud' => 'fair-aspirecloud',
            ];

            if ( $old_id && isset( $preset_map[ $old_id ] ) ) {
                $this->source_id_map[ $old_id ] = $preset_map[ $old_id ];
            }

            return false;
        }

        // 映射旧类型到新类型
        $type_map = [
            'json'     => SourceRegistry::TYPE_JSON,
            'github'   => SourceRegistry::TYPE_GIT,
            'gitlab'   => SourceRegistry::TYPE_GIT,
            'arkpress' => SourceRegistry::TYPE_ARKPRESS,
            'custom'   => SourceRegistry::TYPE_CUSTOM,
        ];

        $new_source = [
            'source_key'       => $old_id,
            'name'             => $old_source['name'] ?? '',
            'type'             => $type_map[ $old_source['type'] ?? 'custom' ] ?? SourceRegistry::TYPE_CUSTOM,
            'api_url'          => $old_source['api_url'] ?? '',
            'enabled'          => $old_source['enabled'] ?? true,
            'default_priority' => $old_source['priority'] ?? 50,
            'auth_type'        => ! empty( $old_source['auth_token'] ) ? SourceRegistry::AUTH_BEARER : SourceRegistry::AUTH_NONE,
            'auth_secret_ref'  => ! empty( $old_source['auth_token'] ) ? $this->store_secret( $old_source['auth_token'] ) : '',
        ];

        $new_key = $this->source_registry->add( $new_source );

        // 记录映射关系
        if ( $new_key && $old_id ) {
            $this->source_id_map[ $old_id ] = $new_key;
        }

        return $new_key;
    }

    /**
     * 迁移项目配置
     */
    private function migrate_item_configs(): void {
        $this->log( 'info', '迁移项目配置...' );

        $old_sources = get_option( self::OLD_SOURCES, [] );
        $migrated    = 0;
        $skipped     = 0;

        foreach ( $old_sources as $old_source ) {
            // 跳过通配符配置（将作为默认规则处理）
            if ( empty( $old_source['slug'] ) || $old_source['slug'] === '*' ) {
                continue;
            }

            $item_type = $old_source['item_type'] ?? 'plugin';
            $item_key  = $this->resolve_item_key( $old_source['slug'], $item_type );

            if ( ! $item_key ) {
                $skipped++;
                continue;
            }

            // 使用映射后的源 key
            $old_source_id = $old_source['id'] ?? '';
            $new_source_key = $this->source_id_map[ $old_source_id ] ?? $old_source_id;

            // 验证新源存在
            if ( ! $this->source_registry->get( $new_source_key ) ) {
                $this->log( 'warning', "跳过项目 {$item_key}: 源 {$new_source_key} 不存在" );
                $skipped++;
                continue;
            }

            $this->item_manager->set( $item_key, [
                'item_type'  => $item_type,
                'item_slug'  => $old_source['slug'],
                'mode'       => ItemSourceManager::MODE_CUSTOM,
                'source_ids' => [ $new_source_key => $old_source['priority'] ?? 50 ],
            ] );
            $migrated++;
        }

        $this->log( 'info', "项目配置迁移完成: {$migrated} 成功, {$skipped} 跳过" );
    }

    /**
     * 解析项目键
     *
     * @param string $slug      项目 slug
     * @param string $item_type 项目类型
     * @return string|null
     */
    private function resolve_item_key( string $slug, string $item_type ): ?string {
        if ( $item_type === 'plugin' ) {
            // 尝试查找已安装的插件
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugins = get_plugins();
            foreach ( $plugins as $plugin_file => $plugin_data ) {
                // 匹配 slug
                $plugin_slug = dirname( $plugin_file );
                if ( $plugin_slug === '.' ) {
                    $plugin_slug = basename( $plugin_file, '.php' );
                }

                if ( $plugin_slug === $slug ) {
                    return 'plugin:' . $plugin_file;
                }
            }

            // 未找到已安装插件，使用 slug 作为预配置
            return 'plugin:' . $slug;
        }

        if ( $item_type === 'theme' ) {
            // 检查主题是否存在
            $theme = wp_get_theme( $slug );
            if ( $theme->exists() ) {
                return 'theme:' . $slug;
            }

            // 预配置
            return 'theme:' . $slug;
        }

        return null;
    }

    /**
     * 设置默认规则
     */
    private function setup_defaults(): void {
        $this->log( 'info', '设置默认规则...' );

        $old_sources = get_option( self::OLD_SOURCES, [] );

        // 查找通配符配置
        $plugin_defaults = [];
        $theme_defaults  = [];

        foreach ( $old_sources as $old_source ) {
            if ( empty( $old_source['slug'] ) || $old_source['slug'] === '*' ) {
                $item_type     = $old_source['item_type'] ?? 'plugin';
                $old_source_id = $old_source['id'] ?? '';

                // 使用映射后的源 key
                $new_source_key = $this->source_id_map[ $old_source_id ] ?? $old_source_id;

                // 验证源存在
                if ( ! $this->source_registry->get( $new_source_key ) ) {
                    continue;
                }

                if ( $item_type === 'plugin' && $new_source_key ) {
                    $plugin_defaults[] = $new_source_key;
                } elseif ( $item_type === 'theme' && $new_source_key ) {
                    $theme_defaults[] = $new_source_key;
                }
            }
        }

        // 设置默认源顺序
        if ( ! empty( $plugin_defaults ) ) {
            $this->defaults_manager->set_source_order( DefaultsManager::SCOPE_PLUGIN, $plugin_defaults );
        }

        if ( ! empty( $theme_defaults ) ) {
            $this->defaults_manager->set_source_order( DefaultsManager::SCOPE_THEME, $theme_defaults );
        }

        $this->log( 'info', '默认规则设置完成' );
    }

    /**
     * 验证迁移
     *
     * @return array
     */
    private function validate_migration(): array {
        $errors = [];

        // 检查源注册表
        $sources = $this->source_registry->get_all();
        if ( empty( $sources ) ) {
            $errors[] = '源注册表为空';
        }

        // 检查预置源
        $preset_keys = [ 'wporg', 'wenpai-mirror', 'fair-aspirecloud' ];
        foreach ( $preset_keys as $key ) {
            if ( ! $this->source_registry->get( $key ) ) {
                $errors[] = "预置源 {$key} 不存在";
            }
        }

        // 检查默认规则
        $defaults = $this->defaults_manager->get_all();
        if ( empty( $defaults ) ) {
            $errors[] = '默认规则为空';
        }

        return [
            'success' => empty( $errors ),
            'errors'  => $errors,
        ];
    }

    /**
     * 回滚迁移
     *
     * @return bool
     */
    public function rollback(): bool {
        $this->log( 'warning', '开始回滚...' );

        try {
            // 恢复备份数据
            $backup_sources  = get_option( self::BACKUP_PREFIX . 'sources', [] );
            $backup_settings = get_option( self::BACKUP_PREFIX . 'settings', [] );

            if ( ! empty( $backup_sources ) ) {
                update_option( self::OLD_SOURCES, $backup_sources );
            }

            if ( ! empty( $backup_settings ) ) {
                update_option( self::OLD_SETTINGS, $backup_settings );
            }

            // 删除新数据
            delete_option( SourceRegistry::OPTION_NAME );
            delete_option( ItemSourceManager::OPTION_NAME );
            delete_option( DefaultsManager::OPTION_NAME );
            delete_option( self::OPTION_VERSION );

            $this->log( 'info', '回滚完成' );
            return true;

        } catch ( \Exception $e ) {
            $this->log( 'error', '回滚失败: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * 清理备份数据
     *
     * @return bool
     */
    public function cleanup_backup(): bool {
        delete_option( self::BACKUP_PREFIX . 'sources' );
        delete_option( self::BACKUP_PREFIX . 'settings' );
        delete_option( self::BACKUP_PREFIX . 'timestamp' );
        return true;
    }

    /**
     * 清理旧数据
     *
     * 迁移成功后删除旧的选项数据
     * 注意：备份数据保留以便回滚
     */
    private function cleanup_old_data(): void {
        $this->log( 'info', '清理旧数据...' );

        // 删除旧的选项
        delete_option( self::OLD_SOURCES );
        delete_option( self::OLD_SETTINGS );

        $this->log( 'info', '旧数据清理完成' );
    }

    /**
     * 完全清理（包括备份）
     *
     * 在确认迁移稳定后调用
     *
     * @return bool
     */
    public function full_cleanup(): bool {
        $this->cleanup_old_data();
        $this->cleanup_backup();
        return true;
    }

    /**
     * 存储敏感信息
     *
     * @param string $secret 敏感信息
     * @return string 引用键
     */
    private function store_secret( string $secret ): string {
        $ref = 'secret_' . wp_generate_uuid4();
        update_option( 'wpbridge_secret_' . $ref, $secret, false );
        return $ref;
    }

    /**
     * 记录日志
     *
     * @param string $level   日志级别
     * @param string $message 日志消息
     */
    private function log( string $level, string $message ): void {
        $this->log[] = [
            'level'     => $level,
            'message'   => $message,
            'timestamp' => current_time( 'mysql' ),
        ];

        // 同时写入 WordPress 日志
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[WPBridge Migration] [{$level}] {$message}" );
        }
    }

    /**
     * 获取迁移日志
     *
     * @return array
     */
    public function get_log(): array {
        return $this->log;
    }

    /**
     * 获取源 ID 映射表
     *
     * @return array<string, string> 旧 ID → 新 key
     */
    public function get_source_id_map(): array {
        return $this->source_id_map;
    }
}
