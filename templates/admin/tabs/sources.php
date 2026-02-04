<?php
/**
 * 更新源 Tab 内容
 *
 * @package WPBridge
 * @since 0.5.0
 * @var array $sources 源列表
 * @var array $stats   统计信息
 * @var array $health_status 健康状态
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WPBridge\UpdateSource\SourceType;
?>

<!-- 统计面板 -->
<div class="wpbridge-stats-panel">
    <div class="wpbridge-stat-card">
        <div class="wpbridge-stat-card-header">
            <span class="dashicons dashicons-database"></span>
            <?php esc_html_e( '总更新源', 'wpbridge' ); ?>
        </div>
        <div class="wpbridge-stat-value"><?php echo esc_html( $stats['total'] ); ?></div>
    </div>
    <div class="wpbridge-stat-card">
        <div class="wpbridge-stat-card-header">
            <span class="dashicons dashicons-yes-alt"></span>
            <?php esc_html_e( '已启用', 'wpbridge' ); ?>
        </div>
        <div class="wpbridge-stat-value success"><?php echo esc_html( $stats['enabled'] ); ?></div>
    </div>
    <div class="wpbridge-stat-card">
        <div class="wpbridge-stat-card-header">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e( '缓存状态', 'wpbridge' ); ?>
        </div>
        <div class="wpbridge-stat-value">
            <button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-clear-cache">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e( '清除缓存', 'wpbridge' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- 更新源列表 -->
<div class="wpbridge-sources-header">
    <h2 class="wpbridge-sources-title"><?php esc_html_e( '更新源列表', 'wpbridge' ); ?></h2>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge&action=add' ) ); ?>" class="wpbridge-btn wpbridge-btn-primary">
        <span class="dashicons dashicons-plus-alt2"></span>
        <?php esc_html_e( '添加更新源', 'wpbridge' ); ?>
    </a>
</div>

<?php if ( empty( $sources ) ) : ?>
    <div class="wpbridge-empty">
        <span class="dashicons dashicons-cloud"></span>
        <h3 class="wpbridge-empty-title"><?php esc_html_e( '暂无更新源', 'wpbridge' ); ?></h3>
        <p class="wpbridge-empty-desc"><?php esc_html_e( '添加自定义更新源来管理插件和主题的更新。', 'wpbridge' ); ?></p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge&action=add' ) ); ?>" class="wpbridge-btn wpbridge-btn-primary">
            <?php esc_html_e( '添加第一个更新源', 'wpbridge' ); ?>
        </a>
    </div>
<?php else : ?>
    <div class="wpbridge-sources-grid">
        <?php foreach ( $sources as $source ) : ?>
            <div class="wpbridge-source-card" data-source-id="<?php echo esc_attr( $source->id ); ?>">
                <div class="wpbridge-source-card-header">
                    <div class="wpbridge-source-card-title">
                        <h3 class="wpbridge-source-name">
                            <?php echo esc_html( $source->name ?: $source->id ); ?>
                            <?php if ( $source->is_preset ) : ?>
                                <span class="wpbridge-badge wpbridge-badge-preset"><?php esc_html_e( '预置', 'wpbridge' ); ?></span>
                            <?php endif; ?>
                        </h3>
                        <span class="wpbridge-source-url"><?php echo esc_html( $source->api_url ); ?></span>
                    </div>
                    <label class="wpbridge-toggle">
                        <input type="checkbox"
                               class="wpbridge-toggle-source"
                               <?php checked( $source->enabled ); ?>
                               data-source-id="<?php echo esc_attr( $source->id ); ?>">
                        <span class="wpbridge-toggle-track"></span>
                    </label>
                </div>

                <div class="wpbridge-source-card-body">
                    <div class="wpbridge-source-meta">
                        <span class="wpbridge-badge wpbridge-badge-type <?php echo esc_attr( $source->type ); ?>">
                            <?php echo esc_html( SourceType::get_label( $source->type ) ); ?>
                        </span>
                        <span class="wpbridge-source-meta-item">
                            <span class="dashicons dashicons-admin-plugins"></span>
                            <?php echo esc_html( $source->item_type === 'plugin' ? __( '插件', 'wpbridge' ) : __( '主题', 'wpbridge' ) ); ?>
                        </span>
                        <?php if ( ! empty( $source->slug ) ) : ?>
                            <span class="wpbridge-source-meta-item">
                                <span class="dashicons dashicons-tag"></span>
                                <?php echo esc_html( $source->slug ); ?>
                            </span>
                        <?php endif; ?>
                        <span class="wpbridge-source-meta-item">
                            <span class="dashicons dashicons-sort"></span>
                            <?php
                            /* translators: %d: priority number */
                            printf( esc_html__( '优先级 %d', 'wpbridge' ), $source->priority );
                            ?>
                        </span>
                    </div>

                    <?php if ( isset( $health_status[ $source->id ] ) && is_array( $health_status[ $source->id ] ) ) : ?>
                        <span class="wpbridge-badge wpbridge-badge-status <?php echo esc_attr( $health_status[ $source->id ]['status'] ?? '' ); ?>">
                            <?php
                            $status_labels = [
                                'healthy'  => __( '正常', 'wpbridge' ),
                                'degraded' => __( '降级', 'wpbridge' ),
                                'failed'   => __( '失败', 'wpbridge' ),
                            ];
                            $current_status = $health_status[ $source->id ]['status'] ?? 'unknown';
                            echo esc_html( $status_labels[ $current_status ] ?? $current_status );
                            ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="wpbridge-source-card-footer">
                    <div class="wpbridge-source-actions">
                        <button type="button"
                                class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-test-source"
                                data-source-id="<?php echo esc_attr( $source->id ); ?>">
                            <span class="dashicons dashicons-admin-site-alt3"></span>
                            <?php esc_html_e( '测试', 'wpbridge' ); ?>
                        </button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge&action=edit&source=' . $source->id ) ); ?>"
                           class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm">
                            <span class="dashicons dashicons-edit"></span>
                            <?php esc_html_e( '编辑', 'wpbridge' ); ?>
                        </a>
                        <?php if ( ! $source->is_preset ) : ?>
                            <button type="button"
                                    class="wpbridge-btn wpbridge-btn-danger wpbridge-btn-sm wpbridge-delete-source"
                                    data-source-id="<?php echo esc_attr( $source->id ); ?>">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- 删除确认表单 -->
<form id="wpbridge-delete-form" method="post" style="display: none;">
    <?php wp_nonce_field( 'wpbridge_action', 'wpbridge_nonce' ); ?>
    <input type="hidden" name="wpbridge_action" value="delete_source">
    <input type="hidden" name="source_id" id="wpbridge-delete-source-id" value="">
</form>
