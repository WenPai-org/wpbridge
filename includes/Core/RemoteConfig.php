<?php
/**
 * 远程配置管理器
 *
 * 从远程服务器获取商业插件检测配置，支持：
 * - 定时自动更新
 * - 本地缓存
 * - 降级到内置配置
 *
 * @package WPBridge
 * @since 0.7.5
 */

namespace WPBridge\Core;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RemoteConfig 类
 */
class RemoteConfig {

    /**
     * 远程配置 URL
     */
    const CONFIG_URL = 'https://wpcy.com/api/bridge/commercial-config.json';

    /**
     * 缓存键名
     */
    const CACHE_KEY = 'wpbridge_remote_config';

    /**
     * 缓存时间（秒）- 默认 12 小时
     */
    const CACHE_TTL = 43200;

    /**
     * 单例实例
     *
     * @var RemoteConfig|null
     */
    private static $instance = null;

    /**
     * 配置数据
     *
     * @var array|null
     */
    private $config = null;

    /**
     * 获取单例实例
     *
     * @return RemoteConfig
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        $this->load_config();
    }

    /**
     * 加载配置（优先从缓存）
     */
    private function load_config() {
        // 尝试从缓存加载
        $cached = get_transient( self::CACHE_KEY );
        if ( $cached !== false ) {
            $this->config = $cached;
            return;
        }

        // 尝试从远程获取
        $remote = $this->fetch_remote_config();
        if ( $remote !== null ) {
            $this->config = $remote;
            set_transient( self::CACHE_KEY, $remote, self::CACHE_TTL );
            return;
        }

        // 降级到内置配置
        $this->config = $this->get_builtin_config();
    }

    /**
     * 从远程获取配置
     *
     * @return array|null
     */
    private function fetch_remote_config() {
        $response = wp_remote_get( self::CONFIG_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            Logger::warning( '远程配置获取失败', array(
                'error' => $response->get_error_message(),
            ) );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            Logger::warning( '远程配置响应异常', array(
                'code' => $code,
            ) );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            Logger::warning( '远程配置 JSON 解析失败', array(
                'error' => json_last_error_msg(),
            ) );
            return null;
        }

        // 验证配置结构
        if ( ! $this->validate_config( $data ) ) {
            Logger::warning( '远程配置结构无效' );
            return null;
        }

        Logger::info( '远程配置加载成功', array(
            'version' => $data['version'] ?? 'unknown',
        ) );

        return $data;
    }

    /**
     * 验证配置结构
     *
     * @param array $data 配置数据
     * @return bool
     */
    private function validate_config( $data ) {
        if ( ! is_array( $data ) ) {
            return false;
        }

        // 必须包含版本号
        if ( empty( $data['version'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * 获取内置配置（降级方案）
     *
     * @return array
     */
    private function get_builtin_config() {
        return array(
            'version'             => '1.0.0-builtin',
            'updated_at'          => '2026-02-04',
            'commercial_plugins'  => array(
                'elementor-pro',
                'wordpress-seo-premium',
                'gravityforms',
                'advanced-custom-fields-pro',
                'wp-rocket',
                'wpforms-pro',
                'memberpress',
                'learndash',
            ),
            'commercial_domains'  => array(
                'codecanyon.net',
                'themeforest.net',
                'elegantthemes.com',
            ),
            'license_keywords'    => array(
                'license_key',
                'license_status',
                'activate_license',
                'deactivate_license',
                'check_license',
            ),
            'commercial_frameworks' => array(
                'EDD_SL_Plugin_Updater',
                'Freemius',
                'WC_AM_Client',
                'Starter_Plugin_Updater',
            ),
        );
    }

    /**
     * 获取商业插件列表
     *
     * @return array
     */
    public function get_commercial_plugins() {
        return $this->config['commercial_plugins'] ?? array();
    }

    /**
     * 获取商业域名列表
     *
     * @return array
     */
    public function get_commercial_domains() {
        return $this->config['commercial_domains'] ?? array();
    }

    /**
     * 获取 License 关键词列表
     *
     * @return array
     */
    public function get_license_keywords() {
        return $this->config['license_keywords'] ?? array();
    }

    /**
     * 获取商业框架列表
     *
     * @return array
     */
    public function get_commercial_frameworks() {
        return $this->config['commercial_frameworks'] ?? array();
    }

    /**
     * 获取配置版本
     *
     * @return string
     */
    public function get_version() {
        return $this->config['version'] ?? 'unknown';
    }

    /**
     * 获取配置更新时间
     *
     * @return string
     */
    public function get_updated_at() {
        return $this->config['updated_at'] ?? 'unknown';
    }

    /**
     * 强制刷新配置
     *
     * @return bool
     */
    public function refresh() {
        delete_transient( self::CACHE_KEY );

        $remote = $this->fetch_remote_config();
        if ( $remote !== null ) {
            $this->config = $remote;
            set_transient( self::CACHE_KEY, $remote, self::CACHE_TTL );
            return true;
        }

        // 刷新失败，保持当前配置
        return false;
    }

    /**
     * 检查是否使用内置配置
     *
     * @return bool
     */
    public function is_builtin() {
        return strpos( $this->get_version(), 'builtin' ) !== false;
    }

    /**
     * 获取完整配置
     *
     * @return array
     */
    public function get_all() {
        return $this->config;
    }
}
