<?php
/**
 * 更新源管理器
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 更新源管理器类
 */
class SourceManager {

    /**
     * 设置实例
     *
     * @var Settings
     */
    private Settings $settings;

    /**
     * 缓存的源模型
     *
     * @var array<string, SourceModel>
     */
    private array $source_models = [];

    /**
     * 构造函数
     *
     * @param Settings $settings 设置实例
     */
    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * 获取所有源
     *
     * @return SourceModel[]
     */
    public function get_all(): array {
        $sources = $this->settings->get_sources();
        $models  = [];

        foreach ( $sources as $source ) {
            $model = SourceModel::from_array( $source );
            $models[ $model->id ] = $model;
        }

        $this->source_models = $models;
        return $models;
    }

    /**
     * 获取启用的源
     *
     * @return SourceModel[]
     */
    public function get_enabled(): array {
        $all = $this->get_all();
        return array_filter( $all, function( SourceModel $source ) {
            return $source->enabled;
        } );
    }

    /**
     * 按优先级排序获取启用的源
     *
     * @return SourceModel[]
     */
    public function get_enabled_sorted(): array {
        $enabled = $this->get_enabled();
        uasort( $enabled, function( SourceModel $a, SourceModel $b ) {
            return $a->priority <=> $b->priority;
        } );
        return $enabled;
    }

    /**
     * 获取单个源
     *
     * @param string $id 源 ID
     * @return SourceModel|null
     */
    public function get( string $id ): ?SourceModel {
        if ( isset( $this->source_models[ $id ] ) ) {
            return $this->source_models[ $id ];
        }

        $source = $this->settings->get_source( $id );
        if ( null === $source ) {
            return null;
        }

        $model = SourceModel::from_array( $source );
        $this->source_models[ $id ] = $model;
        return $model;
    }

    /**
     * 根据 slug 获取源
     *
     * @param string $slug      插件/主题 slug
     * @param string $item_type 项目类型
     * @return SourceModel[]
     */
    public function get_by_slug( string $slug, string $item_type = 'plugin' ): array {
        $all = $this->get_enabled_sorted();
        return array_filter( $all, function( SourceModel $source ) use ( $slug, $item_type ) {
            // 空 slug 表示匹配所有
            if ( empty( $source->slug ) ) {
                return $source->item_type === $item_type;
            }
            return $source->slug === $slug && $source->item_type === $item_type;
        } );
    }

    /**
     * 添加源
     *
     * @param SourceModel $source 源模型
     * @return bool
     */
    public function add( SourceModel $source ): bool {
        // 验证
        $errors = $source->validate();
        if ( ! empty( $errors ) ) {
            Logger::error( '添加源失败：验证错误', [ 'errors' => $errors ] );
            return false;
        }

        // 生成 ID
        if ( empty( $source->id ) ) {
            $source->id = 'source_' . wp_generate_uuid4();
        }

        $result = $this->settings->add_source( $source->to_array() );

        if ( $result ) {
            $this->source_models[ $source->id ] = $source;
            Logger::info( '添加源成功', [ 'id' => $source->id, 'name' => $source->name ] );
        }

        return $result;
    }

    /**
     * 更新源
     *
     * @param SourceModel $source 源模型
     * @return bool
     */
    public function update( SourceModel $source ): bool {
        // 验证
        $errors = $source->validate();
        if ( ! empty( $errors ) ) {
            Logger::error( '更新源失败：验证错误', [ 'errors' => $errors ] );
            return false;
        }

        $result = $this->settings->update_source( $source->id, $source->to_array() );

        if ( $result ) {
            $this->source_models[ $source->id ] = $source;
            Logger::info( '更新源成功', [ 'id' => $source->id ] );
        }

        return $result;
    }

    /**
     * 删除源
     *
     * @param string $id 源 ID
     * @return bool
     */
    public function delete( string $id ): bool {
        // 不允许删除预置源
        if ( PresetSources::is_preset_id( $id ) ) {
            Logger::warning( '尝试删除预置源', [ 'id' => $id ] );
            return false;
        }

        $result = $this->settings->delete_source( $id );

        if ( $result ) {
            unset( $this->source_models[ $id ] );
            Logger::info( '删除源成功', [ 'id' => $id ] );
        }

        return $result;
    }

    /**
     * 启用/禁用源
     *
     * @param string $id      源 ID
     * @param bool   $enabled 是否启用
     * @return bool
     */
    public function toggle( string $id, bool $enabled ): bool {
        $result = $this->settings->toggle_source( $id, $enabled );

        if ( $result && isset( $this->source_models[ $id ] ) ) {
            $this->source_models[ $id ]->enabled = $enabled;
            Logger::info( $enabled ? '启用源' : '禁用源', [ 'id' => $id ] );
        }

        return $result;
    }

    /**
     * 获取源统计
     *
     * @return array
     */
    public function get_stats(): array {
        $all     = $this->get_all();
        $enabled = $this->get_enabled();

        $by_type = [];
        foreach ( $all as $source ) {
            $type = $source->type;
            if ( ! isset( $by_type[ $type ] ) ) {
                $by_type[ $type ] = 0;
            }
            $by_type[ $type ]++;
        }

        return [
            'total'   => count( $all ),
            'enabled' => count( $enabled ),
            'by_type' => $by_type,
        ];
    }

    /**
     * 清除缓存
     */
    public function clear_cache(): void {
        $this->source_models = [];
        $this->settings->clear_cache();
    }
}
