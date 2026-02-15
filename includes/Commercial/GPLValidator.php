<?php
/**
 * GPL 合规验证器
 *
 * 自动检测插件是否为 GPL 兼容授权
 *
 * @package WPBridge
 * @since 0.9.7
 */

declare(strict_types=1);

namespace WPBridge\Commercial;

use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GPLValidator 类
 */
class GPLValidator {

	/**
	 * GPL 兼容的授权标识
	 */
	private const GPL_COMPATIBLE_LICENSES = [
		'gpl',
		'gpl-2.0',
		'gpl-2.0+',
		'gpl-2.0-or-later',
		'gpl-3.0',
		'gpl-3.0+',
		'gpl-3.0-or-later',
		'gplv2',
		'gplv3',
		'gnu general public license',
		'gnu gpl',
		'lgpl',
		'lgpl-2.1',
		'lgpl-3.0',
		'mit',
		'apache-2.0',
		'bsd',
	];

	/**
	 * 已知的 GPL 商业插件列表
	 */
	private const KNOWN_GPL_PLUGINS = [
		'elementor-pro',
		'wordpress-seo-premium',
		'advanced-custom-fields-pro',
		'gravityforms',
		'wpforms',
		'wpforms-lite',
		'ninja-forms',
		'seo-by-rank-math-pro',
		'wp-rocket',
		'perfmatters',
		'flavor',
		'updraftplus',
		'updraftplus-premium',
		'memberpress',
		'learndash',
		'woocommerce-subscriptions',
		'woocommerce-memberships',
	];

	/**
	 * 已知的非 GPL 插件列表（不应桥接）
	 */
	private const NON_GPL_PLUGINS = [
		// Envato 独占插件通常不是 GPL
	];

	/**
	 * 验证结果缓存
	 *
	 * @var array
	 */
	private array $cache = [];

	/**
	 * 验证插件是否 GPL 兼容
	 *
	 * @param string $plugin_slug 插件 slug
	 * @param string $plugin_file 插件文件路径（可选）
	 * @return array 包含 is_gpl, confidence, source 的数组
	 */
	public function validate( string $plugin_slug, string $plugin_file = '' ): array {
		// 检查缓存
		if ( isset( $this->cache[ $plugin_slug ] ) ) {
			return $this->cache[ $plugin_slug ];
		}

		$result = $this->do_validate( $plugin_slug, $plugin_file );

		// 缓存结果
		$this->cache[ $plugin_slug ] = $result;

		return $result;
	}

	/**
	 * 执行验证
	 *
	 * @param string $plugin_slug 插件 slug
	 * @param string $plugin_file 插件文件路径
	 * @return array
	 */
	private function do_validate( string $plugin_slug, string $plugin_file ): array {
		// 1. 检查已知列表
		if ( in_array( $plugin_slug, self::KNOWN_GPL_PLUGINS, true ) ) {
			return [
				'is_gpl'     => true,
				'confidence' => 100,
				'source'     => 'known_list',
				'license'    => 'GPL-2.0+',
			];
		}

		if ( in_array( $plugin_slug, self::NON_GPL_PLUGINS, true ) ) {
			return [
				'is_gpl'     => false,
				'confidence' => 100,
				'source'     => 'known_list',
				'license'    => 'proprietary',
			];
		}

		// 2. 检查 WordPress.org（如果存在则一定是 GPL）
		$wporg_result = $this->check_wordpress_org( $plugin_slug );
		if ( $wporg_result !== null ) {
			return [
				'is_gpl'     => true,
				'confidence' => 100,
				'source'     => 'wordpress_org',
				'license'    => $wporg_result['license'] ?? 'GPL-2.0+',
			];
		}

		// 3. 检查插件文件
		if ( ! empty( $plugin_file ) ) {
			$file_result = $this->check_plugin_file( $plugin_file );
			if ( $file_result !== null ) {
				return $file_result;
			}
		}

		// 4. 无法确定
		return [
			'is_gpl'     => null,
			'confidence' => 0,
			'source'     => 'unknown',
			'license'    => 'unknown',
		];
	}

	/**
	 * 检查 WordPress.org API
	 *
	 * @param string $plugin_slug 插件 slug
	 * @return array|null
	 */
	private function check_wordpress_org( string $plugin_slug ): ?array {
		$cache_key = 'wpbridge_wporg_gpl_' . md5( $plugin_slug );
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached === 'not_found' ? null : $cached;
		}

