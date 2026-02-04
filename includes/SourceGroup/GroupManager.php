<?php
/**
 * 源分组管理器
 *
 * @package WPBridge
 */

namespace WPBridge\SourceGroup;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Security\Encryption;
use WPBridge\UpdateSource\SourceManager;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 源分组管理器类
 */
class GroupManager {

    /**
     * 选项名称
     *
     * @var string
     */
    const OPTION_NAME = 'wpbridge_source_groups';

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
     *
     * @param Settings $settings 设置实例
     */
    public function __construct( Settings $settings ) {
        $this->settings       = $settings;
        $this->source_manager = new SourceManager( $settings );
    }

    /**
     * 获取所有分组
     *
     * @return GroupModel[]
     */
    public function get_all(): array {
        $groups_data = get_option( self::OPTION_NAME, [] );
        $groups      = [];

        foreach ( $groups_data as $data ) {
            $groups[] = GroupModel::from_array( $data );
        }

        return $groups;
    }

    /**
     * 获取单个分组
     *
     * @param string $id 分组 ID
     * @return GroupModel|null
     */
    public function get( string $id ): ?GroupModel {
        $groups = $this->get_all();

        foreach ( $groups as $group ) {
            if ( $group->id === $id ) {
                return $group;
            }
        }

        return null;
    }

    /**
     * 添加分组
     *
     * @param GroupModel $group 分组模型
     * @return bool
     */
    public function add( GroupModel $group ): bool {
        if ( ! $group->is_valid() ) {
            return false;
        }

        $groups = $this->get_all();

        // 生成 ID
        if ( empty( $group->id ) ) {
            $group->id = 'group_' . wp_generate_uuid4();
        }

        $group->created_at = current_time( 'mysql' );
        $group->updated_at = $group->created_at;

        // 加密共享认证令牌
        if ( ! empty( $group->shared_auth_token ) ) {
            $group->shared_auth_token = Encryption::encrypt( $group->shared_auth_token );
        }

        $groups[] = $group;

        Logger::info( '添加源分组', [ 'id' => $group->id, 'name' => $group->name ] );

        return $this->save_groups( $groups );
    }

    /**
     * 更新分组
     *
     * @param GroupModel $group 分组模型
     * @return bool
     */
    public function update( GroupModel $group ): bool {
        if ( ! $group->is_valid() ) {
            return false;
        }

        $groups = $this->get_all();
        $found  = false;

        foreach ( $groups as $index => $existing ) {
            if ( $existing->id === $group->id ) {
                $group->updated_at = current_time( 'mysql' );
                $group->created_at = $existing->created_at;

                // 处理共享认证令牌
                if ( $group->shared_auth_token === '********' || empty( $group->shared_auth_token ) ) {
                    $group->shared_auth_token = $existing->shared_auth_token;
                } else {
                    $group->shared_auth_token = Encryption::encrypt( $group->shared_auth_token );
                }

                $groups[ $index ] = $group;
                $found            = true;
                break;
            }
        }

        if ( ! $found ) {
            return false;
        }

        Logger::info( '更新源分组', [ 'id' => $group->id ] );

        return $this->save_groups( $groups );
    }

    /**
     * 删除分组
     *
     * @param string $id 分组 ID
     * @return bool
     */
    public function delete( string $id ): bool {
        $groups     = $this->get_all();
        $new_groups = [];

        foreach ( $groups as $group ) {
            if ( $group->id !== $id ) {
                $new_groups[] = $group;
            }
        }

        if ( count( $new_groups ) === count( $groups ) ) {
            return false;
        }

        Logger::info( '删除源分组', [ 'id' => $id ] );

        return $this->save_groups( $new_groups );
    }

    /**
     * 切换分组状态
     *
     * @param string $id      分组 ID
     * @param bool   $enabled 是否启用
     * @return bool
     */
    public function toggle( string $id, bool $enabled ): bool {
        $group = $this->get( $id );

        if ( null === $group ) {
            return false;
        }

        // 先更新分组状态
        $group->enabled = $enabled;
        if ( ! $this->update( $group ) ) {
            return false;
        }

        // 然后批量更新源状态，记录失败的源
        $failed_sources = [];
        foreach ( $group->source_ids as $source_id ) {
            if ( ! $this->source_manager->toggle( $source_id, $enabled ) ) {
                $failed_sources[] = $source_id;
            }
        }

        if ( ! empty( $failed_sources ) ) {
            Logger::warning( '部分源状态切换失败', [
                'group_id' => $id,
                'failed'   => $failed_sources,
            ] );
        }

        return true;
    }

    /**
     * 获取分组内的所有源
     *
     * @param string $group_id 分组 ID
     * @return array
     */
    public function get_group_sources( string $group_id ): array {
        $group = $this->get( $group_id );

        if ( null === $group ) {
            return [];
        }

        $sources = [];
        foreach ( $group->source_ids as $source_id ) {
            $source = $this->source_manager->get( $source_id );
            if ( null !== $source ) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * 将源添加到分组
     *
     * @param string $group_id  分组 ID
     * @param string $source_id 源 ID
     * @return bool
     */
    public function add_source_to_group( string $group_id, string $source_id ): bool {
        // 权限检查
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $group = $this->get( $group_id );

        if ( null === $group ) {
            return false;
        }

        $group->add_source( $source_id );

        return $this->update( $group );
    }

    /**
     * 从分组移除源
     *
     * @param string $group_id  分组 ID
     * @param string $source_id 源 ID
     * @return bool
     */
    public function remove_source_from_group( string $group_id, string $source_id ): bool {
        // 权限检查
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $group = $this->get( $group_id );

        if ( null === $group ) {
            return false;
        }

        $group->remove_source( $source_id );

        return $this->update( $group );
    }

    /**
     * 获取源所属的分组
     *
     * @param string $source_id 源 ID
     * @return GroupModel[]
     */
    public function get_source_groups( string $source_id ): array {
        $groups        = $this->get_all();
        $source_groups = [];

        foreach ( $groups as $group ) {
            if ( $group->has_source( $source_id ) ) {
                $source_groups[] = $group;
            }
        }

        return $source_groups;
    }

    /**
     * 保存分组数据
     *
     * @param GroupModel[] $groups 分组列表
     * @return bool
     */
    private function save_groups( array $groups ): bool {
        $data = array_map( fn( $g ) => $g->to_array(), $groups );
        return update_option( self::OPTION_NAME, $data );
    }

    /**
     * 获取统计信息
     *
     * @return array
     */
    public function get_stats(): array {
        $groups       = $this->get_all();
        $total        = count( $groups );
        $enabled      = 0;
        $total_sources = 0;

        foreach ( $groups as $group ) {
            if ( $group->enabled ) {
                $enabled++;
            }
            $total_sources += $group->get_source_count();
        }

        return [
            'total'         => $total,
            'enabled'       => $enabled,
            'disabled'      => $total - $enabled,
            'total_sources' => $total_sources,
        ];
    }
}
