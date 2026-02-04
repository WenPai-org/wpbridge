<?php
/**
 * 主题更新器
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\UpdateSource\Handlers\UpdateInfo;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 主题更新器类
 */
class ThemeUpdater {

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
     * 缓存键前缀
     *
     * @var string
     */
    const CACHE_PREFIX = 'wpbridge_theme_update_';

    /**
     * 构造函数
     *
     * @param Settings $settings 设置实例
     */
    public function __construct( Settings $settings ) {
        $this->settings       = $settings;
        $this->source_manager = new SourceManager( $settings );

        $this->init_hooks();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks(): void {
        // 主题更新检查
        add_filter( 'pre_set_site_transient_update_themes', [ $this, 'check_updates' ], 10, 1 );

        // 主题信息
        add_filter( 'themes_api', [ $this, 'theme_info' ], 10, 3 );
    }

    /**
     * 检查主题更新
     *
     * @param object $transient 更新 transient
     * @return object
     */
    public function check_updates( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) ) {
            $transient = new \stdClass();
        }

        if ( ! isset( $transient->response ) ) {
            $transient->response = [];
        }

        if ( ! isset( $transient->no_update ) ) {
            $transient->no_update = [];
        }

        // 获取已安装的主题
        $themes = wp_get_themes();

        // 获取启用的更新源
        $sources = $this->source_manager->get_enabled_sorted();

        if ( empty( $sources ) ) {
            return $transient;
        }

        foreach ( $themes as $slug => $theme ) {
            // 检查是否有匹配的更新源
            $matching_sources = $this->source_manager->get_by_slug( $slug, 'theme' );

            // 如果没有特定 slug 的源，使用通用源
            if ( empty( $matching_sources ) ) {
                $matching_sources = array_filter( $sources, function( SourceModel $source ) {
                    return empty( $source->slug ) && $source->item_type === 'theme';
                } );
            }

            if ( empty( $matching_sources ) ) {
                continue;
            }

            $version = $theme->get( 'Version' );

            // 尝试从缓存获取（使用 md5 哈希防止缓存污染）
            $cache_key = self::CACHE_PREFIX . md5( $slug . get_site_url() );
            $cached    = get_transient( $cache_key );

            if ( false !== $cached ) {
                if ( ! empty( $cached['update'] ) ) {
                    $transient->response[ $slug ] = $cached['update'];
                } else {
                    $transient->no_update[ $slug ] = [
                        'theme'       => $slug,
                        'new_version' => $version,
                    ];
                }
                continue;
            }

            // 检查更新
            $update_info = $this->check_theme_update( $slug, $version, $matching_sources );

            if ( null !== $update_info ) {
                $update_data = [
                    'theme'       => $slug,
                    'new_version' => $update_info->version,
                    'url'         => $update_info->details_url,
                    'package'     => $update_info->download_url,
                    'requires'    => $update_info->requires,
                    'requires_php' => $update_info->requires_php,
                ];

                $transient->response[ $slug ] = $update_data;

                // 缓存结果
                set_transient( $cache_key, [
                    'update' => $update_data,
                ], $this->settings->get_cache_ttl() );

                Logger::info( '主题更新可用', [
                    'theme'   => $slug,
                    'current' => $version,
                    'new'     => $update_info->version,
                ] );
            } else {
                $transient->no_update[ $slug ] = [
                    'theme'       => $slug,
                    'new_version' => $version,
                ];

                // 缓存无更新结果
                set_transient( $cache_key, [
                    'update' => null,
                ], $this->settings->get_cache_ttl() );
            }
        }

        return $transient;
    }

    /**
     * 检查单个主题更新
     *
     * @param string        $slug    主题 slug
     * @param string        $version 当前版本
     * @param SourceModel[] $sources 更新源列表
     * @return UpdateInfo|null
     */
    private function check_theme_update( string $slug, string $version, array $sources ): ?UpdateInfo {
        foreach ( $sources as $source ) {
            $handler = $source->get_handler();

            if ( null === $handler ) {
                Logger::warning( '无法获取处理器', [
                    'source' => $source->id,
                    'type'   => $source->type,
                ] );
                continue;
            }

            try {
                $update_info = $handler->check_update( $slug, $version );

                if ( null !== $update_info ) {
                    return $update_info;
                }
            } catch ( \Exception $e ) {
                Logger::error( '检查主题更新时发生错误', [
                    'source' => $source->id,
                    'slug'   => $slug,
                    'error'  => $e->getMessage(),
                ] );

                // 继续尝试下一个源
                continue;
            }
        }

        return null;
    }

    /**
     * 获取主题信息
     *
     * @param false|object|array $result 结果
     * @param string             $action 动作
     * @param object             $args   参数
     * @return false|object|array
     */
    public function theme_info( $result, $action, $args ) {
        if ( 'theme_information' !== $action ) {
            return $result;
        }

        $slug = $args->slug ?? '';

        if ( empty( $slug ) ) {
            return $result;
        }

        // 检查是否有匹配的更新源
        $sources = $this->source_manager->get_by_slug( $slug, 'theme' );

        if ( empty( $sources ) ) {
            return $result;
        }

        // 获取第一个匹配的源
        $source  = reset( $sources );
        $handler = $source->get_handler();

        if ( null === $handler ) {
            return $result;
        }

        $info = $handler->get_info( $slug );

        if ( null === $info ) {
            return $result;
        }

        // 转换为 themes_api 响应格式
        return (object) [
            'name'          => $info['name'] ?? $slug,
            'slug'          => $slug,
            'version'       => $info['version'] ?? '',
            'download_link' => $info['download_url'] ?? $info['package'] ?? '',
            'requires'      => $info['requires'] ?? '',
            'requires_php'  => $info['requires_php'] ?? '',
            'last_updated'  => $info['last_updated'] ?? '',
            'sections'      => $info['sections'] ?? [],
            'screenshot_url' => $info['screenshot_url'] ?? '',
        ];
    }

    /**
     * 清除主题更新缓存
     *
     * @param string|null $slug 主题 slug，为空则清除所有
     */
    public function clear_cache( ?string $slug = null ): void {
        if ( null !== $slug ) {
            delete_transient( self::CACHE_PREFIX . md5( $slug . get_site_url() ) );
        } else {
            global $wpdb;
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%'
                )
            );
        }

        // 清除 WordPress 更新缓存
        delete_site_transient( 'update_themes' );
    }
}
