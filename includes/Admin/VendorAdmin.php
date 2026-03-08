<?php
/**
 * 供应商管理后台
 *
 * @package WPBridge
 * @since 0.9.8
 */

declare(strict_types=1);

namespace WPBridge\Admin;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Commercial\BridgeManager;
use WPBridge\Commercial\Vendors\PresetRegistry;
use WPBridge\Security\Encryption;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VendorAdmin 类
 */
class VendorAdmin {

	/**
	 * 设置实例
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * 桥接管理器
	 *
	 * @var BridgeManager|null
	 */
	private ?BridgeManager $bridge_manager = null;

	/**
	 * 构造函数
	 *
	 * @param Settings $settings 设置实例
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->init_hooks();
	}

	/**
	 * 初始化钩子
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		// 供应商 AJAX 处理
		add_action( 'wp_ajax_wpbridge_add_vendor', [ $this, 'ajax_add_vendor' ] );
		add_action( 'wp_ajax_wpbridge_remove_vendor', [ $this, 'ajax_remove_vendor' ] );
		add_action( 'wp_ajax_wpbridge_test_vendor', [ $this, 'ajax_test_vendor' ] );
		add_action( 'wp_ajax_wpbridge_toggle_vendor', [ $this, 'ajax_toggle_vendor' ] );
		add_action( 'wp_ajax_wpbridge_sync_vendor_plugins', [ $this, 'ajax_sync_vendor_plugins' ] );

		// 预设供应商 AJAX 处理
		add_action( 'wp_ajax_wpbridge_activate_preset', [ $this, 'ajax_activate_preset' ] );
		add_action( 'wp_ajax_wpbridge_deactivate_preset', [ $this, 'ajax_deactivate_preset' ] );
		add_action( 'wp_ajax_wpbridge_add_bridge_vendor', [ $this, 'ajax_add_bridge_vendor' ] );

		// 自定义插件 AJAX 处理
		add_action( 'wp_ajax_wpbridge_add_custom_plugin', [ $this, 'ajax_add_custom_plugin' ] );
		add_action( 'wp_ajax_wpbridge_remove_custom_plugin', [ $this, 'ajax_remove_custom_plugin' ] );

		// Bridge Server AJAX 处理
		add_action( 'wp_ajax_wpbridge_test_bridge_server', [ $this, 'ajax_test_bridge_server' ] );
	}

	/**
	 * 获取桥接管理器
	 *
	 * @return BridgeManager
	 */
	private function get_bridge_manager(): BridgeManager {
		if ( null === $this->bridge_manager ) {
			$remote_config = \WPBridge\Core\RemoteConfig::get_instance();
			$this->bridge_manager = new BridgeManager( $this->settings, $remote_config );
		}
		return $this->bridge_manager;
	}

