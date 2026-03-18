<?php
/**
 * 后台更新器
 *
 * @package WPBridge
 */

namespace WPBridge\Performance;

use WPBridge\Core\Settings;
use WPBridge\Core\Logger;
use WPBridge\UpdateSource\SourceManager;
use WPBridge\UpdateSource\SourceModel;
use WPBridge\Cache\CacheManager;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 后台更新器类
 * 使用 WP-Cron 在后台预先更新缓存
 */
class BackgroundUpdater {

	/**
	 * 定时任务钩子名称
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wpbridge_update_sources';

	/**
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * @var SourceManager
	 */
	private SourceManager $source_manager;

	/**
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * 构造函数
	 *
	 * @param Settings $settings 设置实例
	 */
	public function __construct( Settings $settings ) {
		$this->settings       = $settings;
		$this->source_manager = new SourceManager( $settings );
		$this->cache          = new CacheManager();

		$this->init_hooks();
	}

	/**
	 * 初始化钩子
	 */
	private function init_hooks(): void {
		add_action( self::CRON_HOOK, [ $this, 'run_update' ] );
	}

	/**
	 * 调度更新任务
	 */
	public function schedule_update(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
			Logger::info( '已调度后台更新任务' );
		}
	}

	/**
	 * 取消调度
	 */
	public function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			Logger::info( '已取消后台更新任务' );
		}
	}

	/**
	 * 执行更新
	 */
	public function run_update(): void {
		Logger::info( '开始后台更新' );

		$sources = $this->source_manager->get_enabled_sorted();

		if ( empty( $sources ) ) {
			Logger::debug( '没有启用的更新源' );
			return;
		}

		$results = $this->check_multiple_sources( $sources );

		$success_count = 0;
		$fail_count    = 0;

		foreach ( $results as $source_id => $data ) {
			if ( null !== $data ) {
				$this->cache->set(
					'source_data_' . $source_id,
					$data,
					$this->settings->get_cache_ttl()
				);
				++$success_count;
			} else {
				++$fail_count;
			}
		}

		Logger::info(
			'后台更新完成',
			[
				'success' => $success_count,
				'failed'  => $fail_count,
			]
		);
	}

	/**
	 * 并行检查多个更新源
	 *
	 * @param SourceModel[] $sources 源列表
	 * @return array<string, array|null>
	 */
	private function check_multiple_sources( array $sources ): array {
		$requests = [];

		foreach ( $sources as $source ) {
			$requests[ $source->id ] = [
				'url'     => $source->get_check_url(),
				'type'    => \WpOrg\Requests\Requests::GET,
				'headers' => $source->get_headers(),
			];
		}

		$timeout = $this->settings->get_request_timeout();

		$responses = \WpOrg\Requests\Requests::request_multiple(
			$requests,
			[
				'timeout'          => $timeout,
				'connect_timeout'  => 5,
				'follow_redirects' => true,
				'redirects'        => 3,
			]
		);

		$results = [];

		foreach ( $responses as $source_id => $response ) {
			if ( $response instanceof \WpOrg\Requests\Exception ) {
				Logger::warning(
					'请求失败',
					[
						'source' => $source_id,
						'error'  => $response->getMessage(),
					]
				);
				$results[ $source_id ] = null;
				continue;
			}

			if ( ! $response->success ) {
				$results[ $source_id ] = null;
				continue;
			}

			$data                  = json_decode( $response->body, true );
			$results[ $source_id ] = ( json_last_error() === JSON_ERROR_NONE ) ? $data : null;
		}

		return $results;
	}

	/**
	 * 手动触发更新
	 *
	 * @return array 更新结果
	 */
	public function trigger_update(): array {
		$this->run_update();

		return [
			'status' => 'completed',
			'time'   => current_time( 'mysql' ),
		];
	}

	/**
	 * 获取下次更新时间
	 *
	 * @return int|false 时间戳或 false
	 */
	public function get_next_scheduled() {
		return wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * 获取更新状态
	 *
	 * @return array
	 */
	public function get_status(): array {
		$next = $this->get_next_scheduled();

		return [
			'scheduled'      => (bool) $next,
			'next_run'       => $next ? gmdate( 'Y-m-d H:i:s', $next ) : null,
			'next_run_human' => $next ? human_time_diff( time(), $next ) : null,
		];
	}
}
