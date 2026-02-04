<?php
/**
 * 配置导入导出管理
 *
 * @package WPBridge
 */

namespace WPBridge\Core;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 配置管理类
 */
class ConfigManager {

    /**
     * 配置版本
     */
    const CONFIG_VERSION = '1.0';

    /**
     * 需要导出的选项
     *
     * @var array
     */
    private array $export_options = [
        'wpbridge_sources',
        'wpbridge_settings',
        'wpbridge_ai_settings',
        'wpbridge_source_groups',
        'wpbridge_item_sources',
        'wpbridge_defaults',
        'wpbridge_source_registry',
        'wpbridge_plugin_types',
    ];

    /**
     * 导出配置
     *
     * @param bool $include_secrets 是否包含敏感信息（API Key 等）
     * @return array
     */
    public function export( bool $include_secrets = false ): array {
        $config = [
            'version'    => self::CONFIG_VERSION,
            'plugin'     => WPBRIDGE_VERSION,
            'site_url'   => get_site_url(),
            'exported'   => current_time( 'mysql' ),
            'options'    => [],
        ];

        foreach ( $this->export_options as $option_name ) {
            $value = get_option( $option_name, null );

            if ( null !== $value ) {
                // 处理敏感信息
                if ( ! $include_secrets ) {
                    $value = $this->sanitize_secrets( $option_name, $value );
                }
                $config['options'][ $option_name ] = $value;
            }
        }

        return $config;
    }

