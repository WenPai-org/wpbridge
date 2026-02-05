<?php
/**
 * 商业插件检测器
 *
 * 自动检测插件是否为商业插件，支持：
 * - P1: 远程配置 + 已知商业插件列表
 * - P2: 用户手动标记（优先级最高）
 * - P3: 智能检测（代码分析）
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
 * CommercialDetector 类
 */
class CommercialDetector {

    /**
     * 插件类型常量
     */
    const TYPE_FREE       = 'free';
    const TYPE_COMMERCIAL = 'commercial';
    const TYPE_PRIVATE    = 'private';
    const TYPE_UNKNOWN    = 'unknown';

    /**
     * 缓存选项名（永久存储）
     */
    const CACHE_OPTION = 'wpbridge_plugin_type_cache';

    /**
     * 远程配置版本选项名
     */
    const CONFIG_VERSION_OPTION = 'wpbridge_remote_config_version';

    /**
     * 单例实例
     *
     * @var CommercialDetector|null
     */
    private static $instance = null;

    /**
     * 用户标记缓存
     *
     * @var array
     */
    private $user_marks = array();

    /**
     * 检测结果缓存（永久存储）
     *
     * @var array
     */
    private $detection_cache = array();

    /**
     * 远程配置实例
     *
     * @var RemoteConfig|null
     */
    private $remote_config = null;

    /**
     * 获取单例实例
     *
     * @return CommercialDetector
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
        $this->load_user_marks();
        $this->remote_config = RemoteConfig::get_instance();
        $this->load_detection_cache();
    }

    /**
     * 加载用户手动标记
     */
    private function load_user_marks() {
        $this->user_marks = get_option( 'wpbridge_plugin_types', array() );
    }

    /**
     * 加载检测结果缓存（永久存储）
     * 如果远程配置版本变化，自动清除缓存
     */
    private function load_detection_cache() {
        $cached = get_option( self::CACHE_OPTION, array() );
        $this->detection_cache = is_array( $cached ) ? $cached : array();

        // 检查远程配置版本是否变化
        $current_version = $this->remote_config->get_version();
        $cached_version  = get_option( self::CONFIG_VERSION_OPTION, '' );

        if ( $current_version !== $cached_version ) {
            // 远程配置更新了，清除缓存
            $this->detection_cache = array();
            update_option( self::CONFIG_VERSION_OPTION, $current_version );
        }
    }

    /**
     * 保存检测结果缓存（永久存储）
     */
    private function save_detection_cache() {
        update_option( self::CACHE_OPTION, $this->detection_cache, false );
    }

    /**
     * 检测插件类型
     *
     * @param string $plugin_slug 插件 slug
     * @param string $plugin_file 插件文件路径（可选）
     * @param bool   $skip_api    是否跳过 API 检查（默认 true）
     * @param bool   $use_cache   是否使用缓存（默认 true）
     * @return array 包含 type 和 source 的数组
     */
    public function detect( $plugin_slug, $plugin_file = '', $skip_api = true, $use_cache = true ) {
        if ( empty( $plugin_slug ) ) {
            return array(
                'type'   => self::TYPE_UNKNOWN,
                'source' => 'none',
            );
        }

        // P2: 用户手动标记优先（不缓存，实时读取）
        if ( isset( $this->user_marks[ $plugin_slug ] ) ) {
            return array(
                'type'   => $this->user_marks[ $plugin_slug ],
                'source' => 'manual',
            );
        }

        // 检查缓存
        if ( $use_cache && isset( $this->detection_cache[ $plugin_slug ] ) ) {
            return $this->detection_cache[ $plugin_slug ];
        }

        // 执行检测
        $result = $this->do_detect( $plugin_slug, $plugin_file, $skip_api );

        // 保存到缓存
        $this->detection_cache[ $plugin_slug ] = $result;

        return $result;
    }

    /**
     * 执行实际检测逻辑
     *
     * @param string $plugin_slug 插件 slug
     * @param string $plugin_file 插件文件路径
     * @param bool   $skip_api    是否跳过 API 检查
     * @return array
     */
    private function do_detect( $plugin_slug, $plugin_file, $skip_api ) {
        // P1: 远程配置的商业插件列表
        $commercial_plugins = $this->remote_config->get_commercial_plugins();
        if ( in_array( $plugin_slug, $commercial_plugins, true ) ) {
            return array(
                'type'   => self::TYPE_COMMERCIAL,
                'source' => 'remote_list',
            );
        }

        // 检查插件名称模式
        if ( $this->has_commercial_pattern( $plugin_slug ) ) {
            return array(
                'type'   => self::TYPE_COMMERCIAL,
                'source' => 'pattern',
            );
        }

        // WordPress.org API 检查（可选）
        if ( ! $skip_api ) {
            $wporg_result = $this->check_wordpress_org( $plugin_slug );
            if ( $wporg_result !== null ) {
                return array(
                    'type'   => $wporg_result ? self::TYPE_FREE : self::TYPE_UNKNOWN,
                    'source' => 'wporg_api',
                );
            }
        }

        return array(
            'type'   => self::TYPE_UNKNOWN,
            'source' => 'none',
        );
    }

