<?php
/**
 * API Key 管理器
 *
 * @package WPBridge
 */

namespace WPBridge\API;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Security\Encryption;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API Key 管理器类
 */
class ApiKeyManager {

    /**
     * 设置实例
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * 构造函数
     *
     * @param Settings $settings 设置实例
     */
    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * 生成新的 API Key
     *
     * @param string      $name       Key 名称
     * @param string|null $expires_at 过期时间
     * @param array       $permissions 权限列表
     * @return array
     */
    public function generate( string $name, ?string $expires_at = null, array $permissions = [] ): array {
        $api_key = Encryption::generate_token( 32 );
        $key_id  = 'key_' . wp_generate_uuid4();

        $key_data = [
            'id'          => $key_id,
            'name'        => sanitize_text_field( $name ),
            'key'         => $api_key,
            'key_prefix'  => substr( $api_key, 0, 8 ) . '...',
            'permissions' => $permissions,
            'expires_at'  => $expires_at,
            'created_at'  => current_time( 'mysql' ),
            'created_by'  => get_current_user_id(),
            'last_used'   => null,
            'usage_count' => 0,
        ];

        // 保存到设置
        $api_settings = $this->settings->get( 'api', [] );
        $api_settings['keys'] = $api_settings['keys'] ?? [];
        $api_settings['keys'][] = $key_data;

        $this->settings->set( 'api', $api_settings );

        Logger::info( 'API Key 已创建', [ 'id' => $key_id, 'name' => $name ] );

        return [
            'id'         => $key_id,
            'name'       => $name,
            'key'        => $api_key, // 只在创建时返回完整 key
            'expires_at' => $expires_at,
            'created_at' => $key_data['created_at'],
        ];
    }

    /**
     * 获取所有 API Keys（不含完整 key）
     *
     * @return array
     */
    public function get_all(): array {
        $api_settings = $this->settings->get( 'api', [] );
        $keys         = $api_settings['keys'] ?? [];

        return array_map( function ( $key ) {
            return [
                'id'          => $key['id'],
                'name'        => $key['name'],
                'key_prefix'  => $key['key_prefix'],
                'permissions' => $key['permissions'] ?? [],
                'expires_at'  => $key['expires_at'],
                'created_at'  => $key['created_at'],
                'last_used'   => $key['last_used'],
                'usage_count' => $key['usage_count'] ?? 0,
                'is_expired'  => $this->is_expired( $key ),
            ];
        }, $keys );
    }

    /**
     * 获取单个 API Key
     *
     * @param string $key_id Key ID
     * @return array|null
     */
    public function get( string $key_id ): ?array {
        $api_settings = $this->settings->get( 'api', [] );
        $keys         = $api_settings['keys'] ?? [];

        foreach ( $keys as $key ) {
            if ( $key['id'] === $key_id ) {
                return [
                    'id'          => $key['id'],
                    'name'        => $key['name'],
                    'key_prefix'  => $key['key_prefix'],
                    'permissions' => $key['permissions'] ?? [],
                    'expires_at'  => $key['expires_at'],
                    'created_at'  => $key['created_at'],
                    'last_used'   => $key['last_used'],
                    'usage_count' => $key['usage_count'] ?? 0,
                    'is_expired'  => $this->is_expired( $key ),
                ];
            }
        }

        return null;
    }

    /**
     * 删除 API Key
     *
     * @param string $key_id Key ID
     * @return bool
     */
    public function delete( string $key_id ): bool {
        $api_settings = $this->settings->get( 'api', [] );
        $keys         = $api_settings['keys'] ?? [];
        $new_keys     = [];

        foreach ( $keys as $key ) {
            if ( $key['id'] !== $key_id ) {
                $new_keys[] = $key;
            }
        }

        if ( count( $new_keys ) === count( $keys ) ) {
            return false;
        }

        $api_settings['keys'] = $new_keys;
        $this->settings->set( 'api', $api_settings );

        Logger::info( 'API Key 已删除', [ 'id' => $key_id ] );

        return true;
    }

    /**
     * 更新 API Key
     *
     * @param string $key_id Key ID
     * @param array  $data   更新数据
     * @return bool
     */
    public function update( string $key_id, array $data ): bool {
        $api_settings = $this->settings->get( 'api', [] );
        $keys         = $api_settings['keys'] ?? [];
        $found        = false;

        foreach ( $keys as $index => $key ) {
            if ( $key['id'] === $key_id ) {
                if ( isset( $data['name'] ) ) {
                    $keys[ $index ]['name'] = sanitize_text_field( $data['name'] );
                }
                if ( isset( $data['expires_at'] ) ) {
                    $keys[ $index ]['expires_at'] = $data['expires_at'];
                }
                if ( isset( $data['permissions'] ) ) {
                    $keys[ $index ]['permissions'] = $data['permissions'];
                }
                $found = true;
                break;
            }
        }

        if ( ! $found ) {
            return false;
        }

        $api_settings['keys'] = $keys;
        $this->settings->set( 'api', $api_settings );

        Logger::info( 'API Key 已更新', [ 'id' => $key_id ] );

        return true;
    }

    /**
     * 记录 API Key 使用
     *
     * @param string $api_key API Key
     */
    public function record_usage( string $api_key ): void {
        $api_settings = $this->settings->get( 'api', [] );
        $keys         = $api_settings['keys'] ?? [];

        foreach ( $keys as $index => $key ) {
            if ( hash_equals( $key['key'], $api_key ) ) {
                $keys[ $index ]['last_used']   = current_time( 'mysql' );
                $keys[ $index ]['usage_count'] = ( $key['usage_count'] ?? 0 ) + 1;
                break;
            }
        }

        $api_settings['keys'] = $keys;
        $this->settings->set( 'api', $api_settings );
    }

    /**
     * 检查 Key 是否过期
     *
     * @param array $key Key 数据
     * @return bool
     */
    private function is_expired( array $key ): bool {
        if ( empty( $key['expires_at'] ) ) {
            return false;
        }

        return strtotime( $key['expires_at'] ) < time();
    }

    /**
     * 撤销所有 API Keys
     *
     * @return int 撤销的数量
     */
    public function revoke_all(): int {
        $api_settings = $this->settings->get( 'api', [] );
        $count        = count( $api_settings['keys'] ?? [] );

        $api_settings['keys'] = [];
        $this->settings->set( 'api', $api_settings );

        Logger::info( '所有 API Keys 已撤销', [ 'count' => $count ] );

        return $count;
    }

    /**
     * 获取统计信息
     *
     * @return array
     */
    public function get_stats(): array {
        $keys    = $this->get_all();
        $total   = count( $keys );
        $active  = 0;
        $expired = 0;

        foreach ( $keys as $key ) {
            if ( $key['is_expired'] ) {
                $expired++;
            } else {
                $active++;
            }
        }

        return [
            'total'   => $total,
            'active'  => $active,
            'expired' => $expired,
        ];
    }
}
