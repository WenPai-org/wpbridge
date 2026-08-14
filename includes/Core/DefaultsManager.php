<?php
/**
 * 默认规则管理
 *
 * 方案 B：项目优先架构 - 默认规则层
 *
 * @package WPBridge
 * @since 0.6.0
 */

namespace WPBridge\Core;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 默认规则管理类
 *
 * 管理全局和类型级别的默认更新源配置
 */
class DefaultsManager {

	/**
	 * 选项名称
	 */
	const OPTION_NAME = 'wpbridge_defaults';

	/**
	 * 作用范围
	 */
	const SCOPE_GLOBAL = 'global';
	const SCOPE_PLUGIN = 'plugin';
	const SCOPE_THEME  = 'theme';
	const SCOPE_CORE   = 'core';

	/**
	 * 缓存的默认规则
	 *
	 * @var array|null
	 */
	private ?array $cached_defaults = null;

	/**
	 * 获取所有默认规则
	 *
	 * @return array
	 */
	public function get_all(): array {
		if ( null === $this->cached_defaults ) {
			$this->cached_defaults = get_option( self::OPTION_NAME, [] );
			$this->ensure_defaults();
		}
		return $this->cached_defaults;
	}

	/**
	 * 获取指定范围的默认规则
	 *
	 * @param string $scope 作用范围
	 * @return array
	 */
	public function get( string $scope ): array {
		$defaults = $this->get_all();
		return $defaults[ $scope ] ?? $this->get_scope_defaults( $scope );
	}

	/**
	 * 设置指定范围的默认规则
	 *
	 * @param string $scope 作用范围
	 * @param array  $rules 规则数据
	 * @return bool
	 */
	public function set( string $scope, array $rules ): bool {
		$defaults                         = $this->get_all();
		$defaults[ $scope ]               = array_merge(
			$this->get_scope_defaults( $scope ),
			$rules
		);
		$defaults[ $scope ]['updated_at'] = current_time( 'mysql' );

		$this->cached_defaults = $defaults;
		return update_option( self::OPTION_NAME, $defaults, false );
	}

	/**
	 * 获取默认源顺序
	 *
	 * @param string $scope 作用范围
	 * @return array 源键列表（按优先级排序）
	 */
	public function get_source_order( string $scope ): array {
		$rules = $this->get( $scope );
		return $rules['source_order'] ?? [ 'wenpai-mirror', 'wporg' ];
	}

	/**
	 * 设置默认源顺序
	 *
	 * @param string $scope        作用范围
	 * @param array  $source_order 源键列表
	 * @return bool
	 */
	public function set_source_order( string $scope, array $source_order ): bool {
		return $this->set( $scope, [ 'source_order' => $source_order ] );
	}

	/**
	 * 获取默认更新源列表
	 *
	 * @param string         $scope           作用范围
	 * @param SourceRegistry $source_registry 源注册表
	 * @return array 源列表（按优先级排序）
	 */
	public function get_default_sources( string $scope, SourceRegistry $source_registry ): array {
		$source_order = $this->get_source_order( $scope );
		$sources      = [];
		$priority     = 100;

		foreach ( $source_order as $source_key ) {
			$source = $source_registry->get( $source_key );
			if ( $source && ! empty( $source['enabled'] ) ) {
				$source['priority'] = $priority;
				$sources[]          = $source;
				$priority          -= 10;
			}
		}

		// 如果没有配置的源可用，回退到 WordPress.org
		if ( empty( $sources ) ) {
			$rules = $this->get( $scope );
			if ( ! empty( $rules['fallback_to_wporg'] ) ) {
				$wporg = $source_registry->get( 'wporg' );
				if ( $wporg && ! empty( $wporg['enabled'] ) ) {
					$wporg['priority'] = 1;
					$sources[]         = $wporg;
				}
			}
		}

		return $sources;
	}

	/**
	 * 是否需要签名验证
	 *
	 * @param string $scope 作用范围
	 * @return bool
	 */
	public function is_signature_required( string $scope ): bool {
		$rules = $this->get( $scope );
		return ! empty( $rules['signature_required'] );
	}

	/**
	 * 是否允许无签名包
	 *
	 * @param string $scope 作用范围
	 * @return bool
	 */
	public function is_unsigned_allowed( string $scope ): bool {
		$rules = $this->get( $scope );
		return ! empty( $rules['allow_unsigned'] );
	}

	/**
	 * 是否允许预发布版本
	 *
	 * @param string $scope 作用范围
	 * @return bool
	 */
	public function is_prerelease_allowed( string $scope ): bool {
		$rules = $this->get( $scope );
		return ! empty( $rules['allow_prerelease'] );
	}

	/**
	 * 获取最低信任阈值
	 *
	 * @param string $scope 作用范围
	 * @return int
	 */
	public function get_trust_floor( string $scope ): int {
		$rules = $this->get( $scope );
		return (int) ( $rules['trust_floor'] ?? 0 );
	}

	/**
	 * 确保默认规则存在
	 */
	private function ensure_defaults(): void {
		$needs_update = false;
		$scopes       = [ self::SCOPE_GLOBAL, self::SCOPE_PLUGIN, self::SCOPE_THEME, self::SCOPE_CORE ];

		foreach ( $scopes as $scope ) {
			if ( ! isset( $this->cached_defaults[ $scope ] ) ) {
				$this->cached_defaults[ $scope ] = $this->get_scope_defaults( $scope );
				$needs_update                    = true;
			}
		}

		if ( $needs_update ) {
			update_option( self::OPTION_NAME, $this->cached_defaults, false );
		}
	}

	/**
	 * 获取范围的默认值
	 *
	 * @param string $scope 作用范围
	 * @return array
	 */
	private function get_scope_defaults( string $scope ): array {
		$base = [
			'source_order'       => [ 'wenpai-mirror', 'wporg' ],
			'signature_required' => false,
			'allow_unsigned'     => true,
			'allow_prerelease'   => false,
			'trust_floor'        => 0,
			'fallback_to_wporg'  => true,
			'policy'             => [],
			'updated_at'         => current_time( 'mysql' ),
		];

		// 根据范围调整默认值
		switch ( $scope ) {
			case self::SCOPE_CORE:
				$base['source_order'] = [ 'wporg' ];
				$base['trust_floor']  = 90;
				break;

			case self::SCOPE_PLUGIN:
			case self::SCOPE_THEME:
				$base['source_order'] = [ 'wenpai-mirror', 'wporg' ];
				break;

			case self::SCOPE_GLOBAL:
			default:
				break;
		}

		return $base;
	}

	/**
	 * 重置为默认值
	 *
	 * @param string|null $scope 作用范围，null 表示全部重置
	 * @return bool
	 */
	public function reset( ?string $scope = null ): bool {
		if ( null === $scope ) {
			$this->cached_defaults = null;
			return delete_option( self::OPTION_NAME );
		}

		$defaults              = $this->get_all();
		$defaults[ $scope ]    = $this->get_scope_defaults( $scope );
		$this->cached_defaults = $defaults;
		return update_option( self::OPTION_NAME, $defaults, false );
	}

	/**
	 * 清除缓存
	 */
	public function clear_cache(): void {
		$this->cached_defaults = null;
	}
}