    /**
     * P3: 深度扫描检测（智能检测）
     *
     * @param string $plugin_slug 插件 slug
     * @param string $plugin_file 插件文件路径
     * @return array 包含 type, source, score, reasons 的数组
     */
    public function deep_scan( $plugin_slug, $plugin_file ) {
        $score   = 0;
        $reasons = array();

        if ( empty( $plugin_file ) ) {
            return array(
                'type'    => self::TYPE_UNKNOWN,
                'source'  => 'deep_scan',
                'score'   => 0,
                'reasons' => array( 'no_file' ),
            );
        }

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );

        // 1. 检测 License 关键词
        $license_result = $this->check_license_code( $plugin_dir );
        if ( $license_result > 0 ) {
            $score += $license_result;
            $reasons[] = 'license_code';
        }

        // 2. 检测商业框架
        $framework_result = $this->check_commercial_frameworks( $plugin_dir );
        if ( $framework_result > 0 ) {
            $score += $framework_result;
            $reasons[] = 'commercial_framework';
        }

        // 3. 检测插件头部信息
        $header_result = $this->check_plugin_headers( $plugin_file );
        if ( $header_result > 0 ) {
            $score += $header_result;
            $reasons[] = 'commercial_domain';
        }

        // 4. 检测自定义更新机制
        $updater_result = $this->check_custom_updater( $plugin_dir );
        if ( $updater_result > 0 ) {
            $score += $updater_result;
            $reasons[] = 'custom_updater';
        }

        // 分数 >= 2 判定为商业插件
        $type = $score >= 2 ? self::TYPE_COMMERCIAL : self::TYPE_UNKNOWN;