    /**
     * 导出为 JSON 字符串
     *
     * @param bool $include_secrets 是否包含敏感信息
     * @return string
     */
    public function export_json( bool $include_secrets = false ): string {
        return wp_json_encode( $this->export( $include_secrets ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    }

    /**
     * 导入配置
     *
     * @param array $config 配置数据
     * @param bool  $merge  是否合并（true=合并，false=覆盖）
     * @return array 导入结果
     */
    public function import( array $config, bool $merge = true ): array {
        $result = [
            'success'  => true,
            'imported' => [],
            'skipped'  => [],
            'errors'   => [],
        ];

        // 验证配置格式
        $validation = $this->validate_config( $config );
        if ( ! $validation['valid'] ) {
            $result['success'] = false;
            $result['errors']  = $validation['errors'];
            return $result;
        }

        // 导入选项
        foreach ( $config['options'] as $option_name => $value ) {
            // 只导入允许的选项
            if ( ! in_array( $option_name, $this->export_options, true ) ) {
                $result['skipped'][] = $option_name;
                continue;
            }

            try {
                if ( $merge ) {
                    $value = $this->merge_option( $option_name, $value );
                }

                if ( update_option( $option_name, $value ) ) {
                    $result['imported'][] = $option_name;
                } else {
                    // 值相同时 update_option 返回 false
                    $result['imported'][] = $option_name;
                }
            } catch ( \Exception $e ) {
                $result['errors'][] = sprintf(
                    __( '导入 %s 失败: %s', 'wpbridge' ),
                    $option_name,
                    $e->getMessage()
                );
            }
        }

        return $result;
    }

    /**
     * 从 JSON 字符串导入
     *
     * @param string $json  JSON 字符串
     * @param bool   $merge 是否合并
     * @return array 导入结果
     */
    public function import_json( string $json, bool $merge = true ): array {
        $config = json_decode( $json, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return [
                'success' => false,
                'errors'  => [ __( 'JSON 格式无效', 'wpbridge' ) ],
            ];
        }

        return $this->import( $config, $merge );
    }

    /**
     * 验证配置格式
     *
     * @param array $config 配置数据
     * @return array
     */
    public function validate_config( array $config ): array {
        $errors = [];

        if ( empty( $config['version'] ) ) {
            $errors[] = __( '缺少配置版本号', 'wpbridge' );
        }

        if ( empty( $config['options'] ) || ! is_array( $config['options'] ) ) {
            $errors[] = __( '缺少配置选项', 'wpbridge' );
        }

        // 检查版本兼容性
        if ( ! empty( $config['version'] ) && version_compare( $config['version'], self::CONFIG_VERSION, '>' ) ) {
            $errors[] = sprintf(
                __( '配置版本 %s 高于当前支持的版本 %s', 'wpbridge' ),
                $config['version'],
                self::CONFIG_VERSION
            );
        }

        return [
            'valid'  => empty( $errors ),
            'errors' => $errors,
        ];
    }

    /**
     * 清理敏感信息
     *
     * @param string $option_name 选项名
     * @param mixed  $value       选项值
     * @return mixed
     */
    private function sanitize_secrets( string $option_name, $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        // 更新源中的敏感字段
        if ( 'wpbridge_sources' === $option_name ) {
            foreach ( $value as &$source ) {
                if ( isset( $source['auth_token'] ) && ! empty( $source['auth_token'] ) ) {
                    $source['auth_token'] = '***REDACTED***';
                }
                if ( isset( $source['api_key'] ) && ! empty( $source['api_key'] ) ) {
                    $source['api_key'] = '***REDACTED***';
                }
            }
        }

        // AI 设置中的敏感字段
        if ( 'wpbridge_ai_settings' === $option_name ) {
            if ( isset( $value['api_key'] ) && ! empty( $value['api_key'] ) ) {
                $value['api_key'] = '***REDACTED***';
            }
        }

        return $value;
    }

    /**
     * 合并选项值
     *
     * @param string $option_name 选项名
     * @param mixed  $new_value   新值
     * @return mixed
     */
    private function merge_option( string $option_name, $new_value ) {
        $current = get_option( $option_name, [] );

        // 如果当前值为空，直接使用新值
        if ( empty( $current ) ) {
            return $new_value;
        }

        // 如果不是数组，直接覆盖
        if ( ! is_array( $current ) || ! is_array( $new_value ) ) {
            return $new_value;
        }

        // 更新源：按 ID 合并
        if ( 'wpbridge_sources' === $option_name ) {
            return $this->merge_sources( $current, $new_value );
        }

        // 源分组：按 ID 合并
        if ( 'wpbridge_source_groups' === $option_name ) {
            return $this->merge_by_id( $current, $new_value );
        }

        // 其他数组：深度合并
        return array_replace_recursive( $current, $new_value );
    }

    /**
     * 合并更新源
     *
     * @param array $current 当前源
     * @param array $new     新源
     * @return array
     */
    private function merge_sources( array $current, array $new ): array {
        $merged = $current;
        $ids    = array_column( $current, 'id' );

        foreach ( $new as $source ) {
            if ( empty( $source['id'] ) ) {
                continue;
            }

            $index = array_search( $source['id'], $ids, true );

            if ( false !== $index ) {
                // 更新现有源（保留敏感信息）
                if ( isset( $source['auth_token'] ) && '***REDACTED***' === $source['auth_token'] ) {
                    $source['auth_token'] = $merged[ $index ]['auth_token'] ?? '';
                }
                $merged[ $index ] = array_merge( $merged[ $index ], $source );
            } else {
                // 添加新源
                $merged[] = $source;
            }
        }

        return $merged;
    }

    /**
     * 按 ID 合并数组
     *
     * @param array $current 当前数组
     * @param array $new     新数组
     * @return array
     */
    private function merge_by_id( array $current, array $new ): array {
        $merged = $current;
        $ids    = array_column( $current, 'id' );

        foreach ( $new as $item ) {
            if ( empty( $item['id'] ) ) {
                continue;
            }

            $index = array_search( $item['id'], $ids, true );

            if ( false !== $index ) {
                $merged[ $index ] = array_merge( $merged[ $index ], $item );
            } else {
                $merged[] = $item;
            }
        }

        return $merged;
    }

    /**
     * 创建备份
     *
     * @return array 备份数据
     */
    public function create_backup(): array {
        return $this->export( true );
    }

    /**
     * 恢复备份
     *
     * @param array $backup 备份数据
     * @return array 恢复结果
     */
    public function restore_backup( array $backup ): array {
        return $this->import( $backup, false );
    }

    /**
     * 重置为默认配置
     *
     * @return bool
     */
    public function reset_to_defaults(): bool {
        foreach ( $this->export_options as $option_name ) {
            delete_option( $option_name );
        }

        // 重新初始化默认设置
        $settings = new Settings();
        $settings->init_defaults();

        return true;
    }
}
