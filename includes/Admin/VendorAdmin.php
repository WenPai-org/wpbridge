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
use WPBridge\Core\SourceRegistry;
use WPBridge\Core\ItemSourceManager;
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

		// 订阅管理 AJAX 处理
		add_action( 'wp_ajax_wpbridge_get_subscription', [ $this, 'ajax_get_subscription' ] );
		add_action( 'wp_ajax_wpbridge_refresh_subscription', [ $this, 'ajax_refresh_subscription' ] );

		// 插件安装 AJAX 处理
		add_action( 'wp_ajax_wpbridge_install_plugin', [ $this, 'ajax_install_plugin' ] );

		// Bridge Server AJAX 处理
		add_action( 'wp_ajax_wpbridge_test_bridge_server', [ $this, 'ajax_test_bridge_server' ] );

		// 供应商更新绑定 AJAX 处理
		add_action( 'wp_ajax_wpbridge_bind_vendor_update', [ $this, 'ajax_bind_vendor_update' ] );
	}

	/**
	 * 获取桥接管理器
	 *
	 * @return BridgeManager
	 */
	private function get_bridge_manager(): BridgeManager {
		if ( null === $this->bridge_manager ) {
			$remote_config        = \WPBridge\Core\RemoteConfig::get_instance();
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
			return;
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
			return;
		}

		if ( empty( $name ) ) {
			wp_send_json_error( [ 'message' => __( '供应商名称不能为空', 'wpbridge' ) ] );
			return;
		}

		if ( empty( $api_url ) ) {
			wp_send_json_error( [ 'message' => __( 'API 地址不能为空', 'wpbridge' ) ] );
			return;
		}

		// SSRF 防护：校验 URL 格式 + 禁止内网地址
		if ( ! \WPBridge\Security\Validator::is_valid_url( $api_url ) ) {
			wp_send_json_error( [ 'message' => __( '无效的 API 地址或不允许访问内网地址', 'wpbridge' ) ] );
			return;
		}

		// 验证供应商类型
		$allowed_types = [ 'woocommerce', 'wc_am', 'bridge_api' ];
		if ( ! in_array( $type, $allowed_types, true ) ) {
			wp_send_json_error( [ 'message' => __( '不支持的供应商类型', 'wpbridge' ) ] );
			return;
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
			return;
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
			return;
		}

		$vendor_id = sanitize_key( $_POST['vendor_id'] ?? '' );

		if ( empty( $vendor_id ) ) {
			wp_send_json_error( [ 'message' => __( '供应商 ID 不能为空', 'wpbridge' ) ] );
			return;
		}

		$result = $this->get_bridge_manager()->remove_vendor( $vendor_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
			return;
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
			return;
		}

		$vendor_id = sanitize_key( $_POST['vendor_id'] ?? '' );

		if ( empty( $vendor_id ) ) {
			wp_send_json_error( [ 'message' => __( '供应商 ID 不能为空', 'wpbridge' ) ] );
			return;
		}

		$result = $this->get_bridge_manager()->test_vendor_connection( $vendor_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
			return;
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
			return;
		}

		$vendor_id = sanitize_key( $_POST['vendor_id'] ?? '' );
		$enabled   = (int) ( $_POST['enabled'] ?? 0 ) === 1;

		if ( empty( $vendor_id ) ) {
			wp_send_json_error( [ 'message' => __( '供应商 ID 不能为空', 'wpbridge' ) ] );
			return;
		}

		$vendors = $this->settings->get( 'vendors', [] );

		if ( ! isset( $vendors[ $vendor_id ] ) ) {
			wp_send_json_error( [ 'message' => __( '供应商不存在', 'wpbridge' ) ] );
			return;
		}

		$vendors[ $vendor_id ]['enabled'] = $enabled;
		$this->settings->set( 'vendors', $vendors );

		Logger::info(
			'Vendor toggled',
			[
				'vendor_id' => $vendor_id,
				'enabled'   => $enabled,
			]
		);

		wp_send_json_success(
			[
				'message' => $enabled
					? __( '供应商已启用', 'wpbridge' )
					: __( '供应商已禁用', 'wpbridge' ),
			]
		);
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
			return;
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

			// 清除缓存后重新拉取
			$vendor->clear_all_cache();
			$result = $vendor->get_plugins();
			$count  = $result['total'] ?? count( $result['plugins'] ?? [] );
			wp_send_json_success(
				[
					'message' => sprintf(
						/* translators: %d: plugin count */
						__( '已同步 %d 个插件', 'wpbridge' ),
						$count
					),
					'count'   => $count,
				]
			);
		} else {
			// 同步所有供应商：先清缓存
			foreach ( $vendor_manager->get_vendors() as $v ) {
				$v->clear_all_cache();
			}
			$all_plugins = $vendor_manager->get_all_plugins();
			wp_send_json_success(
				[
					'message' => sprintf(
						/* translators: %d: plugin count */
						__( '已同步 %d 个插件', 'wpbridge' ),
						count( $all_plugins )
					),
					'count'   => count( $all_plugins ),
				]
			);
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
			return;
		}

		$preset_id = sanitize_key( $_POST['preset_id'] ?? '' );
		$email     = sanitize_email( $_POST['email'] ?? '' );
		$license   = sanitize_text_field( $_POST['license_key'] ?? '' );

		if ( empty( $preset_id ) ) {
			wp_send_json_error( [ 'message' => __( '预设 ID 不能为空', 'wpbridge' ) ] );
			return;
		}

		$preset = PresetRegistry::get_preset( $preset_id );
		if ( $preset === null ) {
			wp_send_json_error( [ 'message' => __( '无效的预设供应商', 'wpbridge' ) ] );
			return;
		}

		if ( ( $preset['status'] ?? '' ) === 'coming_soon' ) {
			wp_send_json_error( [ 'message' => __( '该供应商即将上线，暂不可用', 'wpbridge' ) ] );
			return;
		}

		if ( empty( $email ) || empty( $license ) ) {
			wp_send_json_error( [ 'message' => __( '请填写邮箱和授权密钥', 'wpbridge' ) ] );
			return;
		}

		$result = $this->get_bridge_manager()->add_vendor_v2(
			$preset_id,
			[
				'name'        => $preset['name'],
				'type'        => $preset['type'],
				'auth_mode'   => $preset['auth_mode'] ?? 'wc_am',
				'api_url'     => $preset['api_url'],
				'email'       => $email,
				'license_key' => $license,
				'is_preset'   => true,
			]
		);

		if ( ! $result['success'] ) {
			wp_send_json_error( $result );
			return;
		}

		// 验证凭证
		$vendor_manager = $this->get_bridge_manager()->get_vendor_manager();
		$vendor         = $vendor_manager->get_vendor( $preset_id );

		if ( $vendor && ! $vendor->verify_credentials() ) {
			$this->get_bridge_manager()->remove_vendor( $preset_id );
			wp_send_json_error( [ 'message' => __( 'API Key 验证失败，请检查邮箱和授权密钥是否正确', 'wpbridge' ) ] );
			return;
		}

		// WC AM 模式：获取产品数量
		$product_count = 0;
		if ( $vendor && method_exists( $vendor, 'wc_am_product_list' ) ) {
			$list_response = $vendor->wc_am_product_list();
			if ( ! empty( $list_response['success'] ) && ! empty( $list_response['data']['product_list'] ) ) {
				$pl = $list_response['data']['product_list'];
				if ( isset( $pl['non_wc_subs_resources'] ) ) {
					$product_count = count( $pl['non_wc_subs_resources'] );
				} elseif ( isset( $pl[0] ) ) {
					$product_count = count( $pl );
				}
			}
		}

		// 如果是订阅供应商，刷新订阅状态
		if ( ! empty( $preset['subscription_vendor'] ) ) {
			$sub_manager = $this->get_bridge_manager()->get_subscription_manager();
			if ( $sub_manager ) {
				$sub_manager->clear_cache();
				$subscription           = $sub_manager->get_subscription( true );
				$result['subscription'] = [
					'plan'  => $subscription['plan'] ?? 'free',
					'label' => $subscription['label'] ?? '免费版',
				];
			}
		}

		$result['product_count'] = $product_count;
		if ( $product_count > 0 ) {
			$result['message'] = sprintf(
				/* translators: %d: product count */
				__( '连接成功，已发现 %d 个已购产品', 'wpbridge' ),
				$product_count
			);
		}

		// 注册为更新源
		$this->register_vendor_source( $preset_id, $preset['name'], $preset['api_url'] ?? '' );

		wp_send_json_success( $result );
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
			return;
		}

		$preset_id = sanitize_key( $_POST['preset_id'] ?? '' );

		if ( empty( $preset_id ) ) {
			wp_send_json_error( [ 'message' => __( '预设 ID 不能为空', 'wpbridge' ) ] );
			return;
		}

		$preset = PresetRegistry::get_preset( $preset_id );
		if ( $preset === null ) {
			wp_send_json_error( [ 'message' => __( '无效的预设供应商', 'wpbridge' ) ] );
			return;
		}

		$result = $this->get_bridge_manager()->remove_vendor( $preset_id );

		// 如果是订阅供应商，清除订阅缓存
		if ( ! empty( $preset['subscription_vendor'] ) ) {
			$sub_manager = $this->get_bridge_manager()->get_subscription_manager();
			if ( $sub_manager ) {
				$sub_manager->clear_cache();
			}
		}

		// 清理加密存储的敏感数据
		Encryption::delete_secure( "vendor_{$preset_id}_license_key" );
		Encryption::delete_secure( "vendor_{$preset_id}_api_key" );

		// 删除对应的更新源
		$this->unregister_vendor_source( $preset_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
			return;
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
			return;
		}

		$name    = sanitize_text_field( $_POST['name'] ?? '' );
		$api_url = esc_url_raw( $_POST['api_url'] ?? '' );
		$api_key = sanitize_text_field( $_POST['api_key'] ?? '' );

		if ( empty( $api_url ) ) {
			wp_send_json_error( [ 'message' => __( 'API 地址不能为空', 'wpbridge' ) ] );
			return;
		}

		if ( empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'API Key 不能为空', 'wpbridge' ) ] );
			return;
		}

		// 验证 URL 协议
		$scheme = wp_parse_url( $api_url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'API 地址必须使用 http 或 https 协议', 'wpbridge' ) ] );
			return;
		}

		// 自动生成 vendor_id
		$host      = wp_parse_url( $api_url, PHP_URL_HOST );
		$vendor_id = 'bridge-' . sanitize_key( str_replace( '.', '-', $host ?? '' ) ) . '-' . substr( md5( $api_url ), 0, 6 );

		if ( empty( $name ) ) {
			$name = $host ?: $api_url;
		}

		$result = $this->get_bridge_manager()->add_vendor_v2(
			$vendor_id,
			[
				'name'    => $name,
				'type'    => 'bridge_api',
				'api_url' => $api_url,
				'api_key' => $api_key,
			]
		);

		if ( $result['success'] ) {
			$result['vendor_id'] = $vendor_id;

			// 注册为更新源
			$this->register_vendor_source( $vendor_id, $name, $api_url );

			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
			return;
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
			return;
		}

		$plugin_slug = sanitize_key( $_POST['plugin_slug'] ?? '' );
		$name        = sanitize_text_field( $_POST['name'] ?? '' );
		$update_url  = esc_url_raw( $_POST['update_url'] ?? '' );

		if ( empty( $plugin_slug ) ) {
			wp_send_json_error( [ 'message' => __( '插件 slug 不能为空', 'wpbridge' ) ] );
			return;
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
			return;
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
			return;
		}

		$plugin_slug = sanitize_key( $_POST['plugin_slug'] ?? '' );

		if ( empty( $plugin_slug ) ) {
			wp_send_json_error( [ 'message' => __( '插件 slug 不能为空', 'wpbridge' ) ] );
			return;
		}

		$result = $this->get_bridge_manager()->remove_custom_plugin( $plugin_slug );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
			return;
		}
	}

	/**
	 * AJAX: 获取订阅状态
	 *
	 * @return void
	 */
	public function ajax_get_subscription(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
			return;
		}

		$sub_manager = $this->get_bridge_manager()->get_subscription_manager();

		if ( ! $sub_manager ) {
			wp_send_json_error( [ 'message' => __( '订阅管理器未初始化', 'wpbridge' ) ] );
			return;
		}

		$subscription = $sub_manager->get_subscription();

		wp_send_json_success(
			[
				'subscription' => $subscription,
				'is_paid'      => $sub_manager->is_paid(),
			]
		);
	}

	/**
	 * AJAX: 刷新订阅状态
	 *
	 * @return void
	 */
	public function ajax_refresh_subscription(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
			return;
		}

		$sub_manager = $this->get_bridge_manager()->get_subscription_manager();

		if ( ! $sub_manager ) {
			wp_send_json_error( [ 'message' => __( '订阅管理器未初始化', 'wpbridge' ) ] );
			return;
		}

		$sub_manager->clear_cache();
		$subscription = $sub_manager->get_subscription( true );

		wp_send_json_success(
			[
				'subscription' => $subscription,
				'is_paid'      => $sub_manager->is_paid(),
				'message'      => sprintf(
					/* translators: %s: plan label */
					__( '订阅状态已刷新：%s', 'wpbridge' ),
					$subscription['label'] ?? '免费版'
				),
			]
		);
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
			return;
		}

		$bridge_client = $this->get_bridge_manager()->get_bridge_client();

		if ( ! $bridge_client ) {
			wp_send_json_error( [ 'message' => __( 'Bridge Server 未配置', 'wpbridge' ) ] );
			return;
		}

		if ( $bridge_client->health_check() ) {
			wp_send_json_success( [ 'message' => __( '连接成功', 'wpbridge' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( '连接失败', 'wpbridge' ) ] );
			return;
		}
	}

	/**
	 * AJAX: 安装供应商插件
	 *
	 * @return void
	 */
	public function ajax_install_plugin(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
			return;
		}

		// 商业包体较大，延长执行时间
		set_time_limit( 600 );

		$plugin_slug = sanitize_key( $_POST['plugin_slug'] ?? '' );
		$vendor_id   = sanitize_key( $_POST['vendor_id'] ?? '' );

		if ( empty( $plugin_slug ) ) {
			wp_send_json_error( [ 'message' => __( '插件 slug 不能为空', 'wpbridge' ) ] );
			return;
		}

		$vendor_manager = $this->get_bridge_manager()->get_vendor_manager();
		$download_url   = $vendor_manager->get_download_url( $plugin_slug, $vendor_id );

		if ( empty( $download_url ) ) {
			wp_send_json_error( [ 'message' => __( '无法获取下载地址，请检查授权是否有效', 'wpbridge' ) ] );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		// 先下载到本地临时文件
		$tmpfile = download_url( $download_url, 600 );
		if ( is_wp_error( $tmpfile ) ) {
			wp_send_json_error( [ 'message' => __( '下载失败：', 'wpbridge' ) . $tmpfile->get_error_message() ] );
			return;
		}

		// 检查 zip 内容判断是插件还是主题
		$is_theme = $this->detect_package_is_theme( $tmpfile );
		$skin     = new \WP_Ajax_Upgrader_Skin();

		if ( $is_theme ) {
			if ( ! current_user_can( 'install_themes' ) ) {
				@unlink( $tmpfile );
				wp_send_json_error( [ 'message' => __( '没有安装主题的权限', 'wpbridge' ) ] );
				return;
			}
			$upgrader = new \Theme_Upgrader( $skin );
		} else {
			$upgrader = new \Plugin_Upgrader( $skin );
		}

		// 用 upgrader_pre_download 钩子让 Upgrader 直接使用已下载的本地文件
		$use_local = function () use ( $tmpfile ) {
			return $tmpfile;
		};
		add_filter( 'upgrader_pre_download', $use_local );
		$result = $upgrader->install( $download_url );
		remove_filter( 'upgrader_pre_download', $use_local );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			return;
		}

		if ( $result === false ) {
			$errors = $skin->get_errors();
			$msg    = is_wp_error( $errors ) ? $errors->get_error_message() : __( '安装失败', 'wpbridge' );
			wp_send_json_error( [ 'message' => $msg ] );
			return;
		}

		$installed_file = $is_theme ? $upgrader->theme_info() : $upgrader->plugin_info();
		$type_label     = $is_theme ? __( '主题', 'wpbridge' ) : __( '插件', 'wpbridge' );

		Logger::info(
			'Package installed via vendor',
			[
				'slug'      => $plugin_slug,
				'vendor_id' => $vendor_id,
				'type'      => $is_theme ? 'theme' : 'plugin',
				'file'      => $installed_file,
			]
		);

		wp_send_json_success(
			[
				'message'     => sprintf(
					/* translators: 1: type label, 2: slug */
					__( '%1$s %2$s 安装成功', 'wpbridge' ),
					$type_label,
					$plugin_slug
				),
				'plugin_file' => $installed_file,
				'type'        => $is_theme ? 'theme' : 'plugin',
			]
		);
	}

	/**
	 * 检测 zip 包是主题还是插件
	 *
	 * 主题包含 style.css（带 Theme Name 头），插件包含 .php 文件（带 Plugin Name 头）
	 *
	 * @param string $zip_path zip 文件路径
	 * @return bool true = 主题，false = 插件
	 */
	private function detect_package_is_theme( string $zip_path ): bool {
		$zip = new \ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			return false;
		}

		$is_theme = false;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			// 只检查顶层目录下的 style.css
			if ( preg_match( '#^[^/]+/style\.css$#', $name ) ) {
				$content = $zip->getFromIndex( $i );
				if ( $content !== false && preg_match( '/Theme Name\s*:/i', $content ) ) {
					$is_theme = true;
					break;
				}
			}
		}

		$zip->close();
		return $is_theme;
	}

	/**
	 * 渲染供应商设置页面
	 *
	 * @return void
	 */
	public function render_vendor_settings(): void {
		$vendors     = $this->get_bridge_manager()->get_vendors();
		$custom      = $this->settings->get( 'custom_plugins', [] );
		$all_plugins = $this->get_bridge_manager()->get_all_available_plugins();
		$stats       = $this->get_bridge_manager()->get_stats();

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
				++$bridge_count;
			}
		}

		$sub_manager  = $this->get_bridge_manager()->get_subscription_manager();
		$subscription = $sub_manager ? $sub_manager->get_subscription() : null;

		return [
			'vendors'            => $vendors,
			'presets'            => $presets,
			'bridge_count'       => $bridge_count,
			'custom'             => $this->settings->get( 'custom_plugins', [] ),
			'all_plugins'        => $this->get_bridge_manager()->get_all_available_plugins(),
			'stats'              => $this->get_bridge_manager()->get_stats(),
			'subscription'       => $subscription,
			'vendor_types'       => [
				'woocommerce' => __( 'WooCommerce 商店', 'wpbridge' ),
				'wc_am'       => __( 'WC API Manager', 'wpbridge' ),
				'bridge_api'  => __( 'Bridge API', 'wpbridge' ),
			],
			'field_labels'       => PresetRegistry::get_auth_field_labels(),
			'field_placeholders' => PresetRegistry::get_auth_field_placeholders(),
			'status_labels'      => PresetRegistry::get_status_labels(),
		];
	}

	/**
	 * AJAX: 绑定/解绑供应商更新
	 *
	 * @return void
	 */
	public function ajax_bind_vendor_update(): void {
		check_ajax_referer( 'wpbridge_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '权限不足', 'wpbridge' ) ] );
			return;
		}

		$slug      = sanitize_text_field( wp_unslash( $_POST['plugin_slug'] ?? '' ) );
		$vendor_id = sanitize_key( $_POST['vendor_id'] ?? '' );
		$item_type = sanitize_key( $_POST['item_type'] ?? 'plugin' );
		$enabled   = (int) ( $_POST['enabled'] ?? 0 ) === 1;

		if ( empty( $slug ) || empty( $vendor_id ) ) {
			wp_send_json_error( [ 'message' => __( '参数不完整', 'wpbridge' ) ] );
			return;
		}

		// 根据类型构建 item_key
		if ( $item_type === 'theme' ) {
			// 主题：大小写不敏感查找实际目录名
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				// 遍历已安装主题做大小写不敏感匹配
				$found_slug = '';
				foreach ( wp_get_themes() as $ts => $to ) {
					if ( strtolower( $ts ) === strtolower( $slug ) ) {
						$found_slug = $ts;
						break;
					}
				}
				if ( empty( $found_slug ) ) {
					wp_send_json_error( [ 'message' => __( '未找到已安装的主题', 'wpbridge' ) ] );
					return;
				}
				$slug = $found_slug;
			}
			$item_key = 'theme:' . $slug;
		} else {
			// 插件：解析 plugin_file
			$plugin_file = $this->resolve_plugin_file( $slug );
			if ( empty( $plugin_file ) ) {
				wp_send_json_error( [ 'message' => __( '未找到已安装的插件', 'wpbridge' ) ] );
				return;
			}
			$item_key = 'plugin:' . $plugin_file;
		}

		$source_key = 'vendor_' . $vendor_id;

		$source_registry = new SourceRegistry();
		$item_manager    = new ItemSourceManager( $source_registry );

		if ( $enabled ) {
			// 确保源存在
			if ( ! $source_registry->get( $source_key ) ) {
				wp_send_json_error( [ 'message' => __( '供应商更新源不存在，请先激活供应商', 'wpbridge' ) ] );
				return;
			}
			$result = $item_manager->set_source( $item_key, $source_key, 50 );
		} else {
			$result = $item_manager->delete( $item_key );
		}

		if ( $result ) {
			wp_send_json_success(
				[
					'message' => $enabled
						? __( '已启用供应商更新', 'wpbridge' )
						: __( '已恢复默认更新源', 'wpbridge' ),
				]
			);
		} else {
			wp_send_json_error( [ 'message' => __( '操作失败', 'wpbridge' ) ] );
			return;
		}
	}

	/**
	 * 注册供应商为更新源
	 *
	 * @param string $vendor_id   供应商 ID
	 * @param string $vendor_name 供应商名称
	 * @param string $api_url     API 地址
	 * @return void
	 */
	private function register_vendor_source( string $vendor_id, string $vendor_name, string $api_url ): void {
		$source_key      = 'vendor_' . $vendor_id;
		$source_registry = new SourceRegistry();

		// 如果已存在，跳过
		if ( $source_registry->get( $source_key ) ) {
			return;
		}

		$source_registry->add(
			[
				'source_key'       => $source_key,
				'name'             => $vendor_name . __( ' (供应商)', 'wpbridge' ),
				'type'             => SourceRegistry::TYPE_VENDOR,
				'api_url'          => $api_url ?: 'vendor://' . $vendor_id,
				'enabled'          => true,
				'is_preset'        => false,
				'default_priority' => 50,
				'metadata'         => [ 'vendor_id' => $vendor_id ],
			]
		);

		Logger::info(
			'供应商已注册为更新源',
			[
				'vendor_id'  => $vendor_id,
				'source_key' => $source_key,
			]
		);
	}

	/**
	 * 删除供应商对应的更新源
	 *
	 * @param string $vendor_id 供应商 ID
	 * @return void
	 */
	private function unregister_vendor_source( string $vendor_id ): void {
		$source_key      = 'vendor_' . $vendor_id;
		$source_registry = new SourceRegistry();

		if ( $source_registry->get( $source_key ) ) {
			$source_registry->delete( $source_key );

			Logger::info(
				'供应商更新源已删除',
				[
					'vendor_id'  => $vendor_id,
					'source_key' => $source_key,
				]
			);
		}
	}

	/**
	 * 根据 slug 解析 plugin_file
	 *
	 * @param string $slug 插件 slug
	 * @return string 插件文件路径（plugin_basename 格式），未找到返回空字符串
	 */
	private function resolve_plugin_file( string $slug ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed  = get_plugins();
		$slug_lower = strtolower( $slug );

		foreach ( $installed as $file => $data ) {
			$dir_slug = dirname( $file );
			if ( strtolower( $dir_slug ) === $slug_lower || strtolower( basename( $file, '.php' ) ) === $slug_lower ) {
				return $file;
			}
		}

		// 回退：用 sanitize_title( Name ) 做模糊匹配
		foreach ( $installed as $file => $data ) {
			$name_slug = sanitize_title( $data['Name'] ?? '' );
			if ( $name_slug === $slug_lower ) {
				return $file;
			}
		}

		return '';
	}
}
