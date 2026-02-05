<?php
/**
 * 备份管理器
 *
 * @package WPBridge
 */

namespace WPBridge\Core;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 备份管理器类
 */
class BackupManager {

    /**
     * 选项名称
     */
    const OPTION_NAME = 'wpbridge_backups';

    /**
     * 备份目录名
     */
    const BACKUP_DIR = 'wpbridge-backups';

    /**
     * 最大保留备份数
     */
    const MAX_BACKUPS = 5;

    /**
     * 单例实例
     *
     * @var BackupManager|null
     */
    private static ?BackupManager $instance = null;

    /**
     * 备份记录缓存
     *
     * @var array|null
     */
    private ?array $backups = null;

    /**
     * 获取单例实例
     *
     * @return BackupManager
     */
    public static function get_instance(): BackupManager {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 私有构造函数
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks(): void {
        // 在更新前创建备份
        add_filter( 'upgrader_pre_install', [ $this, 'pre_install_backup' ], 10, 2 );
    }

    /**
     * 获取备份目录路径
     *
     * @return string
     */
    public function get_backup_dir(): string {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . self::BACKUP_DIR;
    }

    /**
     * 确保备份目录存在
     *
     * @return bool
     */
    private function ensure_backup_dir(): bool {
        $dir = $this->get_backup_dir();

        if ( ! file_exists( $dir ) ) {
            if ( ! wp_mkdir_p( $dir ) ) {
                return false;
            }

            // 创建 .htaccess 防止直接访问
            $htaccess = $dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, "Deny from all\n" );
            }

            // 创建 index.php
            $index = $dir . '/index.php';
            if ( ! file_exists( $index ) ) {
                file_put_contents( $index, "<?php\n// Silence is golden.\n" );
            }
        }

