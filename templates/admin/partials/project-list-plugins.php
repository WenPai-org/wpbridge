<?php
/**
 * 插件列表部分模板
 *
 * @package WPBridge
 * @since 0.6.0
 * @var array $installed_plugins 已安装插件
 * @var array $all_sources 所有可用源
 * @var ItemSourceManager $item_manager 项目配置管理器
 * @var DefaultsManager $defaults_manager 默认规则管理器
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WPBridge\Core\ItemSourceManager;
?>

<!-- 批量操作工具栏 -->
<div class="wpbridge-toolbar">
    <div class="wpbridge-toolbar-left">
        <label class="wpbridge-checkbox-all">
            <input type="checkbox" id="wpbridge-select-all-plugins">
            <span><?php esc_html_e( '全选', 'wpbridge' ); ?></span>
        </label>
        <select id="wpbridge-bulk-action-plugins" class="wpbridge-select">
            <option value=""><?php esc_html_e( '批量操作', 'wpbridge' ); ?></option>
            <option value="set_source"><?php esc_html_e( '设置更新源', 'wpbridge' ); ?></option>
            <option value="reset_default"><?php esc_html_e( '重置为默认', 'wpbridge' ); ?></option>
            <option value="disable"><?php esc_html_e( '禁用更新', 'wpbridge' ); ?></option>
        </select>
        <button type="button" class="wpbridge-btn wpbridge-btn-secondary wpbridge-btn-sm" id="wpbridge-apply-bulk-plugins">
            <?php esc_html_e( '应用', 'wpbridge' ); ?>
        </button>
    </div>
    <div class="wpbridge-toolbar-right">
        <input type="search" class="wpbridge-search" id="wpbridge-search-plugins" placeholder="<?php esc_attr_e( '搜索插件...', 'wpbridge' ); ?>">
    </div>
</div>

<!-- 插件列表 -->
<div class="wpbridge-project-list" id="wpbridge-plugins-list">
    <?php if ( empty( $installed_plugins ) ) : ?>
        <div class="wpbridge-empty">
            <span class="dashicons dashicons-admin-plugins"></span>
            <h3><?php esc_html_e( '暂无已安装插件', 'wpbridge' ); ?></h3>
        </div>
    <?php else : ?>
        <?php foreach ( $installed_plugins as $plugin_file => $plugin_data ) :
            $item_key = 'plugin:' . $plugin_file;
            $config = $item_manager->get( $item_key );
            $mode = $config['mode'] ?? ItemSourceManager::MODE_DEFAULT;
            $effective_sources = $item_manager->get_effective_sources( $item_key, $defaults_manager );

            // 获取插件 slug
            $plugin_slug = dirname( $plugin_file );
            if ( $plugin_slug === '.' ) {
                $plugin_slug = basename( $plugin_file, '.php' );
            }

            // 判断是否激活
            $is_active = is_plugin_active( $plugin_file );
        ?>
            <div class="wpbridge-project-item" data-item-key="<?php echo esc_attr( $item_key ); ?>" data-item-type="plugin">
                <div class="wpbridge-project-checkbox">
                    <input type="checkbox" class="wpbridge-project-select" value="<?php echo esc_attr( $item_key ); ?>">
                </div>

                <div class="wpbridge-project-info">
                    <div class="wpbridge-project-name">
                        <?php echo esc_html( $plugin_data['Name'] ); ?>
                        <?php if ( $is_active ) : ?>
                            <span class="wpbridge-badge wpbridge-badge-success"><?php esc_html_e( '已激活', 'wpbridge' ); ?></span>
                        <?php endif; ?>
                        <?php if ( $mode === ItemSourceManager::MODE_DISABLED ) : ?>
                            <span class="wpbridge-badge wpbridge-badge-warning"><?php esc_html_e( '更新已禁用', 'wpbridge' ); ?></span>
                        <?php elseif ( $mode === ItemSourceManager::MODE_CUSTOM ) : ?>
                            <span class="wpbridge-badge wpbridge-badge-info"><?php esc_html_e( '自定义源', 'wpbridge' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="wpbridge-project-meta">
                        <span class="wpbridge-project-version">v<?php echo esc_html( $plugin_data['Version'] ); ?></span>
                        <span class="wpbridge-project-slug"><?php echo esc_html( $plugin_slug ); ?></span>
                        <?php if ( ! empty( $plugin_data['Author'] ) ) : ?>
                            <span class="wpbridge-project-author"><?php echo esc_html( $plugin_data['Author'] ); ?></span>
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
