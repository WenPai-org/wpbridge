<?php
/**
 * 更新源编辑模板
 *
 * @package WPBridge
 * @since 0.5.0
 * @var \WPBridge\UpdateSource\SourceModel|null $source 源模型
 * @var array $types 类型列表
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_edit = null !== $source;
$title   = $is_edit ? __( '编辑更新源', 'wpbridge' ) : __( '添加更新源', 'wpbridge' );
?>

<div class="wrap wpbridge-wrap">
    <!-- 标题栏 -->
    <header class="wpbridge-header">
        <div class="wpbridge-header-left">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge' ) ); ?>" class="wpbridge-back-link">
                <span class="dashicons dashicons-arrow-left-alt2"></span>
            </a>
            <h1 class="wpbridge-title"><?php echo esc_html( $title ); ?></h1>
        </div>
    </header>

    <!-- 主内容区 -->
    <div class="wpbridge-content">
        <div class="wpbridge-tabs-card">
            <div class="wpbridge-tab-pane wpbridge-tab-pane-active" style="padding: 24px;">
                <?php settings_errors( 'wpbridge' ); ?>

                <form method="post" class="wpbridge-editor-form">
                    <?php wp_nonce_field( 'wpbridge_action', 'wpbridge_nonce' ); ?>
                    <input type="hidden" name="wpbridge_action" value="save_source">
                    <input type="hidden" name="source_id" value="<?php echo esc_attr( $source->id ?? '' ); ?>">

                    <!-- 基本信息 -->
                    <div class="wpbridge-form-section">
                        <h2 class="wpbridge-form-section-title"><?php esc_html_e( '基本信息', 'wpbridge' ); ?></h2>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label">
                                <?php esc_html_e( '名称', 'wpbridge' ); ?>
                                <span class="required">*</span>
                            </label>
                            <div>
                                <input type="text"
                                       name="name"
                                       value="<?php echo esc_attr( $source->name ?? '' ); ?>"
                                       class="wpbridge-form-input"
                                       placeholder="<?php esc_attr_e( '例如：我的私有仓库', 'wpbridge' ); ?>"
                                       required>
                                <p class="wpbridge-form-help"><?php esc_html_e( '更新源的显示名称', 'wpbridge' ); ?></p>
                            </div>
                        </div>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label">
                                <?php esc_html_e( '类型', 'wpbridge' ); ?>
                                <span class="required">*</span>
                            </label>
                            <div>
                                <select name="type" class="wpbridge-form-select">
                                    <?php foreach ( $types as $type_value => $type_label ) : ?>
                                        <option value="<?php echo esc_attr( $type_value ); ?>"
                                                <?php selected( $source->type ?? 'json', $type_value ); ?>>
                                            <?php echo esc_html( $type_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="wpbridge-form-help"><?php esc_html_e( '选择更新源的类型', 'wpbridge' ); ?></p>
                            </div>
                        </div>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label">
                                <?php esc_html_e( 'API URL', 'wpbridge' ); ?>
                                <span class="required">*</span>
                            </label>
                            <div>
                                <input type="url"
                                       name="api_url"
                                       value="<?php echo esc_url( $source->api_url ?? '' ); ?>"
                                       class="wpbridge-form-input"
                                       style="max-width: 100%;"
                                       placeholder="https://example.com/api/v1"
                                       required>
                                <p class="wpbridge-form-help">
                                    <?php esc_html_e( '更新源的 API 地址。对于 JSON 类型，可以使用 {slug} 占位符。', 'wpbridge' ); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- 匹配规则 -->
                    <div class="wpbridge-form-section">
                        <h2 class="wpbridge-form-section-title"><?php esc_html_e( '匹配规则', 'wpbridge' ); ?></h2>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label"><?php esc_html_e( '项目类型', 'wpbridge' ); ?></label>
                            <div>
                                <select name="item_type" class="wpbridge-form-select">
                                    <option value="plugin" <?php selected( $source->item_type ?? 'plugin', 'plugin' ); ?>>
                                        <?php esc_html_e( '插件', 'wpbridge' ); ?>
                                    </option>
                                    <option value="theme" <?php selected( $source->item_type ?? 'plugin', 'theme' ); ?>>
                                        <?php esc_html_e( '主题', 'wpbridge' ); ?>
                                    </option>
                                </select>
                                <p class="wpbridge-form-help"><?php esc_html_e( '此更新源用于插件还是主题', 'wpbridge' ); ?></p>
                            </div>
                        </div>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label"><?php esc_html_e( 'Slug', 'wpbridge' ); ?></label>
                            <div>
                                <input type="text"
                                       name="slug"
                                       value="<?php echo esc_attr( $source->slug ?? '' ); ?>"
                                       class="wpbridge-form-input"
                                       placeholder="<?php esc_attr_e( '留空匹配所有', 'wpbridge' ); ?>">
                                <p class="wpbridge-form-help">
                                    <?php esc_html_e( '指定插件/主题的 slug。留空表示匹配所有。', 'wpbridge' ); ?>
                                </p>
                            </div>
                        </div>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label"><?php esc_html_e( '优先级', 'wpbridge' ); ?></label>
                            <div>
                                <input type="number"
                                       name="priority"
                                       value="<?php echo esc_attr( $source->priority ?? 50 ); ?>"
                                       min="0"
                                       max="100"
                                       class="wpbridge-form-input"
                                       style="max-width: 100px;">
                                <p class="wpbridge-form-help">
                                    <?php esc_html_e( '数字越小优先级越高（0-100）', 'wpbridge' ); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- 认证设置 -->
                    <div class="wpbridge-form-section">
                        <h2 class="wpbridge-form-section-title"><?php esc_html_e( '认证设置', 'wpbridge' ); ?></h2>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label"><?php esc_html_e( '认证令牌', 'wpbridge' ); ?></label>
                            <div>
                                <input type="password"
                                       name="auth_token"
                                       value="<?php echo esc_attr( ! empty( $source->auth_token ) ? '********' : '' ); ?>"
                                       class="wpbridge-form-input"
                                       autocomplete="new-password"
                                       placeholder="<?php echo esc_attr( ! empty( $source->auth_token ) ? __( '已设置（留空保持不变）', 'wpbridge' ) : __( '可选', 'wpbridge' ) ); ?>">
                                <p class="wpbridge-form-help">
                                    <?php esc_html_e( '用于私有仓库或需要认证的 API。留空表示无需认证。', 'wpbridge' ); ?>
                                </p>
                            </div>
                        </div>

                        <div class="wpbridge-form-row">
                            <label class="wpbridge-form-label"><?php esc_html_e( '启用状态', 'wpbridge' ); ?></label>
                            <div>
                                <label class="wpbridge-toggle">
                                    <input type="checkbox" name="enabled" value="1" <?php checked( $source->enabled ?? true ); ?>>
                                    <span class="wpbridge-toggle-track"></span>
                                </label>
                                <p class="wpbridge-form-help" style="margin-top: 8px;">
                                    <?php esc_html_e( '启用此更新源', 'wpbridge' ); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- 操作按钮 -->
                    <div class="wpbridge-form-actions">
                        <button type="submit" class="wpbridge-btn wpbridge-btn-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php echo esc_html( $is_edit ? __( '保存更改', 'wpbridge' ) : __( '添加更新源', 'wpbridge' ) ); ?>
                        </button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge' ) ); ?>" class="wpbridge-btn wpbridge-btn-secondary">
                            <?php esc_html_e( '取消', 'wpbridge' ); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
