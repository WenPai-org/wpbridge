<?php
/**
 * 源分组数据模型
 *
 * @package WPBridge
 */

namespace WPBridge\SourceGroup;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 源分组模型类
 */
class GroupModel {

    /**
     * 唯一标识
     *
     * @var string
     */
    public string $id = '';

    /**
     * 分组名称
     *
     * @var string
     */
    public string $name = '';

    /**
     * 分组描述
     *
     * @var string
     */
    public string $description = '';

    /**
     * 包含的源 ID 列表
     *
     * @var array
     */
    public array $source_ids = [];

    /**
     * 共享认证令牌
     *
     * @var string
     */
    public string $shared_auth_token = '';

    /**
     * 是否启用
     *
     * @var bool
     */
    public bool $enabled = true;

    /**
     * 创建时间
     *
     * @var string
     */
    public string $created_at = '';

    /**
     * 更新时间
     *
     * @var string
     */
    public string $updated_at = '';

    /**
     * 从数组创建实例
     *
     * @param array $data 数据数组
     * @return self
     */
    public static function from_array( array $data ): self {
        $model = new self();

        $model->id                = $data['id'] ?? '';
        $model->name              = $data['name'] ?? '';
        $model->description       = $data['description'] ?? '';
        $model->source_ids        = $data['source_ids'] ?? [];
        $model->shared_auth_token = $data['shared_auth_token'] ?? '';
        $model->enabled           = (bool) ( $data['enabled'] ?? true );
        $model->created_at        = $data['created_at'] ?? '';
        $model->updated_at        = $data['updated_at'] ?? '';

        return $model;
    }

    /**
     * 转换为数组
     *
     * @return array
     */
    public function to_array(): array {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'description'       => $this->description,
            'source_ids'        => $this->source_ids,
            'shared_auth_token' => $this->shared_auth_token,
            'enabled'           => $this->enabled,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }

    /**
     * 验证模型
     *
     * @return array 错误数组
     */
    public function validate(): array {
        $errors = [];

        if ( empty( $this->name ) ) {
            $errors['name'] = __( '分组名称不能为空', 'wpbridge' );
        }

        return $errors;
    }

    /**
     * 是否有效
     *
     * @return bool
     */
    public function is_valid(): bool {
        return empty( $this->validate() );
    }

    /**
     * 添加源到分组
     *
     * @param string $source_id 源 ID
     */
    public function add_source( string $source_id ): void {
        if ( ! in_array( $source_id, $this->source_ids, true ) ) {
            $this->source_ids[] = $source_id;
        }
    }

    /**
     * 从分组移除源
     *
     * @param string $source_id 源 ID
     */
    public function remove_source( string $source_id ): void {
        $this->source_ids = array_values(
            array_filter(
                $this->source_ids,
                fn( $id ) => $id !== $source_id
            )
        );
    }

    /**
     * 检查源是否在分组中
     *
     * @param string $source_id 源 ID
     * @return bool
     */
    public function has_source( string $source_id ): bool {
        return in_array( $source_id, $this->source_ids, true );
    }

    /**
     * 获取源数量
     *
     * @return int
     */
    public function get_source_count(): int {
        return count( $this->source_ids );
    }
}
