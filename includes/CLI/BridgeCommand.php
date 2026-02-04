<?php
/**
 * WP-CLI 命令
 *
 * @package WPBridge
 */

namespace WPBridge\CLI;

use WPBridge\Core\Settings;
use WPBridge\Core\Plugin;
use WPBridge\UpdateSource\SourceManager;
use WPBridge\UpdateSource\SourceModel;
use WPBridge\UpdateSource\SourceType;
use WPBridge\Cache\HealthChecker;
use WPBridge\Performance\BackgroundUpdater;
use WP_CLI;
use WP_CLI\Utils;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 管理 WPBridge 更新源和缓存
 *
 * ## EXAMPLES
 *
 *     # 列出所有更新源
 *     $ wp bridge source list
 *
 *     # 添加更新源
 *     $ wp bridge source add https://example.com/updates.json --name="My Source"
 *
 *     # 检查所有源
 *     $ wp bridge check
 *
 *     # 清除缓存
 *     $ wp bridge cache clear
 */
class BridgeCommand {

    /**
     * 设置实例
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * 源管理器
     *
     * @var SourceManager
     */
    private SourceManager $source_manager;

    /**
     * 构造函数
     */
    public function __construct() {
        $this->settings       = new Settings();
        $this->source_manager = new SourceManager( $this->settings );
    }

    /**
     * 列出所有更新源
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : 输出格式
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * [--enabled]
     * : 只显示启用的源
     *
     * ## EXAMPLES
     *
     *     $ wp bridge source list
     *     $ wp bridge source list --format=json
     *     $ wp bridge source list --enabled
     *
     * @subcommand source list
     */
    public function source_list( $args, $assoc_args ) {
        $enabled_only = Utils\get_flag_value( $assoc_args, 'enabled', false );

        $sources = $enabled_only
            ? $this->source_manager->get_enabled()
            : $this->source_manager->get_all();

        if ( empty( $sources ) ) {
            WP_CLI::warning( '没有更新源' );
            return;
        }

        $items = [];
        foreach ( $sources as $source ) {
            $items[] = [
                'id'       => $source->id,
                'name'     => $source->name,
                'type'     => $source->type,
                'api_url'  => $source->api_url,
                'slug'     => $source->slug ?: '(all)',
                'enabled'  => $source->enabled ? 'yes' : 'no',
                'priority' => $source->priority,
                'preset'   => $source->is_preset ? 'yes' : 'no',
            ];
        }

        $format = Utils\get_flag_value( $assoc_args, 'format', 'table' );

        Utils\format_items( $format, $items, [
            'id', 'name', 'type', 'api_url', 'slug', 'enabled', 'priority', 'preset'
        ] );
    }

    /**
     * 添加更新源
     *
     * ## OPTIONS
     *
     * <url>
     * : 更新源 URL
     *
     * [--name=<name>]
     * : 源名称
     *
     * [--type=<type>]
     * : 源类型
     * ---
     * default: json
     * options:
     *   - json
     *   - github
     *   - gitlab
     *   - gitee
     *   - arkpress
     *   - aspirecloud
     * ---
     *
     * [--slug=<slug>]
     * : 插件/主题 slug
     *
     * [--item-type=<item_type>]
     * : 项目类型
     * ---
     * default: plugin
     * options:
     *   - plugin
     *   - theme
     * ---
     *
     * [--priority=<priority>]
     * : 优先级 (0-100)
     * ---
     * default: 50
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp bridge source add https://example.com/updates.json --name="My Plugin"
     *     $ wp bridge source add github.com/user/repo --type=github --name="GitHub Plugin"
     *
     * @subcommand source add
     */
    public function source_add( $args, $assoc_args ) {
        $url = $args[0];

        $source = new SourceModel();
        $source->api_url   = $url;
        $source->name      = Utils\get_flag_value( $assoc_args, 'name', '' );
        $source->type      = Utils\get_flag_value( $assoc_args, 'type', SourceType::JSON );
        $source->slug      = Utils\get_flag_value( $assoc_args, 'slug', '' );
        $source->item_type = Utils\get_flag_value( $assoc_args, 'item-type', 'plugin' );
        $source->priority  = (int) Utils\get_flag_value( $assoc_args, 'priority', 50 );
        $source->enabled   = true;

        // 自动检测类型
        if ( empty( $assoc_args['type'] ) ) {
            $source->type = $this->detect_source_type( $url );
        }

        // 自动生成名称
        if ( empty( $source->name ) ) {
            $source->name = $this->generate_source_name( $url, $source->type );
        }

        // 验证
        $errors = $source->validate();
        if ( ! empty( $errors ) ) {
            WP_CLI::error( implode( "\n", $errors ) );
        }

        if ( $this->source_manager->add( $source ) ) {
            WP_CLI::success( sprintf( '已添加更新源: %s', $source->name ) );
        } else {
            WP_CLI::error( '添加失败' );
        }
    }