        return true;
    }

    /**
     * 获取所有备份记录
     *
     * @return array
     */
    public function get_all(): array {
        if ( null === $this->backups ) {
            $this->backups = get_option( self::OPTION_NAME, [] );
            if ( ! is_array( $this->backups ) ) {
                $this->backups = [];
            }
        }
        return $this->backups;
    }

    /**
     * 获取项目的备份列表
     *
     * @param string $item_key 项目键
     * @return array
     */
    public function get_item_backups( string $item_key ): array {
        $backups = $this->get_all();
        return $backups[ $item_key ] ?? [];
    }

    /**
     * 更新前创建备份
     *
     * @param bool|WP_Error $response 响应
     * @param array         $hook_extra 额外参数
     * @return bool|WP_Error
     */
    public function pre_install_backup( $response, $hook_extra ) {
        // 检查是否启用了备份
        $settings = new Settings();
        if ( ! $settings->get( 'backup_enabled', true ) ) {
            return $response;
        }

        // 确定项目类型和路径
        if ( ! empty( $hook_extra['plugin'] ) ) {
            $item_key = 'plugin:' . $hook_extra['plugin'];
            $source_path = WP_PLUGIN_DIR . '/' . dirname( $hook_extra['plugin'] );

            // 单文件插件
            if ( dirname( $hook_extra['plugin'] ) === '.' ) {
                $source_path = WP_PLUGIN_DIR . '/' . $hook_extra['plugin'];
            }
        } elseif ( ! empty( $hook_extra['theme'] ) ) {
            $item_key = 'theme:' . $hook_extra['theme'];
            $source_path = get_theme_root() . '/' . $hook_extra['theme'];
        } else {
            return $response;
        }

        // 创建备份
        $this->create_backup( $item_key, $source_path );

        return $response;
    }

    /**
     * 创建备份
     *
     * @param string $item_key    项目键
     * @param string $source_path 源路径
     * @return array|false 备份信息或失败
     */
    public function create_backup( string $item_key, string $source_path ) {
        if ( ! file_exists( $source_path ) ) {
            Logger::warning( "Backup failed: source not found - {$source_path}" );
            return false;
        }

        if ( ! $this->ensure_backup_dir() ) {
            Logger::error( 'Backup failed: cannot create backup directory' );
            return false;
        }

        // 获取当前版本
        $version = $this->get_item_version( $item_key, $source_path );

        // 生成备份文件名
        $backup_id = wp_generate_uuid4();
        $backup_filename = sanitize_file_name( str_replace( ':', '-', $item_key ) ) . '-' . $version . '-' . gmdate( 'Ymd-His' ) . '.zip';
        $backup_path = $this->get_backup_dir() . '/' . $backup_filename;

        // 创建 ZIP 备份
        if ( ! $this->create_zip( $source_path, $backup_path ) ) {
            Logger::error( "Backup failed: cannot create zip - {$backup_path}" );
            return false;
        }

        // 记录备份信息
        $backup_info = [
            'id'         => $backup_id,
            'filename'   => $backup_filename,
            'version'    => $version,
            'size'       => filesize( $backup_path ),
            'created_at' => current_time( 'mysql' ),
        ];

        $this->add_backup_record( $item_key, $backup_info );

        // 清理旧备份
        $this->cleanup_old_backups( $item_key );

        Logger::info( "Backup created: {$item_key} v{$version}" );

        return $backup_info;
    }

    /**
     * 创建 ZIP 文件
     *
     * @param string $source_path 源路径
     * @param string $zip_path    ZIP 路径
     * @return bool
     */
    private function create_zip( string $source_path, string $zip_path ): bool {
        if ( ! class_exists( 'ZipArchive' ) ) {
            Logger::error( 'ZipArchive class not available' );
            return false;
        }

        $zip = new \ZipArchive();

        if ( $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
            return false;
        }

        if ( is_file( $source_path ) ) {
            // 单文件
            $zip->addFile( $source_path, basename( $source_path ) );
        } else {
            // 目录
            $this->add_dir_to_zip( $zip, $source_path, basename( $source_path ) );
        }

        return $zip->close();
    }

    /**
     * 递归添加目录到 ZIP
     *
     * @param \ZipArchive $zip     ZIP 对象
     * @param string      $dir     目录路径
     * @param string      $zip_dir ZIP 内目录名
     */
    private function add_dir_to_zip( \ZipArchive $zip, string $dir, string $zip_dir ): void {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $files as $file ) {
            $file_path = $file->getRealPath();
            $relative_path = $zip_dir . '/' . substr( $file_path, strlen( $dir ) + 1 );

            if ( $file->isDir() ) {
                $zip->addEmptyDir( $relative_path );
            } else {
                $zip->addFile( $file_path, $relative_path );
            }
        }
    }

    /**
     * 获取项目版本
     *
     * @param string $item_key    项目键
     * @param string $source_path 源路径
     * @return string
     */
    private function get_item_version( string $item_key, string $source_path ): string {
        if ( strpos( $item_key, 'plugin:' ) === 0 ) {
            $plugin_file = substr( $item_key, 7 );
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugin_path = is_file( $source_path ) ? $source_path : $source_path . '/' . basename( $plugin_file );
            if ( file_exists( $plugin_path ) ) {
                $data = get_plugin_data( $plugin_path, false, false );
                return $data['Version'] ?? '0.0.0';
            }
        } elseif ( strpos( $item_key, 'theme:' ) === 0 ) {
            $theme_slug = substr( $item_key, 6 );
            $theme = wp_get_theme( $theme_slug );
            if ( $theme->exists() ) {
                return $theme->get( 'Version' );
            }
        }

        return '0.0.0';
    }

    /**
     * 添加备份记录
     *
     * @param string $item_key    项目键
     * @param array  $backup_info 备份信息
     */
    private function add_backup_record( string $item_key, array $backup_info ): void {
        $backups = $this->get_all();

        if ( ! isset( $backups[ $item_key ] ) ) {
            $backups[ $item_key ] = [];
        }

        array_unshift( $backups[ $item_key ], $backup_info );

        $this->backups = $backups;
        update_option( self::OPTION_NAME, $backups );
    }

    /**
     * 清理旧备份
     *
     * @param string $item_key 项目键
     */
    private function cleanup_old_backups( string $item_key ): void {
        $backups = $this->get_all();

        if ( ! isset( $backups[ $item_key ] ) ) {
            return;
        }

        $item_backups = $backups[ $item_key ];

        while ( count( $item_backups ) > self::MAX_BACKUPS ) {
            $old_backup = array_pop( $item_backups );

            // 删除文件
            $file_path = $this->get_backup_dir() . '/' . $old_backup['filename'];
            if ( file_exists( $file_path ) ) {
                unlink( $file_path );
            }
        }

        $backups[ $item_key ] = $item_backups;
        $this->backups = $backups;
        update_option( self::OPTION_NAME, $backups );
    }

    /**
     * 回滚到指定备份
     *
     * @param string $item_key  项目键
     * @param string $backup_id 备份 ID
     * @return bool|WP_Error
     */
    public function rollback( string $item_key, string $backup_id ) {
        $item_backups = $this->get_item_backups( $item_key );

        // 查找备份
        $backup = null;
        foreach ( $item_backups as $b ) {
            if ( $b['id'] === $backup_id ) {
                $backup = $b;
                break;
            }
        }

        if ( ! $backup ) {
            return new \WP_Error( 'backup_not_found', __( '备份不存在', 'wpbridge' ) );
        }

        $backup_path = $this->get_backup_dir() . '/' . $backup['filename'];

        if ( ! file_exists( $backup_path ) ) {
            return new \WP_Error( 'backup_file_missing', __( '备份文件不存在', 'wpbridge' ) );
        }

        // 确定目标路径
        if ( strpos( $item_key, 'plugin:' ) === 0 ) {
            $plugin_file = substr( $item_key, 7 );
            $target_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );

            if ( dirname( $plugin_file ) === '.' ) {
                // 单文件插件
                $target_dir = WP_PLUGIN_DIR;
            }
        } elseif ( strpos( $item_key, 'theme:' ) === 0 ) {
            $theme_slug = substr( $item_key, 6 );
            $target_dir = get_theme_root() . '/' . $theme_slug;
        } else {
            return new \WP_Error( 'invalid_item', __( '无效的项目', 'wpbridge' ) );
        }

        // 解压备份
        $result = $this->extract_zip( $backup_path, dirname( $target_dir ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        Logger::info( "Rollback completed: {$item_key} to v{$backup['version']}" );

        return true;
    }

    /**
     * 解压 ZIP 文件
     *
     * @param string $zip_path   ZIP 路径
     * @param string $target_dir 目标目录
     * @return bool|WP_Error
     */
    private function extract_zip( string $zip_path, string $target_dir ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new \WP_Error( 'no_zip', __( 'ZipArchive 不可用', 'wpbridge' ) );
        }

        $zip = new \ZipArchive();

        if ( $zip->open( $zip_path ) !== true ) {
            return new \WP_Error( 'zip_open_failed', __( '无法打开备份文件', 'wpbridge' ) );
        }

        $zip->extractTo( $target_dir );
        $zip->close();

        return true;
    }

    /**
     * 删除备份
     *
     * @param string $item_key  项目键
     * @param string $backup_id 备份 ID
     * @return bool
     */
    public function delete_backup( string $item_key, string $backup_id ): bool {
        $backups = $this->get_all();

        if ( ! isset( $backups[ $item_key ] ) ) {
            return false;
        }

        foreach ( $backups[ $item_key ] as $index => $backup ) {
            if ( $backup['id'] === $backup_id ) {
                // 删除文件
                $file_path = $this->get_backup_dir() . '/' . $backup['filename'];
                if ( file_exists( $file_path ) ) {
                    unlink( $file_path );
                }

                // 删除记录
                unset( $backups[ $item_key ][ $index ] );
                $backups[ $item_key ] = array_values( $backups[ $item_key ] );

                $this->backups = $backups;
                update_option( self::OPTION_NAME, $backups );

                return true;
            }
        }

        return false;
    }

    /**
     * 获取备份总大小
     *
     * @return int 字节数
     */
    public function get_total_size(): int {
        $total = 0;
        $backups = $this->get_all();

        foreach ( $backups as $item_backups ) {
            foreach ( $item_backups as $backup ) {
                $total += $backup['size'] ?? 0;
            }
        }

        return $total;
    }

    /**
     * 清除缓存
     */
    public function clear_cache(): void {
        $this->backups = null;
    }
}