        return array(
            'type'    => $type,
            'source'  => 'deep_scan',
            'score'   => $score,
            'reasons' => $reasons,
        );
    }

    /**
     * 检测 License 相关代码
     *
     * @param string $plugin_dir 插件目录
     * @return int 分数
     */
    private function check_license_code( $plugin_dir ) {
        $keywords = $this->remote_config->get_license_keywords();
        if ( empty( $keywords ) ) {
            $keywords = array(
                'license_key',
                'license_status',
                'activate_license',
                'deactivate_license',
            );
        }

        $files = $this->get_php_files( $plugin_dir, 2 );

        foreach ( $files as $file ) {
            $content = @file_get_contents( $file );
            if ( $content === false ) {
                continue;
            }

            foreach ( $keywords as $keyword ) {
                if ( stripos( $content, $keyword ) !== false ) {
                    return 1;
                }
            }
        }

        return 0;
    }

    /**
     * 检测商业插件框架
     *
     * @param string $plugin_dir 插件目录
     * @return int 分数
     */
    private function check_commercial_frameworks( $plugin_dir ) {
        $frameworks = $this->remote_config->get_commercial_frameworks();
        if ( empty( $frameworks ) ) {
            $frameworks = array(
                'EDD_SL_Plugin_Updater',
                'Freemius',
                'WC_AM_Client',
            );
        }

        $files = $this->get_php_files( $plugin_dir, 2 );

        foreach ( $files as $file ) {
            $content = @file_get_contents( $file );
            if ( $content === false ) {
                continue;
            }

            foreach ( $frameworks as $framework ) {
                if ( strpos( $content, $framework ) !== false ) {
                    return 2;
                }
            }
        }

        return 0;
    }

    /**
     * 检测插件头部信息
     *
     * @param string $plugin_file 插件文件
     * @return int 分数
     */
    private function check_plugin_headers( $plugin_file ) {
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if ( ! file_exists( $plugin_path ) ) {
            return 0;
        }

        $headers = get_plugin_data( $plugin_path, false, false );
        $score   = 0;

        // 检查 Plugin URI 是否为商业域名
        $commercial_domains = $this->remote_config->get_commercial_domains();
        if ( ! empty( $headers['PluginURI'] ) && ! empty( $commercial_domains ) ) {
            foreach ( $commercial_domains as $domain ) {
                if ( strpos( $headers['PluginURI'], $domain ) !== false ) {
                    $score += 2;
                    break;
                }
            }
        }

        // 检查名称是否包含 Pro/Premium
        if ( ! empty( $headers['Name'] ) ) {
            if ( preg_match( '/(pro|premium|elite|business|agency)$/i', $headers['Name'] ) ) {
                $score += 1;
            }
        }

        return $score;
    }

    /**
     * 检测自定义更新机制
     *
     * @param string $plugin_dir 插件目录
     * @return int 分数
     */
    private function check_custom_updater( $plugin_dir ) {
        $update_hooks = array(
            'pre_set_site_transient_update_plugins',
            'plugins_api',
        );

        $files = $this->get_php_files( $plugin_dir, 2 );

        foreach ( $files as $file ) {
            $content = @file_get_contents( $file );
            if ( $content === false ) {
                continue;
            }

            foreach ( $update_hooks as $hook ) {
                if ( strpos( $content, $hook ) !== false ) {
                    return 1;
                }
            }
        }

        return 0;
    }

    /**
     * 获取目录下的 PHP 文件
     *
     * @param string $dir   目录
     * @param int    $depth 深度
     * @return array
     */
    private function get_php_files( $dir, $depth = 1 ) {
        $files = array();

        if ( ! is_dir( $dir ) ) {
            return $files;
        }

        // 主目录 PHP 文件
        $main_files = glob( $dir . '/*.php' );
        if ( $main_files ) {
            $files = array_merge( $files, $main_files );
        }

        // 子目录（如果深度允许）
        if ( $depth > 1 ) {
            $subdirs = array( 'includes', 'inc', 'src', 'lib', 'admin' );
            foreach ( $subdirs as $subdir ) {
                $subdir_path = $dir . '/' . $subdir;
                if ( is_dir( $subdir_path ) ) {
                    $sub_files = glob( $subdir_path . '/*.php' );
                    if ( $sub_files ) {
                        $files = array_merge( $files, $sub_files );
                    }
                }
            }
        }

        // 限制文件数量，避免性能问题
        return array_slice( $files, 0, 20 );
    }

    /**
     * 检查插件名称是否有商业模式
     *
     * @param string $plugin_slug 插件 slug
     * @return bool
     */
    private function has_commercial_pattern( $plugin_slug ) {
        $patterns = array(
            '-pro$',
            '-premium$',
            '-elite$',
            '-business$',
            '-agency$',
            '-developer$',
            '-enterprise$',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( '/' . $pattern . '/i', $plugin_slug ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查插件是否在 WordPress.org 上存在
     *
     * @param string $plugin_slug 插件 slug
     * @return bool|null
     */
    private function check_wordpress_org( $plugin_slug ) {
        $cache_key = 'wpbridge_wporg_' . md5( $plugin_slug );
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return $cached === 'yes';
        }

        $url      = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=' . urlencode( $plugin_slug );
        $response = wp_remote_get( $url, array( 'timeout' => 5 ) );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code === 200 && ! empty( $body ) ) {
            $data = json_decode( $body, true );
            if ( isset( $data['slug'] ) ) {
                set_transient( $cache_key, 'yes', DAY_IN_SECONDS );
                return true;
            }
        }

        set_transient( $cache_key, 'no', DAY_IN_SECONDS );
        return false;
    }

    /**
     * 设置用户手动标记
     *
     * @param string $plugin_slug 插件 slug
     * @param string $type        插件类型
     * @return bool
     */
    public function set_user_mark( $plugin_slug, $type ) {
        $valid_types = array(
            self::TYPE_FREE,
            self::TYPE_COMMERCIAL,
            self::TYPE_PRIVATE,
            self::TYPE_UNKNOWN,
        );

        if ( ! in_array( $type, $valid_types, true ) ) {
            return false;
        }

        if ( $type === self::TYPE_UNKNOWN ) {
            unset( $this->user_marks[ $plugin_slug ] );
        } else {
            $this->user_marks[ $plugin_slug ] = $type;
        }

        return update_option( 'wpbridge_plugin_types', $this->user_marks );
    }

    /**
     * 获取用户手动标记
     *
     * @param string $plugin_slug 插件 slug
     * @return string|null
     */
    public function get_user_mark( $plugin_slug ) {
        return isset( $this->user_marks[ $plugin_slug ] ) ? $this->user_marks[ $plugin_slug ] : null;
    }

    /**
     * 清除用户手动标记
     *
     * @param string $plugin_slug 插件 slug
     * @return bool
     */
    public function clear_user_mark( $plugin_slug ) {
        return $this->set_user_mark( $plugin_slug, self::TYPE_UNKNOWN );
    }

    /**
     * 获取类型标签
     *
     * @param string $type 插件类型
     * @return array
     */
    public static function get_type_label( $type ) {
        $labels = array(
            self::TYPE_FREE => array(
                'label' => __( '免费', 'wpbridge' ),
                'color' => 'success',
                'icon'  => 'dashicons-wordpress',
            ),
            self::TYPE_COMMERCIAL => array(
                'label' => __( '商业', 'wpbridge' ),
                'color' => 'warning',
                'icon'  => 'dashicons-awards',
            ),
            self::TYPE_PRIVATE => array(
                'label' => __( '私有', 'wpbridge' ),
                'color' => 'info',
                'icon'  => 'dashicons-lock',
            ),
            self::TYPE_UNKNOWN => array(
                'label' => __( '第三方', 'wpbridge' ),
                'color' => 'gray',
                'icon'  => 'dashicons-admin-plugins',
            ),
        );

        return isset( $labels[ $type ] ) ? $labels[ $type ] : $labels[ self::TYPE_UNKNOWN ];
    }

    /**
     * 获取远程配置实例
     *
     * @return RemoteConfig
     */
    public function get_remote_config() {
        return $this->remote_config;
    }

    /**
     * 批量检测插件类型
     *
     * @param array $plugins 插件列表
     * @param bool  $use_cache 是否使用缓存
     * @return array
     */
    public function detect_batch( $plugins, $use_cache = true ) {
        $results = array();
        foreach ( $plugins as $slug => $file ) {
            $results[ $slug ] = $this->detect( $slug, $file, true, $use_cache );
        }
        // 批量检测后保存缓存
        $this->save_detection_cache();
        return $results;
    }

    /**
     * 清除检测缓存
     *
     * @return bool
     */
    public function clear_cache() {
        $this->detection_cache = array();
        delete_option( self::CONFIG_VERSION_OPTION );
        return delete_option( self::CACHE_OPTION );
    }

    /**
     * 重新检测所有插件（同步方式，已废弃）
     *
     * @deprecated 使用 prepare_refresh() + refresh_batch() 代替
     * @return array 检测结果
     */
    public function refresh_all() {
        // 清除缓存
        $this->clear_cache();

        // 刷新远程配置
        $this->remote_config->refresh();

        // 更新配置版本
        update_option( self::CONFIG_VERSION_OPTION, $this->remote_config->get_version() );

        // 获取所有已安装插件
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();

        // 重新检测
        $results = array();
        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $plugin_slug = dirname( $plugin_file );
            if ( $plugin_slug === '.' ) {
                $plugin_slug = basename( $plugin_file, '.php' );
            }
            $results[ $plugin_slug ] = $this->detect( $plugin_slug, $plugin_file, false, false );
        }

        // 保存缓存
        $this->save_detection_cache();

        return $results;
    }

    /**
     * 准备刷新检测（清除缓存，返回插件列表）
     *
     * @return array 包含 plugins 列表和 total 数量
     */
    public function prepare_refresh() {
        // 清除缓存
        $this->clear_cache();

        // 刷新远程配置
        $this->remote_config->refresh();

        // 更新配置版本
        update_option( self::CONFIG_VERSION_OPTION, $this->remote_config->get_version() );

        // 获取所有已安装插件
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();

        // 构建插件列表
        $plugins = array();
        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $plugin_slug = dirname( $plugin_file );
            if ( $plugin_slug === '.' ) {
                $plugin_slug = basename( $plugin_file, '.php' );
            }
            $plugins[] = array(
                'slug' => $plugin_slug,
                'file' => $plugin_file,
                'name' => $plugin_data['Name'],
            );
        }

        return array(
            'plugins' => $plugins,
            'total'   => count( $plugins ),
        );
    }

    /**
     * 批量检测插件（异步方式）
     *
     * @param array $plugins 插件列表，每项包含 slug 和 file
     * @return array 检测结果
     */
    public function refresh_batch( $plugins ) {
        $results = array();

        foreach ( $plugins as $plugin ) {
            $slug = $plugin['slug'];
            $file = $plugin['file'];
            $results[ $slug ] = $this->detect( $slug, $file, false, false );
        }

        // 保存缓存
        $this->save_detection_cache();

        return $results;
    }

    /**
     * 获取缓存统计信息
     *
     * @return array
     */
    public function get_cache_stats() {
        return array(
            'count'          => count( $this->detection_cache ),
            'storage'        => 'wp_options (permanent)',
            'config_version' => get_option( self::CONFIG_VERSION_OPTION, 'unknown' ),
        );
    }
}
