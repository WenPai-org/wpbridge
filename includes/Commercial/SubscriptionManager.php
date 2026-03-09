<?php
/**
 * 订阅管理器
 *
 * 通过 WC AM API 验证用户购买状态，管理订阅等级和功能门控
 *
 * @package WPBridge
 * @since 1.1.0
 */

declare(strict_types=1);

namespace WPBridge\Commercial;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\Commercial\Vendors\VendorManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SubscriptionManager {

	/**
	 * 订阅供应商 ID（薇晓朵商城）
	 */
	private const SUBSCRIPTION_VENDOR_ID = 'weixiaoduo-store';

	/**
	 * 订阅缓存 transient key
	 */
	private const CACHE_KEY = 'wpbridge_subscription';

	/**
	 * 缓存时长（秒）
	 */
	private const CACHE_TTL = 3600;

	/**
	 * 产品 → 订阅等级映射
	 *
	 * 等级优先级: all_access(100) > pro_enterprise(50) > pro(10) > free(0)
	 * 41706 为 variable 父产品，41708/41709 为变体，均需映射
	 */
	private const PRODUCT_PLANS = [
		41705 => [
			'plan'            => 'all_access',
			'label'           => '全站通',
			'priority'        => 100,
			'plugins_limit'   => PHP_INT_MAX,
			'daily_downloads' => PHP_INT_MAX,
			'features'        => [ 'bridge_api', 'bridge_server', 'license_proxy', 'priority_support' ],
		],
		41706 => [
			'plan'            => 'pro',
			'label'           => 'Pro',
			'priority'        => 10,
			'plugins_limit'   => PHP_INT_MAX,
			'daily_downloads' => 50,
			'features'        => [ 'bridge_api', 'bridge_server' ],
		],
		41708 => [
			'plan'            => 'pro',
			'label'           => 'Pro 个人版',
			'priority'        => 10,
			'plugins_limit'   => PHP_INT_MAX,
			'daily_downloads' => 50,
			'features'        => [ 'bridge_api', 'bridge_server' ],
		],
		41709 => [
			'plan'            => 'pro_enterprise',
			'label'           => 'Pro 企业版',
			'priority'        => 50,
			'plugins_limit'   => PHP_INT_MAX,
			'daily_downloads' => 500,
			'features'        => [ 'bridge_api', 'bridge_server', 'priority_support' ],
		],
	];

	/**
	 * Free 计划默认值
	 */
	private const FREE_PLAN = [
		'plan'            => 'free',
		'label'           => '免费版',
		'priority'        => 0,
		'plugins_limit'   => 0,
		'daily_downloads' => 0,
		'features'        => [],
		'product_id'      => 0,
	];

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var VendorManager
	 */
	private VendorManager $vendor_manager;

	public function __construct( Settings $settings, VendorManager $vendor_manager ) {
		$this->settings       = $settings;
		$this->vendor_manager = $vendor_manager;
	}

	/**
	 * 获取当前订阅信息
	 *
	 * @param bool $force_refresh 强制刷新（跳过缓存）
	 * @return array
	 */
	public function get_subscription( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$subscription = $this->resolve_subscription();

		set_transient( self::CACHE_KEY, $subscription, self::CACHE_TTL );

		return $subscription;
	}

	/**
	 * 从商城 API 解析当前订阅等级
	 *
	 * @return array
	 */
	private function resolve_subscription(): array {
		$vendor = $this->vendor_manager->get_vendor( self::SUBSCRIPTION_VENDOR_ID );

		if ( null === $vendor ) {
			return self::FREE_PLAN;
		}

		if ( ! method_exists( $vendor, 'wc_am_product_list' ) ) {
			return self::FREE_PLAN;
		}

		$response = $vendor->wc_am_product_list();

		if ( empty( $response['success'] ) ) {
			Logger::warning( 'Subscription check failed', [
				'vendor'   => self::SUBSCRIPTION_VENDOR_ID,
				'response' => $response,
			] );
			return self::FREE_PLAN;
		}

		$product_ids = $this->extract_product_ids( $response );

		if ( empty( $product_ids ) ) {
			return self::FREE_PLAN;
		}

		return $this->resolve_plan( $product_ids );
	}

	/**
	 * 从 WC AM product_list 响应中提取 product_id 列表
	 *
	 * 适配 Kestrel API Manager 的嵌套响应格式
	 *
	 * @param array $response WC AM API 响应
	 * @return int[]
	 */
	private function extract_product_ids( array $response ): array {
		$product_ids  = [];
		$product_list = $response['data']['product_list'] ?? [];

		// Kestrel 格式: product_list.non_wc_subs_resources
		$resources = $product_list['non_wc_subs_resources']
			?? $product_list['wc_subs_resources']
			?? $product_list;

		if ( ! is_array( $resources ) ) {
			return [];
		}

		foreach ( $resources as $resource ) {
			if ( isset( $resource['product_id'] ) ) {
				$product_ids[] = (int) $resource['product_id'];
			}
		}

		return $product_ids;
	}

	/**
	 * 根据 product_id 列表确定最高订阅等级
	 *
	 * @param int[] $product_ids 已购产品 ID 列表
	 * @return array
	 */
	private function resolve_plan( array $product_ids ): array {
		/**
		 * 允许扩展产品映射
		 *
		 * @param array $plans product_id => plan config
		 */
		$plans = apply_filters( 'wpbridge_subscription_product_plans', self::PRODUCT_PLANS );

		$best_plan = self::FREE_PLAN;

		foreach ( $product_ids as $product_id ) {
			if ( ! isset( $plans[ $product_id ] ) ) {
				continue;
			}

			$plan = $plans[ $product_id ];
			$plan['product_id'] = $product_id;

			if ( $plan['priority'] > $best_plan['priority'] ) {
				$best_plan = $plan;
			}
		}

		$best_plan['status']     = 'active';
		$best_plan['checked_at'] = time();

		return $best_plan;
	}

	/**
	 * 检查是否启用了指定功能
	 *
	 * @param string $feature 功能标识 (bridge_api, bridge_server, license_proxy, priority_support)
	 * @return bool
	 */
	public function is_feature_enabled( string $feature ): bool {
		$subscription = $this->get_subscription();
		return in_array( $feature, $subscription['features'] ?? [], true );
	}

	/**
	 * 获取当前计划名称
	 *
	 * @return string
	 */
	public function get_plan(): string {
		$subscription = $this->get_subscription();
		return $subscription['plan'] ?? 'free';
	}

	/**
	 * 获取当前计划限制
	 *
	 * @return array
	 */
	public function get_limits(): array {
		$subscription = $this->get_subscription();
		return [
			'plugins_limit'   => $subscription['plugins_limit'] ?? 0,
			'daily_downloads' => $subscription['daily_downloads'] ?? 0,
		];
	}

	/**
	 * 是否为付费用户
	 *
	 * @return bool
	 */
	public function is_paid(): bool {
		return $this->get_plan() !== 'free';
	}

	/**
	 * 清除订阅缓存
	 */
	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * 获取订阅供应商 ID
	 *
	 * @return string
	 */
	public static function get_subscription_vendor_id(): string {
		return self::SUBSCRIPTION_VENDOR_ID;
	}
}
