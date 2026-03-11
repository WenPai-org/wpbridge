<?php
/**
 * 预设供应商注册表
 *
 * 硬编码三种预设供应商的元数据
 *
 * @package WPBridge
 * @since 1.1.0
 */

declare(strict_types=1);

namespace WPBridge\Commercial\Vendors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PresetRegistry {

	/**
	 * 获取所有预设供应商
	 *
	 * @return array
	 */
	public static function get_presets(): array {
		return [
			'wenpai-marketplace' => [
				'name'        => '文派集市',
				'description' => '文派官方商城，购买后输入邮箱和授权密钥即可自动接收更新。',
				'type'        => 'wc_am',
				'auth_mode'   => 'wc_am',
				'api_url'     => '',
				'status'      => 'coming_soon',
				'icon'        => 'dashicons-store',
				'logo'        => 'wenpai-logo.png',
				'auth_fields' => [ 'email', 'license_key' ],
				'is_preset'   => true,
				'removable'   => false,
			],
			'weixiaoduo-mall'   => [
				'name'        => '薇晓朵商城',
				'description' => '薇晓朵数字商城，购买后输入邮箱和授权密钥即可自动接收更新。',
				'type'        => 'wc_am',
				'auth_mode'   => 'wc_am',
				'api_url'     => 'https://mall.weixiaoduo.com',
				'status'      => 'available',
				'icon'        => 'dashicons-cart',
				'logo'        => 'weixiaoduo-logo.png',
				'auth_fields' => [ 'email', 'license_key' ],
				'is_preset'   => true,
				'removable'           => false,
				'subscription_vendor' => true,
			],
			'custom-bridge-api'  => [
				'name'           => 'Bridge API',
				'description'    => '连接其他安装了文派云桥的站点，聚合多个插件源的更新。',
				'type'           => 'bridge_api',
				'status'         => 'available',
				'icon'           => 'dashicons-admin-links',
				'logo'           => '',
				'auth_fields'    => [ 'api_url', 'api_key' ],
				'is_preset'      => true,
				'multi_instance' => true,
			],
		];
	}

	/**
	 * 获取单个预设
	 *
	 * @param string $preset_id 预设 ID
	 * @return array|null
	 */
	public static function get_preset( string $preset_id ): ?array {
		$presets = self::get_presets();
		return $presets[ $preset_id ] ?? null;
	}

	/**
	 * 获取预设的认证字段标签
	 *
	 * @return array
	 */
	public static function get_auth_field_labels(): array {
		return [
			'email'       => __( '邮箱地址', 'wpbridge' ),
			'license_key' => __( '授权密钥', 'wpbridge' ),
			'api_url'     => __( 'API 地址', 'wpbridge' ),
			'api_key'     => __( 'API Key', 'wpbridge' ),
		];
	}

	/**
	 * 获取预设的认证字段占位符
	 *
	 * @return array
	 */
	public static function get_auth_field_placeholders(): array {
		return [
			'email'       => 'your@email.com',
			'license_key' => 'xxxx-xxxx-xxxx-xxxx',
			'api_url'     => 'https://example.com',
			'api_key'     => 'wpb_xxxxxxxxxxxxxxxx',
		];
	}

	/**
	 * 获取预设状态标签
	 *
	 * @return array
	 */
	public static function get_status_labels(): array {
		return [
			'available'    => __( '可用', 'wpbridge' ),
			'coming_soon'  => __( '即将上线', 'wpbridge' ),
			'activated'    => __( '已激活', 'wpbridge' ),
			'inactive'     => __( '未激活', 'wpbridge' ),
		];
	}
}
