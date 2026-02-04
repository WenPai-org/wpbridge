<?php
/**
 * 主题列表部分模板
 *
 * @package WPBridge
 * @since 0.6.0
 * @var array $installed_themes 已安装主题
 * @var array $all_sources 所有可用源
 * @var ItemSourceManager $item_manager 项目配置管理器
 * @var DefaultsManager $defaults_manager 默认规则管理器
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WPBridge\Core\ItemSourceManager;

// 获取当前主题
$current_theme = wp_get_theme();
$current_theme_slug = $current_theme->get_stylesheet();
?>

<!-- 批量操作工具栏 -->
<div class="wpbridge-toolbar">
    <div class="wpbridge-toolbar-left">
        <label class="wpbridge-checkbox-all">
            <input type="checkbox" id="wpbridge-select-all-themes">
            <span><?php esc_html_e( '全选', 'wpbridge' ); ?></span>
        </label>
        <select id="wpbridge-bulk-action-themes" class="wpbridge-select">
            <option value=""><?php esc_html_e( '批量操作', 'wpbridge' ); ?></option>
            <option value="set_source"><?php esc_html_e( '设置更新源', 'wpbridge' ); ?></option>
            <option value="reset_default"><?php esc_html_e( '重置为默认', 'wpbridge' ); ?></option>
            <option value="disable"><?php esc_html_e( '禁用更新', 'wpbridge' ); ?></option>
        </select>
        <select id="wpbridge-bulk-source-themes" class="wpbridge-select wpbridge-bulk-source-select" style="display: none;">
            <option value=""><?php esc_html_e( '-- 选择更新源 --', 'wpbridge' ); ?></option>
            <?php foreach ( $all_sources as $source ) : ?>
                <option value="<?php echo esc_attr( $source['source_key'] ); ?>">
                    <?php echo esc_html( $source['name'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm" id="wpbridge-apply-bulk-themes">
            <?php esc_html_e( '应用', 'wpbridge' ); ?>
        </button>
    </div>
    <div class="wpbridge-toolbar-right">
        <input type="search" class="wpbridge-search" id="wpbridge-search-themes" placeholder="<?php esc_attr_e( '搜索主题...', 'wpbridge' ); ?>" autocomplete="off">
    </div>
</div>

<!-- 主题列表 -->
<div class="wpbridge-project-list" id="wpbridge-themes-list">
    <?php if ( empty( $installed_themes ) ) : ?>
        <div class="wpbridge-empty">
            <span class="dashicons dashicons-admin-appearance"></span>
            <h3><?php esc_html_e( '暂无已安装主题', 'wpbridge' ); ?></h3>
        </div>
    <?php else : ?>
        <?php foreach ( $installed_themes as $theme_slug => $theme ) :
            $item_key = 'theme:' . $theme_slug;
            $config = $item_manager->get( $item_key );
            $mode = $config['mode'] ?? ItemSourceManager::MODE_DEFAULT;

            // 判断是否当前主题
            $is_active = $theme_slug === $current_theme_slug;
        ?>
            <div class="wpbridge-project-item" data-item-key="<?php echo esc_attr( $item_key ); ?>" data-item-type="theme">
                <div class="wpbridge-project-checkbox">
                    <input type="checkbox" class="wpbridge-project-select" value="<?php echo esc_attr( $item_key ); ?>">
                </div>

                <button type="button" class="wpbridge-btn wpbridge-btn-icon wpbridge-project-expand"
                        data-item-key="<?php echo esc_attr( $item_key ); ?>"
                        title="<?php esc_attr_e( '展开配置', 'wpbridge' ); ?>">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </button>

                <div class="wpbridge-project-thumbnail">
                    <?php
                    $screenshot = $theme->get_screenshot();
                    if ( $screenshot ) :
                        // get_screenshot() 返回相对路径，需要构建完整 URL
                        $screenshot_url = $theme->get_stylesheet_directory_uri() . '/' . basename( $screenshot );
                    ?>
                        <img loading="lazy" src="<?php echo esc_url( $screenshot_url ); ?>" alt="<?php echo esc_attr( $theme->get( 'Name' ) ); ?>">
                    <?php else : ?>
                        <span class="dashicons dashicons-admin-appearance"></span>
                    <?php endif; ?>
                </div>

                <div class="wpbridge-project-info">
                    <div class="wpbridge-project-name">
                        <?php echo esc_html( $theme->get( 'Name' ) ); ?>
                        <?php if ( $is_active ) : ?>
                            <span class="wpbridge-badge wpbridge-badge-success"><?php esc_html_e( '当前主题', 'wpbridge' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="wpbridge-project-meta">
                        <span class="wpbridge-project-version">v<?php echo esc_html( $theme->get( 'Version' ) ); ?></span>
                        <span class="wpbridge-project-slug"><?php echo esc_html( $theme_slug ); ?></span>
                        <?php if ( $theme->get( 'Author' ) ) : ?>
                            <span class="wpbridge-project-author"><?php echo esc_html( $theme->get( 'Author' ) ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="wpbridge-project-status">
                    <?php if ( $mode === ItemSourceManager::MODE_DISABLED ) : ?>
                        <span class="wpbridge-status-badge wpbridge-status-disabled">
                            <span class="dashicons dashicons-dismiss"></span>
                            <?php esc_html_e( '已禁用', 'wpbridge' ); ?>
                        </span>
                    <?php elseif ( $mode === ItemSourceManager::MODE_CUSTOM ) : ?>
                        <span class="wpbridge-status-badge wpbridge-status-custom">
                            <span class="dashicons dashicons-admin-links"></span>
                            <?php esc_html_e( '自定义', 'wpbridge' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="wpbridge-status-badge wpbridge-status-default">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php esc_html_e( '默认', 'wpbridge' ); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- 内联配置面板（默认折叠） -->
                <div class="wpbridge-project-config-panel" data-item-key="<?php echo esc_attr( $item_key ); ?>" style="display: none;">
                    <div class="wpbridge-config-row">
                        <label class="wpbridge-config-label"><?php esc_html_e( '更新模式', 'wpbridge' ); ?></label>
                        <div class="wpbridge-config-field">
                            <div class="wpbridge-radio-group">
                                <label class="wpbridge-radio">
                                    <input type="radio" name="mode_<?php echo esc_attr( $item_key ); ?>" value="default" class="wpbridge-mode-radio" <?php checked( $mode, ItemSourceManager::MODE_DEFAULT ); ?>>
                                    <span><?php esc_html_e( '使用默认源', 'wpbridge' ); ?></span>
                                </label>
                                <label class="wpbridge-radio">
                                    <input type="radio" name="mode_<?php echo esc_attr( $item_key ); ?>" value="custom" class="wpbridge-mode-radio" <?php checked( $mode, ItemSourceManager::MODE_CUSTOM ); ?>>
                                    <span><?php esc_html_e( '自定义源', 'wpbridge' ); ?></span>
                                </label>
                                <label class="wpbridge-radio">
                                    <input type="radio" name="mode_<?php echo esc_attr( $item_key ); ?>" value="disabled" class="wpbridge-mode-radio" <?php checked( $mode, ItemSourceManager::MODE_DISABLED ); ?>>
                                    <span><?php esc_html_e( '禁用更新', 'wpbridge' ); ?></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="wpbridge-config-row wpbridge-custom-source-row" style="<?php echo $mode !== ItemSourceManager::MODE_CUSTOM ? 'display: none;' : ''; ?>">
                        <label class="wpbridge-config-label"><?php esc_html_e( '更新地址', 'wpbridge' ); ?></label>
                        <div class="wpbridge-config-field">
                            <input type="url" class="wpbridge-form-input wpbridge-inline-url"
                                   data-item-key="<?php echo esc_attr( $item_key ); ?>"
                                   placeholder="https://github.com/user/repo 或 https://example.com/update.json"
                                   autocomplete="off">
                            <p class="wpbridge-form-help"><?php esc_html_e( '粘贴更新源地址，系统会自动识别类型', 'wpbridge' ); ?></p>
                        </div>
                    </div>
                    <div class="wpbridge-config-row wpbridge-custom-source-row" style="<?php echo $mode !== ItemSourceManager::MODE_CUSTOM ? 'display: none;' : ''; ?>">
                        <label class="wpbridge-config-label"><?php esc_html_e( '访问密码', 'wpbridge' ); ?></label>
                        <div class="wpbridge-config-field">
                            <input type="password" class="wpbridge-form-input wpbridge-inline-token"
                                   data-item-key="<?php echo esc_attr( $item_key ); ?>"
                                   placeholder="<?php esc_attr_e( '可选，用于私有仓库', 'wpbridge' ); ?>"
                                   autocomplete="new-password">
                        </div>
                    </div>
                    <div class="wpbridge-config-actions">
                        <button type="button" class="wpbridge-btn wpbridge-btn-primary wpbridge-btn-sm wpbridge-save-inline"
                                data-item-key="<?php echo esc_attr( $item_key ); ?>">
                            <span class="dashicons dashicons-saved"></span>
                            <?php esc_html_e( '保存', 'wpbridge' ); ?>
                        </button>
                        <button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm wpbridge-cancel-inline"
                                data-item-key="<?php echo esc_attr( $item_key ); ?>">
                            <?php esc_html_e( '取消', 'wpbridge' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