    /**
     * 删除更新源
     *
     * ## OPTIONS
     *
     * <id>
     * : 源 ID
     *
     * [--yes]
     * : 跳过确认
     *
     * ## EXAMPLES
     *
     *     $ wp bridge source remove source_abc123
     *
     * @subcommand source remove
     */
    public function source_remove( $args, $assoc_args ) {
        $source_id = $args[0];

        $source = $this->source_manager->get( $source_id );

        if ( null === $source ) {
            WP_CLI::error( '源不存在' );
        }

        if ( $source->is_preset ) {
            WP_CLI::error( '不能删除预置源' );
        }

        WP_CLI::confirm( sprintf( '确定要删除 "%s" 吗?', $source->name ), $assoc_args );

        if ( $this->source_manager->delete( $source_id ) ) {
            WP_CLI::success( '已删除' );
        } else {
            WP_CLI::error( '删除失败' );
        }
    }

    /**
     * 启用更新源
     *
     * ## OPTIONS
     *
     * <id>
     * : 源 ID
     *
     * ## EXAMPLES
     *
     *     $ wp bridge source enable source_abc123
     *
     * @subcommand source enable
     */
    public function source_enable( $args, $assoc_args ) {
        $source_id = $args[0];

        if ( $this->source_manager->toggle( $source_id, true ) ) {
            WP_CLI::success( '已启用' );
        } else {
            WP_CLI::error( '操作失败' );
        }
    }

    /**
     * 禁用更新源
     *
     * ## OPTIONS
     *
     * <id>
     * : 源 ID
     *
     * ## EXAMPLES
     *
     *     $ wp bridge source disable source_abc123
     *
     * @subcommand source disable
     */
    public function source_disable( $args, $assoc_args ) {
        $source_id = $args[0];

        if ( $this->source_manager->toggle( $source_id, false ) ) {
            WP_CLI::success( '已禁用' );
        } else {
            WP_CLI::error( '操作失败' );
        }
    }

    /**
     * 检查所有更新源
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : 输出格式
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp bridge check
     *
     * @subcommand check
     */
    public function check( $args, $assoc_args ) {
        $sources = $this->source_manager->get_enabled_sorted();

        if ( empty( $sources ) ) {
            WP_CLI::warning( '没有启用的更新源' );
            return;
        }

        $checker = new HealthChecker();
        $results = [];

        foreach ( $sources as $source ) {
            WP_CLI::log( sprintf( '检查 %s...', $source->name ) );

            $status = $checker->check( $source, true );

            $results[] = [
                'id'            => $source->id,
                'name'          => $source->name,
                'status'        => $status->status,
                'response_time' => $status->response_time . 'ms',
                'error'         => $status->error ?: '-',
            ];
        }

        $format = Utils\get_flag_value( $assoc_args, 'format', 'table' );

        WP_CLI::log( '' );
        Utils\format_items( $format, $results, [
            'id', 'name', 'status', 'response_time', 'error'
        ] );

        // 统计
        $healthy = count( array_filter( $results, fn( $r ) => $r['status'] === 'healthy' ) );
        $total   = count( $results );

        WP_CLI::log( '' );
        WP_CLI::log( sprintf( '健康: %d/%d', $healthy, $total ) );
    }

    /**
     * 清除缓存
     *
     * ## EXAMPLES
     *
     *     $ wp bridge cache clear
     *
     * @subcommand cache clear
     */
    public function cache_clear( $args, $assoc_args ) {
        Plugin::clear_all_cache();
        WP_CLI::success( '缓存已清除' );
    }

    /**
     * 查看缓存状态
     *
     * ## EXAMPLES
     *
     *     $ wp bridge cache status
     *
     * @subcommand cache status
     */
    public function cache_status( $args, $assoc_args ) {
        $cache = new \WPBridge\Cache\CacheManager();
        $stats = $cache->get_stats();

        WP_CLI::log( sprintf( 'Transient 缓存数: %d', $stats['transient_count'] ) );
        WP_CLI::log( sprintf( '对象缓存: %s', $stats['object_cache'] ? '是' : '否' ) );
        WP_CLI::log( sprintf( '对象缓存类型: %s', $stats['object_cache_type'] ) );

        // 后台更新状态
        $updater = new BackgroundUpdater( $this->settings );
        $status  = $updater->get_status();

        WP_CLI::log( '' );
        WP_CLI::log( '后台更新:' );
        WP_CLI::log( sprintf( '  已调度: %s', $status['scheduled'] ? '是' : '否' ) );
        if ( $status['next_run'] ) {
            WP_CLI::log( sprintf( '  下次运行: %s (%s)', $status['next_run'], $status['next_run_human'] ) );
        }
    }

