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
        <button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm" id="wpbridge-apply-bulk-themes">
            <?php esc_html_e( '应用', 'wpbridge' ); ?>
        </button>
    </div>
    <div class="wpbridge-toolbar-right">
        <input type="search" class="wpbridge-search" id="wpbridge-search-themes" placeholder="<?php esc_attr_e( '搜索主题...', 'wpbridge' ); ?>">
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

                <div class="wpbridge-project-thumbnail">
                    <?php if ( $theme->get_screenshot() ) : ?>
                        <img src="<?php echo esc_url( $theme->get_screenshot() ); ?>" alt="<?php echo esc_attr( $theme->get( 'Name' ) ); ?>">
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
                        <?php if ( $mode === ItemSourceManager::MODE_DISABLED ) : ?>
                            <span class="wpbridge-badge wpbridge-badge-warning"><?php esc_html_e( '更新已禁用', 'wpbridge' ); ?></span>
                        <?php elseif ( $mode === ItemSourceManager::MODE_CUSTOM ) : ?>
                            <span class="wpbridge-badge wpbridge-badge-info"><?php esc_html_e( '自定义源', 'wpbridge' ); ?></span>
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

                <div class="wpbridge-project-source">
                    <label class="wpbridge-project-source-label"><?php esc_html_e( '更新源', 'wpbridge' ); ?></label>
                    <select class="wpbridge-source-select" data-item-key="<?php echo esc_attr( $item_key ); ?>">
                        <option value="default" <?php selected( $mode, ItemSourceManager::MODE_DEFAULT ); ?>>
                            <?php esc_html_e( '使用默认', 'wpbridge' ); ?>
                        </option>
                        <option value="disabled" <?php selected( $mode, ItemSourceManager::MODE_DISABLED ); ?>>
                            <?php esc_html_e( '禁用更新', 'wpbridge' ); ?>
                        </option>
                        <optgroup label="<?php esc_attr_e( '自定义源', 'wpbridge' ); ?>">
                            <?php foreach ( $all_sources as $source ) : ?>
                                <?php
                                $is_selected = $mode === ItemSourceManager::MODE_CUSTOM &&
                                               isset( $config['source_ids'] ) &&
                                               array_key_exists( $source['source_key'], $config['source_ids'] );
                                ?>
                                <option value="<?php echo esc_attr( $source['source_key'] ); ?>" <?php selected( $is_selected ); ?>>
                                    <?php echo esc_html( $source['name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div class="wpbridge-project-actions">
                    <button type="button" class="wpbridge-btn wpbridge-btn-icon wpbridge-project-config"
                            data-item-key="<?php echo esc_attr( $item_key ); ?>"
                            title="<?php esc_attr_e( '高级配置', 'wpbridge' ); ?>">
                        <span class="dashicons dashicons-admin-generic"></span>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