	/**
	 * AJAX: 添加供应商
	 *
	 * @return void
	 */
	public function ajax_add_vendor(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$vendor_id       = sanitize_key( $_POST['vendor_id'] ?? '' );
		$name            = sanitize_text_field( $_POST['name'] ?? '' );
		$type            = sanitize_text_field( $_POST['type'] ?? 'woocommerce' );
		$api_url         = esc_url_raw( $_POST['api_url'] ?? '' );
		$consumer_key    = sanitize_text_field( $_POST['consumer_key'] ?? '' );
		$consumer_secret = sanitize_text_field( $_POST['consumer_secret'] ?? '' );

		// 验证必填字段
		if ( empty( $vendor_id ) ) {
			wp_send_json_error( [ 'message' => __( '供应商 ID 不能为空', 'wpbridge' ) ] );
		}

		if ( empty( $name ) ) {
			wp_send_json_error( [ 'message' => __( '供应商名称不能为空', 'wpbridge' ) ] );
		}

		if ( empty( $api_url ) ) {
			wp_send_json_error( [ 'message' => __( 'API 地址不能为空', 'wpbridge' ) ] );
		}

		// 验证 URL 协议
		$scheme = wp_parse_url( $api_url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'API 地址必须使用 http 或 https 协议', 'wpbridge' ) ] );
		}

		// 验证供应商类型
		$allowed_types = [ 'woocommerce', 'wc_am', 'bridge_api' ];
		if ( ! in_array( $type, $allowed_types, true ) ) {
			wp_send_json_error( [ 'message' => __( '不支持的供应商类型', 'wpbridge' ) ] );
		}

		$result = $this->get_bridge_manager()->add_vendor(
			$vendor_id,
			$name,
			$type,
			$api_url,
			$consumer_key,
			$consumer_secret
		);

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: 移除供应商
	 *
	 * @return void
	 */
	public function ajax_remove_vendor(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$vendor_id = sanitize_key( $_POST['vendor_id'] ?? '' );

		if ( empty( $vendor_id ) ) {
			wp_send_json_error( [ 'message' => __( '供应商 ID 不能为空', 'wpbridge' ) ] );
		}

		$result = $this->get_bridge_manager()->remove_vendor( $vendor_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: 测试供应商连接
	 *
	 * @return void
	 */
	public function ajax_test_vendor(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$vendor_id = sanitize_key( $_POST['vendor_id'] ?? '' );

		if ( empty( $vendor_id ) ) {
			wp_send_json_error( [ 'message' => __( '供应商 ID 不能为空', 'wpbridge' ) ] );
		}

		$result = $this->get_bridge_manager()->test_vendor_connection( $vendor_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: 切换供应商状态
	 *
	 * @return void
	 */
	public function ajax_toggle_vendor(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$vendor_id = sanitize_key( $_POST['vendor_id'] ?? '' );
		$enabled   = (int) ( $_POST['enabled'] ?? 0 ) === 1;

		if ( empty( $vendor_id ) ) {
			wp_send_json_error( [ 'message' => __( '供应商 ID 不能为空', 'wpbridge' ) ] );
		}

		$vendors = $this->settings->get( 'vendors', [] );

		if ( ! isset( $vendors[ $vendor_id ] ) ) {
			wp_send_json_error( [ 'message' => __( '供应商不存在', 'wpbridge' ) ] );
		}

		$vendors[ $vendor_id ]['enabled'] = $enabled;
		$this->settings->set( 'vendors', $vendors );

		Logger::info( 'Vendor toggled', [
			'vendor_id' => $vendor_id,
			'enabled'   => $enabled,
		] );

		wp_send_json_success( [
			'message' => $enabled
				? __( '供应商已启用', 'wpbridge' )
				: __( '供应商已禁用', 'wpbridge' ),
		] );
	}

	/**
	 * AJAX: 同步供应商插件列表
	 *
	 * @return void
	 */
	public function ajax_sync_vendor_plugins(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$vendor_id = sanitize_key( $_POST['vendor_id'] ?? '' );

		$vendor_manager = $this->get_bridge_manager()->get_vendor_manager();

		if ( ! empty( $vendor_id ) ) {
			// 同步单个供应商
			$vendor = $vendor_manager->get_vendor( $vendor_id );
			if ( ! $vendor ) {
				wp_send_json_error( [ 'message' => __( '供应商不存在', 'wpbridge' ) ] );
				return;
			}

			$result = $vendor->get_plugins();
			$count  = $result['total'] ?? count( $result['plugins'] ?? [] );
			wp_send_json_success( [
				'message' => sprintf(
					/* translators: %d: plugin count */
					__( '已同步 %d 个插件', 'wpbridge' ),
					$count
				),
				'count'   => $count,
			] );
		} else {
			// 同步所有供应商
			$all_plugins = $vendor_manager->get_all_plugins();
			wp_send_json_success( [
				'message' => sprintf(
					/* translators: %d: plugin count */
					__( '已同步 %d 个插件', 'wpbridge' ),
					count( $all_plugins )
				),
				'count'   => count( $all_plugins ),
			] );
		}
	}

	/**
	 * AJAX: 激活预设供应商
	 *
	 * @return void
	 */
	public function ajax_activate_preset(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$preset_id = sanitize_key( $_POST['preset_id'] ?? '' );
		$email     = sanitize_email( $_POST['email'] ?? '' );
		$license   = sanitize_text_field( $_POST['license_key'] ?? '' );

		if ( empty( $preset_id ) ) {
			wp_send_json_error( [ 'message' => __( '预设 ID 不能为空', 'wpbridge' ) ] );
		}

		$preset = PresetRegistry::get_preset( $preset_id );
		if ( $preset === null ) {
			wp_send_json_error( [ 'message' => __( '无效的预设供应商', 'wpbridge' ) ] );
		}

		if ( ( $preset['status'] ?? '' ) === 'coming_soon' ) {
			wp_send_json_error( [ 'message' => __( '该供应商即将上线，暂不可用', 'wpbridge' ) ] );
		}

		if ( empty( $email ) || empty( $license ) ) {
			wp_send_json_error( [ 'message' => __( '请填写邮箱和授权密钥', 'wpbridge' ) ] );
		}

		$result = $this->get_bridge_manager()->add_vendor_v2( $preset_id, [
			'name'        => $preset['name'],
			'type'        => $preset['type'],
			'auth_mode'   => $preset['auth_mode'] ?? 'wc_am',
			'api_url'     => $preset['api_url'],
			'email'       => $email,
			'license_key' => $license,
			'is_preset'   => true,
		] );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: 停用预设供应商
	 *
	 * @return void
	 */
	public function ajax_deactivate_preset(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$preset_id = sanitize_key( $_POST['preset_id'] ?? '' );

		if ( empty( $preset_id ) ) {
			wp_send_json_error( [ 'message' => __( '预设 ID 不能为空', 'wpbridge' ) ] );
		}

		$preset = PresetRegistry::get_preset( $preset_id );
		if ( $preset === null ) {
			wp_send_json_error( [ 'message' => __( '无效的预设供应商', 'wpbridge' ) ] );
		}

		$result = $this->get_bridge_manager()->remove_vendor( $preset_id );

		// 清理加密存储的敏感数据
		Encryption::delete_secure( "vendor_{$preset_id}_license_key" );
		Encryption::delete_secure( "vendor_{$preset_id}_api_key" );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: 添加 Bridge API 连接
	 *
	 * @return void
	 */
	public function ajax_add_bridge_vendor(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$name    = sanitize_text_field( $_POST['name'] ?? '' );
		$api_url = esc_url_raw( $_POST['api_url'] ?? '' );
		$api_key = sanitize_text_field( $_POST['api_key'] ?? '' );

		if ( empty( $api_url ) ) {
			wp_send_json_error( [ 'message' => __( 'API 地址不能为空', 'wpbridge' ) ] );
		}

		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'API Key 不能为空', 'wpbridge' ) ] );
		}

		// 验证 URL 协议
		$scheme = wp_parse_url( $api_url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'API 地址必须使用 http 或 https 协议', 'wpbridge' ) ] );
		}

		// 自动生成 vendor_id
		$host      = wp_parse_url( $api_url, PHP_URL_HOST );
		$vendor_id = 'bridge-' . sanitize_key( str_replace( '.', '-', $host ?? '' ) ) . '-' . substr( md5( $api_url ), 0, 6 );

		if ( empty( $name ) ) {
			$name = $host ?: $api_url;
		}

		$result = $this->get_bridge_manager()->add_vendor_v2( $vendor_id, [
			'name'    => $name,
			'type'    => 'bridge_api',
			'api_url' => $api_url,
			'api_key' => $api_key,
		] );

		if ( $result['success'] ) {
			$result['vendor_id'] = $vendor_id;
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: 添加自定义插件
	 *
	 * @return void
	 */
	public function ajax_add_custom_plugin(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$plugin_slug = sanitize_key( $_POST['plugin_slug'] ?? '' );
		$name        = sanitize_text_field( $_POST['name'] ?? '' );
		$update_url  = esc_url_raw( $_POST['update_url'] ?? '' );

		if ( empty( $plugin_slug ) ) {
			wp_send_json_error( [ 'message' => __( '插件 slug 不能为空', 'wpbridge' ) ] );
		}

		$info = [
			'name'       => $name ?: $plugin_slug,
			'update_url' => $update_url,
		];

		$result = $this->get_bridge_manager()->add_custom_plugin( $plugin_slug, $info );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: 移除自定义插件
	 *
	 * @return void
	 */
	public function ajax_remove_custom_plugin(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$plugin_slug = sanitize_key( $_POST['plugin_slug'] ?? '' );

		if ( empty( $plugin_slug ) ) {
			wp_send_json_error( [ 'message' => __( '插件 slug 不能为空', 'wpbridge' ) ] );
		}

		$result = $this->get_bridge_manager()->remove_custom_plugin( $plugin_slug );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: 测试 Bridge Server 连接
	 *
	 * @return void
	 */
	public function ajax_test_bridge_server(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
		}

		$bridge_client = $this->get_bridge_manager()->get_bridge_client();

		if ( ! $bridge_client ) {
			wp_send_json_error( [ 'message' => __( 'Bridge Server 未配置', 'wpbridge' ) ] );
		}

		if ( $bridge_client->health_check() ) {
			wp_send_json_success( [ 'message' => __( '连接成功', 'wpbridge' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( '连接失败', 'wpbridge' ) ] );
		}
	}

	/**
	 * 渲染供应商设置页面
	 *
	 * @return void
	 */
	public function render_vendor_settings(): void {
		$vendors      = $this->get_bridge_manager()->get_vendors();
		$custom       = $this->settings->get( 'custom_plugins', [] );
		$all_plugins  = $this->get_bridge_manager()->get_all_available_plugins();
		$stats        = $this->get_bridge_manager()->get_stats();

		include WPBRIDGE_PATH . 'templates/admin/vendor-settings.php';
	}

	/**
	 * 获取供应商数据（用于模板）
	 *
	 * @return array
	 */
	public function get_vendor_data(): array {
		$vendors = $this->get_bridge_manager()->get_vendors();
		$presets = PresetRegistry::get_presets();

		// 标记已激活的预设
		foreach ( $presets as $preset_id => &$preset ) {
			$preset['activated'] = isset( $vendors[ $preset_id ] ) && ! empty( $vendors[ $preset_id ]['enabled'] );
		}
		unset( $preset );

		// 统计 Bridge API 连接数
		$bridge_count = 0;
		foreach ( $vendors as $vid => $vc ) {
			if ( ( $vc['type'] ?? '' ) === 'bridge_api' ) {
				$bridge_count++;
			}
		}

		return [
			'vendors'       => $vendors,
			'presets'        => $presets,
			'bridge_count'   => $bridge_count,
			'custom'        => $this->settings->get( 'custom_plugins', [] ),
			'all_plugins'   => $this->get_bridge_manager()->get_all_available_plugins(),
			'stats'         => $this->get_bridge_manager()->get_stats(),
			'vendor_types'  => [
				'woocommerce' => __( 'WooCommerce 商店', 'wpbridge' ),
				'wc_am'       => __( 'WC API Manager', 'wpbridge' ),
				'bridge_api'  => __( 'Bridge API', 'wpbridge' ),
			],
			'field_labels'       => PresetRegistry::get_auth_field_labels(),
			'field_placeholders' => PresetRegistry::get_auth_field_placeholders(),
			'status_labels'      => PresetRegistry::get_status_labels(),
		];
	}
}
