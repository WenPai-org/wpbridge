<?php
/**
 * 更新源列表模板
 *
 * @package WPBridge
 * @var array $sources 源列表
 * @var array $stats   统计信息
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WPBridge\UpdateSource\SourceType;
?>

<div class="wrap wpbridge-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'WPBridge 更新源', 'wpbridge' ); ?></h1>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge&action=add' ) ); ?>" class="page-title-action">
        <?php esc_html_e( '添加更新源', 'wpbridge' ); ?>
    </a>
    <hr class="wp-header-end">

    <?php settings_errors( 'wpbridge' ); ?>

    <!-- 统计信息 -->
    <div class="wpbridge-stats">
        <div class="wpbridge-stat-item">
            <span class="wpbridge-stat-number"><?php echo esc_html( $stats['total'] ); ?></span>
            <span class="wpbridge-stat-label"><?php esc_html_e( '总数', 'wpbridge' ); ?></span>
        </div>
        <div class="wpbridge-stat-item">
            <span class="wpbridge-stat-number"><?php echo esc_html( $stats['enabled'] ); ?></span>
            <span class="wpbridge-stat-label"><?php esc_html_e( '已启用', 'wpbridge' ); ?></span>
        </div>
        <div class="wpbridge-stat-item">
            <button type="button" class="button wpbridge-clear-cache">
                <?php esc_html_e( '清除缓存', 'wpbridge' ); ?>
            </button>
        </div>
    </div>

    <!-- 源列表 -->
    <table class="wp-list-table widefat fixed striped wpbridge-sources-table">
        <thead>
            <tr>
                <th class="column-status"><?php esc_html_e( '状态', 'wpbridge' ); ?></th>
                <th class="column-name"><?php esc_html_e( '名称', 'wpbridge' ); ?></th>
                <th class="column-type"><?php esc_html_e( '类型', 'wpbridge' ); ?></th>
                <th class="column-slug"><?php esc_html_e( 'Slug', 'wpbridge' ); ?></th>
                <th class="column-priority"><?php esc_html_e( '优先级', 'wpbridge' ); ?></th>
                <th class="column-actions"><?php esc_html_e( '操作', 'wpbridge' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $sources ) ) : ?>
                <tr>
                    <td colspan="6" class="wpbridge-no-items">
                        <?php esc_html_e( '暂无更新源', 'wpbridge' ); ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $sources as $source ) : ?>
                    <tr data-source-id="<?php echo esc_attr( $source->id ); ?>">
                        <td class="column-status">
                            <label class="wpbridge-toggle">
                                <input type="checkbox"
                                       class="wpbridge-toggle-source"
                                       <?php checked( $source->enabled ); ?>
                                       data-source-id="<?php echo esc_attr( $source->id ); ?>">
                                <span class="wpbridge-toggle-slider"></span>
                            </label>
                        </td>
                        <td class="column-name">
                            <strong>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge&action=edit&source=' . $source->id ) ); ?>">
                                    <?php echo esc_html( $source->name ?: $source->id ); ?>
                                </a>
                            </strong>
                            <?php if ( $source->is_preset ) : ?>
                                <span class="wpbridge-badge wpbridge-badge-preset"><?php esc_html_e( '预置', 'wpbridge' ); ?></span>
                            <?php endif; ?>
                            <div class="wpbridge-source-url">
                                <?php echo esc_html( $source->api_url ); ?>
                            </div>
                        </td>
                        <td class="column-type">
                            <span class="wpbridge-type-badge wpbridge-type-<?php echo esc_attr( $source->type ); ?>">
                                <?php echo esc_html( SourceType::get_label( $source->type ) ); ?>
                            </span>
                        </td>
                        <td class="column-slug">
                            <?php echo esc_html( $source->slug ?: __( '全部', 'wpbridge' ) ); ?>
                        </td>
                        <td class="column-priority">
                            <?php echo esc_html( $source->priority ); ?>
                        </td>
                        <td class="column-actions">
                            <button type="button"
                                    class="button button-small wpbridge-test-source"
                                    data-source-id="<?php echo esc_attr( $source->id ); ?>">
                                <?php esc_html_e( '测试', 'wpbridge' ); ?>
                            </button>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge&action=edit&source=' . $source->id ) ); ?>"
                               class="button button-small">
                                <?php esc_html_e( '编辑', 'wpbridge' ); ?>
                            </a>
                            <?php if ( ! $source->is_preset ) : ?>
                                <button type="button"
                                        class="button button-small button-link-delete wpbridge-delete-source"
                                        data-source-id="<?php echo esc_attr( $source->id ); ?>">
                                    <?php esc_html_e( '删除', 'wpbridge' ); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 删除确认表单 -->
    <form id="wpbridge-delete-form" method="post" style="display: none;">
        <?php wp_nonce_field( 'wpbridge_action', 'wpbridge_nonce' ); ?>
        <input type="hidden" name="wpbridge_action" value="delete_source">
        <input type="hidden" name="source_id" id="wpbridge-delete-source-id" value="">
    </form>
</div>
