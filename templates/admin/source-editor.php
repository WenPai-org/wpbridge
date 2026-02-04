<?php
/**
 * 更新源编辑模板
 *
 * @package WPBridge
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
    <h1><?php echo esc_html( $title ); ?></h1>

    <?php settings_errors( 'wpbridge' ); ?>

    <form method="post" class="wpbridge-form">
        <?php wp_nonce_field( 'wpbridge_action', 'wpbridge_nonce' ); ?>
        <input type="hidden" name="wpbridge_action" value="save_source">
        <input type="hidden" name="source_id" value="<?php echo esc_attr( $source->id ?? '' ); ?>">

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="wpbridge-name"><?php esc_html_e( '名称', 'wpbridge' ); ?></label>
                </th>
                <td>
                    <input type="text"
                           id="wpbridge-name"
                           name="name"
                           value="<?php echo esc_attr( $source->name ?? '' ); ?>"
                           class="regular-text"
                           required>
                    <p class="description"><?php esc_html_e( '更新源的显示名称', 'wpbridge' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpbridge-type"><?php esc_html_e( '类型', 'wpbridge' ); ?></label>
                </th>
                <td>
                    <select id="wpbridge-type" name="type" class="regular-text">
                        <?php foreach ( $types as $type_value => $type_label ) : ?>
                            <option value="<?php echo esc_attr( $type_value ); ?>"
                                    <?php selected( $source->type ?? 'json', $type_value ); ?>>
                                <?php echo esc_html( $type_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( '更新源的类型', 'wpbridge' ); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpbridge-api-url"><?php esc_html_e( 'API URL', 'wpbridge' ); ?></label>
                </th>
                <td>
                    <input type="url"
                           id="wpbridge-api-url"
                           name="api_url"
                           value="<?php echo esc_url( $source->api_url ?? '' ); ?>"
                           class="large-text"
                           required>
                    <p class="description">
                        <?php esc_html_e( '更新源的 API 地址。对于 JSON 类型，可以使用 {slug} 占位符。', 'wpbridge' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpbridge-slug"><?php esc_html_e( 'Slug', 'wpbridge' ); ?></label>
                </th>
                <td>
                    <input type="text"
                           id="wpbridge-slug"
                           name="slug"
                           value="<?php echo esc_attr( $source->slug ?? '' ); ?>"
                           class="regular-text">
                    <p class="description">
                        <?php esc_html_e( '插件/主题的 slug。留空表示匹配所有。', 'wpbridge' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpbridge-item-type"><?php esc_html_e( '项目类型', 'wpbridge' ); ?></label>
                </th>
                <td>
                    <select id="wpbridge-item-type" name="item_type">
                        <option value="plugin" <?php selected( $source->item_type ?? 'plugin', 'plugin' ); ?>>
                            <?php esc_html_e( '插件', 'wpbridge' ); ?>
                        </option>
                        <option value="theme" <?php selected( $source->item_type ?? 'plugin', 'theme' ); ?>>
                            <?php esc_html_e( '主题', 'wpbridge' ); ?>
                        </option>
                    </select>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpbridge-auth-token"><?php esc_html_e( '认证令牌', 'wpbridge' ); ?></label>
                </th>
                <td>
                    <input type="password"
                           id="wpbridge-auth-token"
                           name="auth_token"
                           value="<?php echo esc_attr( ! empty( $source->auth_token ) ? '********' : '' ); ?>"
                           class="regular-text"
                           autocomplete="new-password"
                           placeholder="<?php echo esc_attr( ! empty( $source->auth_token ) ? __( '已设置（留空保持不变）', 'wpbridge' ) : '' ); ?>">
                    <p class="description">
                        <?php esc_html_e( '用于私有仓库或需要认证的 API。留空表示无需认证。', 'wpbridge' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wpbridge-priority"><?php esc_html_e( '优先级', 'wpbridge' ); ?></label>
                </th>
                <td>
                    <input type="number"
                           id="wpbridge-priority"
                           name="priority"
                           value="<?php echo esc_attr( $source->priority ?? 50 ); ?>"
                           min="0"
                           max="100"
                           class="small-text">
                    <p class="description">
                        <?php esc_html_e( '数字越小优先级越高（0-100）', 'wpbridge' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e( '启用', 'wpbridge' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox"
                               name="enabled"
                               value="1"
                               <?php checked( $source->enabled ?? true ); ?>>
                        <?php esc_html_e( '启用此更新源', 'wpbridge' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit"
                   class="button button-primary"
                   value="<?php echo esc_attr( $is_edit ? __( '更新', 'wpbridge' ) : __( '添加', 'wpbridge' ) ); ?>">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpbridge' ) ); ?>" class="button">
                <?php esc_html_e( '取消', 'wpbridge' ); ?>
            </a>
        </p>
    </form>
</div>
