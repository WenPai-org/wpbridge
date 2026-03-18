<?php
/**
 * 降级策略
 *
 * @package WPBridge
 */

namespace WPBridge\Cache;

use WPBridge\UpdateSource\SourceModel;
use WPBridge\UpdateSource\SourceManager;
use WPBridge\Core\Settings;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 降级策略类
 */
class FallbackStrategy {

	/**
	 * 源不可用时的行为
	 */
	const ON_FAIL_SKIP  = 'skip';   // 跳过，继续下一个源
	const ON_FAIL_WARN  = 'warn';   // 警告，但继续
	const ON_FAIL_BLOCK = 'block';  // 阻止更新检查

	/**
	 * 设置实例
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * 缓存管理器
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * 健康检查器
	 *
	 * @var HealthChecker
	 */
	private HealthChecker $health_checker;

	/**
	 * 最大重试次数
	 *
	 * @var int
	 */
	const MAX_RETRIES = 2;

	/**
	 * 构造函数
	 *
	 * @param Settings $settings 设置实例
	 */
	public function __construct( Settings $settings ) {
		$this->settings       = $settings;
		$this->cache          = new CacheManager();
		$this->health_checker = new HealthChecker();
	}

	/**
	 * 获取可用的源列表（排除不可用的）
	 *
	 * @param SourceModel[] $sources 源列表
	 * @return SourceModel[]
	 */
	public function get_available_sources( array $sources ): array {
		$available = [];

		foreach ( $sources as $source ) {
			// 检查是否在冷却期
			if ( $this->health_checker->is_in_cooldown( $source->id ) ) {
				Logger::debug( '源在冷却期，跳过', [ 'source' => $source->id ] );
				continue;
			}

			// 检查缓存的健康状态
			$status = $this->health_checker->get_status( $source->id );

			if ( null !== $status && ! $status->is_available() ) {
				Logger::debug( '源不可用，跳过', [ 'source' => $source->id ] );
				continue;
			}

			$available[] = $source;
		}

		return $available;
	}

	/**
	 * 执行带降级的操作
	 *
	 * @param SourceModel[] $sources  源列表
	 * @param callable      $callback 操作回调，接收 SourceModel 参数
	 * @param string        $cache_key 缓存键（用于过期缓存兜底）
	 * @return mixed
	 */
	public function execute_with_fallback( array $sources, callable $callback, string $cache_key = '' ) {
		$available = $this->get_available_sources( $sources );

		if ( empty( $available ) ) {
			Logger::warning( '没有可用的更新源' );

			// 尝试使用过期缓存
			if ( ! empty( $cache_key ) ) {
				$stale = $this->cache->get( $cache_key . '_stale' );
				if ( false !== $stale ) {
					Logger::info( '使用过期缓存', [ 'key' => $cache_key ] );
					return $stale;
				}
			}

			return null;
		}

		$last_error = null;

		foreach ( $available as $source ) {
			$retries = 0;

			while ( $retries < self::MAX_RETRIES ) {
				try {
					$result = $callback( $source );

					if ( null !== $result && false !== $result ) {
						// 成功，缓存结果
						if ( ! empty( $cache_key ) ) {
							$this->cache->set( $cache_key, $result );
							$this->cache->set( $cache_key . '_stale', $result, 604800 ); // 7 天
						}

						return $result;
					}

					// 返回 null/false 但没有异常，不重试
					break;

				} catch ( \Exception $e ) {
					$last_error = $e;
					++$retries;

					Logger::warning(
						'操作失败，重试中',
						[
							'source' => $source->id,
							'retry'  => $retries,
							'error'  => $e->getMessage(),
						]
					);

					if ( $retries >= self::MAX_RETRIES ) {
						// 标记源为失败
						$this->health_checker->check( $source, true );
					}
				}
			}
		}

		// 所有源都失败，尝试使用过期缓存
		if ( ! empty( $cache_key ) ) {
			$stale = $this->cache->get( $cache_key . '_stale' );
			if ( false !== $stale ) {
				Logger::info( '所有源失败，使用过期缓存', [ 'key' => $cache_key ] );
				return $stale;
			}
		}

		if ( null !== $last_error ) {
			Logger::error( '所有源都失败', [ 'error' => $last_error->getMessage() ] );
		}

		return null;
	}

	/**
	 * 处理源失败
	 *
	 * @param SourceModel $source 源模型
	 * @param string      $error  错误信息
	 */
	public function handle_source_failure( SourceModel $source, string $error ): void {
		$behavior = $this->settings->get( 'on_source_fail', self::ON_FAIL_SKIP );

		Logger::warning(
			'源失败',
			[
				'source'   => $source->id,
				'error'    => $error,
				'behavior' => $behavior,
			]
		);

		switch ( $behavior ) {
			case self::ON_FAIL_WARN:
				// 添加管理员通知
				$this->add_admin_notice( $source, $error );
				break;

			case self::ON_FAIL_BLOCK:
				// 阻止更新检查（不推荐）
				throw new \RuntimeException(
					sprintf(
						__( '更新源 %1$s 不可用: %2$s', 'wpbridge' ),
						$source->name,
						$error
					)
				);

			case self::ON_FAIL_SKIP:
			default:
				// 静默跳过
				break;
		}
	}

	/**
	 * 添加管理员通知
	 *
	 * @param SourceModel $source 源模型
	 * @param string      $error  错误信息
	 */
	private function add_admin_notice( SourceModel $source, string $error ): void {
		$notices = get_option( 'wpbridge_admin_notices', [] );

		$notices[] = [
			'type'    => 'warning',
			'message' => sprintf(
				__( '更新源 "%1$s" 暂时不可用: %2$s', 'wpbridge' ),
				$source->name,
				$error
			),
			'time'    => time(),
		];

		// 只保留最近 10 条通知
		$notices = array_slice( $notices, -10 );

		update_option( 'wpbridge_admin_notices', $notices );
	}
}