		$url      = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=' . urlencode( $plugin_slug );
		$response = wp_remote_get( $url, [ 'timeout' => 5 ] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code === 200 && ! empty( $body ) ) {
			$data = json_decode( $body, true );
			if ( isset( $data['slug'] ) ) {
				$result = [
					'license' => 'GPL-2.0+', // WordPress.org 要求 GPL
					'name'    => $data['name'] ?? '',
				];
				set_transient( $cache_key, $result, DAY_IN_SECONDS );
				return $result;
			}
		}

		set_transient( $cache_key, 'not_found', HOUR_IN_SECONDS );
		return null;
	}

	/**
	 * 检查插件文件中的授权信息
	 *
	 * @param string $plugin_file 插件文件路径
	 * @return array|null
	 */
	private function check_plugin_file( string $plugin_file ): ?array {
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

		if ( ! file_exists( $plugin_path ) ) {
			return null;
		}

		// 读取插件头部
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data( $plugin_path, false, false );
		$license     = $plugin_data['License'] ?? '';

		if ( ! empty( $license ) ) {
			$is_gpl = $this->is_gpl_compatible_license( $license );
			if ( $is_gpl !== null ) {
				return [
					'is_gpl'     => $is_gpl,
					'confidence' => 90,
					'source'     => 'plugin_header',
					'license'    => $license,
				];
			}
		}

		// 检查 license.txt
		$plugin_dir  = dirname( $plugin_path );
		$license_file = $plugin_dir . '/license.txt';

		if ( file_exists( $license_file ) ) {
			$license_content = file_get_contents( $license_file );
			if ( $this->contains_gpl_text( $license_content ) ) {
				return [
					'is_gpl'     => true,
					'confidence' => 85,
					'source'     => 'license_file',
					'license'    => 'GPL (from license.txt)',
				];
			}
		}

		// 检查 readme.txt
		$readme_file = $plugin_dir . '/readme.txt';
		if ( file_exists( $readme_file ) ) {
			$readme_content = file_get_contents( $readme_file );
			if ( preg_match( '/License:\s*(.+)/i', $readme_content, $matches ) ) {
				$license = trim( $matches[1] );
				$is_gpl  = $this->is_gpl_compatible_license( $license );
				if ( $is_gpl !== null ) {
					return [
						'is_gpl'     => $is_gpl,
						'confidence' => 80,
						'source'     => 'readme_file',
						'license'    => $license,
					];
				}
			}
		}

		return null;
	}

	/**
	 * 检查授权字符串是否 GPL 兼容
	 *
	 * @param string $license 授权字符串
	 * @return bool|null
	 */
	private function is_gpl_compatible_license( string $license ): ?bool {
		$license_lower = strtolower( trim( $license ) );

		foreach ( self::GPL_COMPATIBLE_LICENSES as $gpl_license ) {
			if ( strpos( $license_lower, $gpl_license ) !== false ) {
				return true;
			}
		}

		// 检查明确的非 GPL 标识
		$non_gpl_indicators = [ 'proprietary', 'commercial', 'all rights reserved', 'envato' ];
		foreach ( $non_gpl_indicators as $indicator ) {
			if ( strpos( $license_lower, $indicator ) !== false ) {
				return false;
			}
		}

		return null;
	}

	/**
	 * 检查文本是否包含 GPL 授权内容
	 *
	 * @param string $content 文本内容
	 * @return bool
	 */
	private function contains_gpl_text( string $content ): bool {
		$gpl_indicators = [
			'GNU General Public License',
			'GPL version 2',
			'GPL version 3',
			'GPLv2',
			'GPLv3',
			'free software',
			'redistribute it and/or modify',
		];

		foreach ( $gpl_indicators as $indicator ) {
			if ( stripos( $content, $indicator ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 批量验证
	 *
	 * @param array $plugins 插件列表 [ slug => file ]
	 * @return array
	 */
	public function validate_batch( array $plugins ): array {
		$results = [];
		foreach ( $plugins as $slug => $file ) {
			$results[ $slug ] = $this->validate( $slug, $file );
		}
		return $results;
	}

	/**
	 * 清除缓存
	 */
	public function clear_cache(): void {
		$this->cache = [];
	}

	/**
	 * 添加到已知 GPL 列表（运行时）
	 *
	 * @param string $plugin_slug 插件 slug
	 */
	public function add_known_gpl( string $plugin_slug ): void {
		$this->cache[ $plugin_slug ] = [
			'is_gpl'     => true,
			'confidence' => 100,
			'source'     => 'manual',
			'license'    => 'GPL (manually verified)',
		];
	}
}
