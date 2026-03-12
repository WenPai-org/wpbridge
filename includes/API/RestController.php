<?php
/**
 * REST API 控制器
 *
 * @package WPBridge
 */

namespace WPBridge\API;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\UpdateSource\SourceManager;
use WPBridge\Cache\CacheManager;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API 控制器类
 */
class RestController {

    /**
     * API 命名空间
     *
     * @var string
     */
    const NAMESPACE = 'bridge/v1';

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
     * 缓存管理器
     *
     * @var CacheManager
     */
    private CacheManager $cache;

    /**
     * 构造函数
     *
     * @param Settings $settings 设置实例
     */
    public function __construct( Settings $settings ) {
        $this->settings       = $settings;
        $this->source_manager = new SourceManager( $settings );
        $this->cache          = new CacheManager();

        $this->init_hooks();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * 注册路由
     */
    public function register_routes(): void {
        // 获取所有更新源
        register_rest_route( self::NAMESPACE, '/sources', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_sources' ],
            'permission_callback' => [ $this, 'check_api_permission' ],
        ] );

        // 获取单个更新源
        register_rest_route( self::NAMESPACE, '/sources/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_source' ],
            'permission_callback' => [ $this, 'check_api_permission' ],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // 检查更新源状态
        register_rest_route( self::NAMESPACE, '/check/(?P<source_id>[a-zA-Z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'check_source' ],
            'permission_callback' => [ $this, 'check_api_permission' ],
            'args'                => [
                'source_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // 获取插件信息
        register_rest_route( self::NAMESPACE, '/plugins/(?P<slug>[a-z0-9-]+)/info', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_plugin_info' ],
            'permission_callback' => [ $this, 'check_api_permission' ],
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ] );

        // 获取主题信息
        register_rest_route( self::NAMESPACE, '/themes/(?P<slug>[a-z0-9-]+)/info', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_theme_info' ],
            'permission_callback' => [ $this, 'check_api_permission' ],
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ] );

        // 菲码源库 Releases
        register_rest_route( self::NAMESPACE, '/wenpai-git/(?P<repo>[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+)/releases', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_wenpai_git_releases' ],
            'permission_callback' => [ $this, 'check_api_permission' ],
            'args'                => [
                'repo' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ( $param ) {
                        return preg_match( '/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_.-]+$/', $param );
                    },
                ],
            ],
        ] );

        // API 状态
        register_rest_route( self::NAMESPACE, '/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * 检查 API 权限
     *
     * @param \WP_REST_Request $request 请求对象
     * @return bool|\WP_Error
     */
    public function check_api_permission( \WP_REST_Request $request ) {
        $api_settings = $this->settings->get( 'api', [] );

        // 检查 API 是否启用
        if ( empty( $api_settings['enabled'] ) ) {
            return new \WP_Error(
                'api_disabled',
                __( 'API 未启用', 'wpbridge' ),
                [ 'status' => 403 ]
            );
        }

        // M3: 默认要求认证，除非显式关闭
        $require_auth = $api_settings['require_auth'] ?? true;
        if ( $require_auth ) {
            $api_key = $this->get_api_key_from_request( $request );

            if ( empty( $api_key ) ) {
                return new \WP_Error(
                    'missing_api_key',
                    __( '缺少 API Key', 'wpbridge' ),
                    [ 'status' => 401 ]
                );
            }

            if ( ! $this->validate_api_key( $api_key ) ) {
                return new \WP_Error(
                    'invalid_api_key',
                    __( '无效的 API Key', 'wpbridge' ),
                    [ 'status' => 401 ]
                );
            }
        }

        // 检查速率限制
        $rate_limit_result = $this->check_rate_limit( $request );
        if ( is_wp_error( $rate_limit_result ) ) {
            return $rate_limit_result;
        }

        return true;
    }

    /**
     * 从请求中获取 API Key
     *
     * @param \WP_REST_Request $request 请求对象
     * @return string
     */
    private function get_api_key_from_request( \WP_REST_Request $request ): string {
        // 优先从 Header 获取
        $auth_header = $request->get_header( 'X-WPBridge-API-Key' );
        if ( ! empty( $auth_header ) ) {
            return sanitize_text_field( $auth_header );
        }

        // 从 Authorization Bearer 获取
        $auth_header = $request->get_header( 'Authorization' );
        if ( ! empty( $auth_header ) && strpos( $auth_header, 'Bearer ' ) === 0 ) {
            return sanitize_text_field( substr( $auth_header, 7 ) );
        }

        // 从查询参数获取（不推荐，记录警告）
        $api_key = $request->get_param( 'api_key' );
        if ( ! empty( $api_key ) ) {
            Logger::warning( 'API Key 通过 URL 参数传递，建议使用 Header 方式', [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ] );
            return sanitize_text_field( $api_key );
        }

        return '';
    }

    /**
     * 验证 API Key
     *
     * @param string $api_key API Key
     * @return bool
     */
    private function validate_api_key( string $api_key ): bool {
        $api_settings = $this->settings->get( 'api', [] );
        $valid_keys   = $api_settings['keys'] ?? [];

        foreach ( $valid_keys as $key_data ) {
            // 使用 password_verify 验证哈希
            if ( isset( $key_data['key_hash'] ) && password_verify( $api_key, $key_data['key_hash'] ) ) {
                // 检查是否过期
                if ( ! empty( $key_data['expires_at'] ) ) {
                    if ( strtotime( $key_data['expires_at'] ) < time() ) {
                        return false;
                    }
                }

                // 记录使用（使用缓存批量更新）
                $this->record_api_key_usage( $key_data['id'] );

                return true;
            }
        }

        return false;
    }

    /**
     * 记录 API Key 使用
     *
     * @param string $key_id Key ID
     */
    private function record_api_key_usage( string $key_id ): void {
        // 使用缓存记录，避免频繁写入数据库
        $cache_key = 'api_usage_' . $key_id;
        $count     = $this->cache->get( $cache_key );

        if ( false === $count ) {
            $count = 0;
        }

        $count++;
        $this->cache->set( $cache_key, $count, 300 );

        // 每 50 次批量写入数据库
        if ( $count >= 50 ) {
            $this->flush_usage_to_db( $key_id, $count );
            $this->cache->set( $cache_key, 0, 300 );
        }
    }

    /**
     * 将使用统计写入数据库
     *
     * @param string $key_id Key ID
     * @param int    $count  使用次数
     */
    private function flush_usage_to_db( string $key_id, int $count ): void {
        $api_settings = $this->settings->get( 'api', [] );
        $keys         = $api_settings['keys'] ?? [];

        foreach ( $keys as $index => $key ) {
            if ( $key['id'] === $key_id ) {
                $keys[ $index ]['last_used']   = current_time( 'mysql' );
                $keys[ $index ]['usage_count'] = ( $key['usage_count'] ?? 0 ) + $count;
                break;
            }
        }

        $api_settings['keys'] = $keys;
        $this->settings->set( 'api', $api_settings );
    }

    /**
     * 检查速率限制
     *
     * @param \WP_REST_Request $request 请求对象
     * @return true|\WP_Error
     */
    private function check_rate_limit( \WP_REST_Request $request ) {
        $api_settings = $this->settings->get( 'api', [] );
        $rate_limit   = $api_settings['rate_limit'] ?? 60; // 默认每分钟 60 次

        if ( $rate_limit <= 0 ) {
            return true; // 无限制
        }

        // 获取客户端标识
        $client_id = $this->get_client_identifier( $request );
        $cache_key = 'rate_limit_' . md5( $client_id );

        $current = $this->cache->get( $cache_key );

        if ( false === $current ) {
            $this->cache->set( $cache_key, 1, 60 );
            return true;
        }

        if ( $current >= $rate_limit ) {
            return new \WP_Error(
                'rate_limit_exceeded',
                __( '请求过于频繁，请稍后再试', 'wpbridge' ),
                [
                    'status'           => 429,
                    'retry_after'      => 60,
                    'x-ratelimit-limit' => $rate_limit,
                    'x-ratelimit-remaining' => 0,
                ]
            );
        }

        $this->cache->set( $cache_key, $current + 1, 60 );

        return true;
    }

    /**
     * 获取客户端标识
     *
     * @param \WP_REST_Request $request 请求对象
     * @return string
     */
    private function get_client_identifier( \WP_REST_Request $request ): string {
        // 优先使用 API Key
        $api_key = $this->get_api_key_from_request( $request );
        if ( ! empty( $api_key ) ) {
            return 'key:' . md5( $api_key );
        }

        // 使用 IP 地址
        return 'ip:' . $this->get_client_ip( $request );
    }

    /**
     * 获取客户端 IP 地址
     *
     * @param \WP_REST_Request $request 请求对象
     * @return string
     */
    private function get_client_ip( \WP_REST_Request $request ): string {
        // 检查是否配置了可信代理
        $trusted_proxies = apply_filters( 'wpbridge_trusted_proxies', [] );

        if ( ! empty( $trusted_proxies ) ) {
            $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';

            // 只有当请求来自可信代理时才信任 X-Forwarded-For
            if ( in_array( $remote_addr, $trusted_proxies, true ) ) {
                $forwarded = $request->get_header( 'X-Forwarded-For' );
                if ( ! empty( $forwarded ) ) {
                    // 取第一个非代理 IP
                    $ips = array_map( 'trim', explode( ',', $forwarded ) );
                    return sanitize_text_field( $ips[0] );
                }
            }
        }

        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
    }

    /**
     * 获取所有更新源
     *
     * @param \WP_REST_Request $request 请求对象
     * @return \WP_REST_Response
     */
    public function get_sources( \WP_REST_Request $request ): \WP_REST_Response {
        $sources = $this->source_manager->get_enabled_sorted();

        $data = array_map( function ( $source ) {
            return [
                'id'        => $source->id,
                'name'      => $source->name,
                'type'      => $source->type,
                'item_type' => $source->item_type,
                'slug'      => $source->slug,
                'enabled'   => $source->enabled,
                'priority'  => $source->priority,
            ];
        }, $sources );

        return new \WP_REST_Response( [
            'success' => true,
            'data'    => array_values( $data ),
            'total'   => count( $data ),
        ] );
    }

    /**
     * 获取单个更新源
     *
     * @param \WP_REST_Request $request 请求对象
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_source( \WP_REST_Request $request ) {
        $id     = $request->get_param( 'id' );
        $source = $this->source_manager->get( $id );

        if ( null === $source ) {
            return new \WP_Error(
                'source_not_found',
                __( '更新源不存在', 'wpbridge' ),
                [ 'status' => 404 ]
            );
        }

        return new \WP_REST_Response( [
            'success' => true,
            'data'    => [
                'id'        => $source->id,
                'name'      => $source->name,
                'type'      => $source->type,
                'api_url'   => $source->api_url,
                'item_type' => $source->item_type,
                'slug'      => $source->slug,
                'enabled'   => $source->enabled,
                'priority'  => $source->priority,
            ],
        ] );
    }

    /**
     * 检查更新源状态
     *
     * @param \WP_REST_Request $request 请求对象
     * @return \WP_REST_Response|\WP_Error
     */
    public function check_source( \WP_REST_Request $request ) {
        $source_id = $request->get_param( 'source_id' );
        $source    = $this->source_manager->get( $source_id );

        if ( null === $source ) {
            return new \WP_Error(
                'source_not_found',
                __( '更新源不存在', 'wpbridge' ),
                [ 'status' => 404 ]
            );
        }

        $checker = new \WPBridge\Cache\HealthChecker();
        $status  = $checker->check( $source, true );

        return new \WP_REST_Response( [
            'success' => true,
            'data'    => [
                'source_id'     => $source_id,
                'status'        => $status->status,
                'response_time' => $status->response_time,
                'error'         => $status->error,
                'checked_at'    => current_time( 'c' ),
            ],
        ] );
    }

    /**
     * 获取插件信息
     *
     * @param \WP_REST_Request $request 请求对象
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_plugin_info( \WP_REST_Request $request ) {
        $slug = $request->get_param( 'slug' );

        // 从缓存获取
        $cache_key = 'plugin_info_' . $slug;
        $cached    = $this->cache->get( $cache_key );

        if ( false !== $cached ) {
            return new \WP_REST_Response( [
                'success' => true,
                'data'    => $cached,
                'cached'  => true,
            ] );
        }

        // 查找匹配的更新源
        $sources = $this->source_manager->get_enabled_sorted();
        $info    = null;

        foreach ( $sources as $source ) {
            if ( $source->item_type !== 'plugin' ) {
                continue;
            }

            if ( ! empty( $source->slug ) && $source->slug !== $slug ) {
                continue;
            }

            $handler = $source->get_handler();
            if ( null === $handler ) {
                continue;
            }

            try {
                $info = $handler->get_info( $slug );
                if ( null !== $info ) {
                    break;
                }
            } catch ( \Exception $e ) {
                Logger::debug( '获取插件信息失败', [
                    'slug'   => $slug,
                    'source' => $source->id,
                    'error'  => $e->getMessage(),
                ] );
            }
        }

        if ( null === $info ) {
            return new \WP_Error(
                'plugin_not_found',
                __( '未找到插件信息', 'wpbridge' ),
                [ 'status' => 404 ]
            );
        }

        // 缓存结果
        $this->cache->set( $cache_key, $info, $this->settings->get_cache_ttl() );

        return new \WP_REST_Response( [
            'success' => true,
            'data'    => $info,
            'cached'  => false,
        ] );
    }

    /**
     * 获取主题信息
     *
     * @param \WP_REST_Request $request 请求对象
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_theme_info( \WP_REST_Request $request ) {
        $slug = $request->get_param( 'slug' );

        // 从缓存获取
        $cache_key = 'theme_info_' . $slug;
        $cached    = $this->cache->get( $cache_key );

        if ( false !== $cached ) {
            return new \WP_REST_Response( [
                'success' => true,
                'data'    => $cached,
                'cached'  => true,
            ] );
        }

        // 查找匹配的更新源
        $sources = $this->source_manager->get_enabled_sorted();
        $info    = null;

        foreach ( $sources as $source ) {
            if ( $source->item_type !== 'theme' ) {
                continue;
            }

            if ( ! empty( $source->slug ) && $source->slug !== $slug ) {
                continue;
            }

            $handler = $source->get_handler();
            if ( null === $handler ) {
                continue;
            }

            try {
                $info = $handler->get_info( $slug );
                if ( null !== $info ) {
                    break;
                }
            } catch ( \Exception $e ) {
                Logger::debug( '获取主题信息失败', [
                    'slug'   => $slug,
                    'source' => $source->id,
                    'error'  => $e->getMessage(),
                ] );
            }
        }

        if ( null === $info ) {
            return new \WP_Error(
                'theme_not_found',
                __( '未找到主题信息', 'wpbridge' ),
                [ 'status' => 404 ]
            );
        }

        // 缓存结果
        $this->cache->set( $cache_key, $info, $this->settings->get_cache_ttl() );

        return new \WP_REST_Response( [
            'success' => true,
            'data'    => $info,
            'cached'  => false,
        ] );
    }

    /**
     * 获取菲码源库 Releases
     *
     * @param \WP_REST_Request $request 请求对象
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_wenpai_git_releases( \WP_REST_Request $request ) {
        $repo = $request->get_param( 'repo' );

        // 从缓存获取
        $cache_key = 'wenpai_git_' . md5( $repo );
        $cached    = $this->cache->get( $cache_key );

        if ( false !== $cached ) {
            return new \WP_REST_Response( [
                'success' => true,
                'data'    => $cached,
                'cached'  => true,
            ] );
        }

        // 调用菲码源库 API
        $api_url  = 'https://git.wenpai.org/api/v1/repos/' . $repo . '/releases';
        $response = wp_remote_get( $api_url, [
            'timeout' => $this->settings->get_request_timeout(),
            'headers' => [
                'Accept' => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error(
                'api_error',
                $response->get_error_message(),
                [ 'status' => 502 ]
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return new \WP_Error(
                'api_error',
                sprintf( __( '菲码源库 API 返回错误: %d', 'wpbridge' ), $status_code ),
                [ 'status' => $status_code ]
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new \WP_Error(
                'json_error',
                __( 'JSON 解析失败', 'wpbridge' ),
                [ 'status' => 500 ]
            );
        }

        // 格式化数据
        $releases = array_map( function ( $release ) {
            return [
                'id'           => $release['id'] ?? 0,
                'tag_name'     => $release['tag_name'] ?? '',
                'name'         => $release['name'] ?? '',
                'body'         => wp_kses_post( $release['body'] ?? '' ),
                'draft'        => $release['draft'] ?? false,
                'prerelease'   => $release['prerelease'] ?? false,
                'created_at'   => $release['created_at'] ?? '',
                'published_at' => $release['published_at'] ?? '',
                'assets'       => array_map( function ( $asset ) {
                    return [
                        'name'          => $asset['name'] ?? '',
                        'size'          => $asset['size'] ?? 0,
                        'download_url'  => $asset['browser_download_url'] ?? '',
                        'download_count' => $asset['download_count'] ?? 0,
                    ];
                }, $release['assets'] ?? [] ),
            ];
        }, $data );

        // 缓存结果
        $this->cache->set( $cache_key, $releases, $this->settings->get_cache_ttl() );

        return new \WP_REST_Response( [
            'success' => true,
            'data'    => $releases,
            'cached'  => false,
        ] );
    }

    /**
     * 获取 API 状态
     *
     * @param \WP_REST_Request $request 请求对象
     * @return \WP_REST_Response
     */
    public function get_status( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [
            'success' => true,
            'data'    => [
                'version'      => WPBRIDGE_VERSION,
                'api_version'  => 'v1',
                'endpoints'    => [
                    'sources'       => rest_url( self::NAMESPACE . '/sources' ),
                    'check'         => rest_url( self::NAMESPACE . '/check/{source_id}' ),
                    'plugins'       => rest_url( self::NAMESPACE . '/plugins/{slug}/info' ),
                    'themes'        => rest_url( self::NAMESPACE . '/themes/{slug}/info' ),
                    'wenpai_git'    => rest_url( self::NAMESPACE . '/wenpai-git/{repo}/releases' ),
                ],
                'timestamp'    => current_time( 'c' ),
            ],
        ] );
    }
}