    /**
     * 生成诊断报告
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : 输出格式
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp bridge diagnose
     *
     * @subcommand diagnose
     */
    public function diagnose( $args, $assoc_args ) {
        $format = Utils\get_flag_value( $assoc_args, 'format', 'text' );

        $report = [
            'wpbridge_version' => WPBRIDGE_VERSION,
            'wordpress_version' => get_bloginfo( 'version' ),
            'php_version' => PHP_VERSION,
            'sources' => $this->source_manager->get_stats(),
            'settings' => $this->settings->get_all(),
        ];

        if ( $format === 'json' ) {
            WP_CLI::log( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
        } else {
            WP_CLI::log( '=== WPBridge 诊断报告 ===' );
            WP_CLI::log( '' );
            WP_CLI::log( sprintf( 'WPBridge 版本: %s', $report['wpbridge_version'] ) );
            WP_CLI::log( sprintf( 'WordPress 版本: %s', $report['wordpress_version'] ) );
            WP_CLI::log( sprintf( 'PHP 版本: %s', $report['php_version'] ) );
            WP_CLI::log( '' );
            WP_CLI::log( '更新源统计:' );
            WP_CLI::log( sprintf( '  总数: %d', $report['sources']['total'] ) );
            WP_CLI::log( sprintf( '  已启用: %d', $report['sources']['enabled'] ) );
            WP_CLI::log( '' );
            WP_CLI::log( '设置:' );
            WP_CLI::log( sprintf( '  调试模式: %s', $report['settings']['debug_mode'] ? '是' : '否' ) );
            WP_CLI::log( sprintf( '  缓存时间: %d 秒', $report['settings']['cache_ttl'] ) );
            WP_CLI::log( sprintf( '  请求超时: %d 秒', $report['settings']['request_timeout'] ) );
        }
    }

    /**
     * 导出配置
     *
     * ## OPTIONS
     *
     * [<file>]
     * : 导出文件路径
     *
     * ## EXAMPLES
     *
     *     $ wp bridge config export
     *     $ wp bridge config export /path/to/config.json
     *
     * @subcommand config export
     */
    public function config_export( $args, $assoc_args ) {
        $sources  = $this->source_manager->get_all();
        $settings = $this->settings->get_all();

        $export = [
            'version'  => WPBRIDGE_VERSION,
            'exported' => current_time( 'mysql' ),
            'sources'  => array_map( fn( $s ) => $s->to_array(), $sources ),
            'settings' => $settings,
        ];

        // 移除敏感信息
        foreach ( $export['sources'] as &$source ) {
            $source['auth_token'] = '';
        }

        $json = wp_json_encode( $export, JSON_PRETTY_PRINT );

        if ( ! empty( $args[0] ) ) {
            file_put_contents( $args[0], $json );
            WP_CLI::success( sprintf( '已导出到 %s', $args[0] ) );
        } else {
            WP_CLI::log( $json );
        }
    }

    /**
     * 导入配置
     *
     * ## OPTIONS
     *
     * <file>
     * : 配置文件路径
     *
     * [--yes]
     * : 跳过确认
     *
     * ## EXAMPLES
     *
     *     $ wp bridge config import /path/to/config.json
     *
     * @subcommand config import
     */
    public function config_import( $args, $assoc_args ) {
        $file = $args[0];

        if ( ! file_exists( $file ) ) {
            WP_CLI::error( '文件不存在' );
        }

        $json = file_get_contents( $file );
        $data = json_decode( $json, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            WP_CLI::error( 'JSON 解析失败: ' . json_last_error_msg() );
        }

        WP_CLI::confirm( '这将覆盖现有配置，确定继续吗?', $assoc_args );

        // 导入设置
        if ( ! empty( $data['settings'] ) ) {
            $this->settings->update( $data['settings'] );
            WP_CLI::log( '已导入设置' );
        }

        // 导入源
        if ( ! empty( $data['sources'] ) ) {
            $count = 0;
            foreach ( $data['sources'] as $source_data ) {
                // 跳过预置源
                if ( ! empty( $source_data['is_preset'] ) ) {
                    continue;
                }

                $source = SourceModel::from_array( $source_data );
                $source->id = ''; // 生成新 ID

                if ( $this->source_manager->add( $source ) ) {
                    $count++;
                }
            }
            WP_CLI::log( sprintf( '已导入 %d 个更新源', $count ) );
        }

        WP_CLI::success( '导入完成' );
    }

    /**
     * 检测源类型
     *
     * @param string $url URL
     * @return string
     */
    private function detect_source_type( string $url ): string {
        if ( strpos( $url, 'github.com' ) !== false || strpos( $url, 'github/' ) !== false ) {
            return SourceType::GITHUB;
        }

        if ( strpos( $url, 'gitlab.com' ) !== false || strpos( $url, 'gitlab/' ) !== false ) {
            return SourceType::GITLAB;
        }

        if ( strpos( $url, 'gitee.com' ) !== false || strpos( $url, 'gitee/' ) !== false ) {
            return SourceType::GITEE;
        }

        return SourceType::JSON;
    }

    /**
     * 生成源名称
     *
     * @param string $url  URL
     * @param string $type 类型
     * @return string
     */
    private function generate_source_name( string $url, string $type ): string {
        $parsed = parse_url( $url );
        $host   = $parsed['host'] ?? '';
        $path   = $parsed['path'] ?? '';

        if ( in_array( $type, [ SourceType::GITHUB, SourceType::GITLAB, SourceType::GITEE ], true ) ) {
            // 提取 owner/repo
            $path = trim( $path, '/' );
            $path = preg_replace( '#\.git$#', '', $path );
            return $path ?: $host;
        }

        return $host ?: $url;
    }
}
