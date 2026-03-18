<?php
/**
 * 源注册表管理
 *
 * 方案 B：项目优先架构 - 源注册表层
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
 * 源注册表管理类
 *
 * 管理所有可用的更新源（WP.org、FAIR、自定义等）
 */
class SourceRegistry {

	/**
	 * 选项名称
	 */
	const OPTION_NAME = 'wpbridge_source_registry';

	/**
	 * 源类型枚举
	 */
	const TYPE_WPORG    = 'wporg';
	const TYPE_FAIR     = 'fair';
	const TYPE_CUSTOM   = 'custom';
	const TYPE_GIT      = 'git';
	const TYPE_MIRROR   = 'mirror';
	const TYPE_JSON     = 'json';
	const TYPE_ARKPRESS = 'arkpress';
	const TYPE_VENDOR   = 'vendor';

	/**
	 * 签名方案
	 */
	const SIGNATURE_NONE    = 'none';
	const SIGNATURE_ED25519 = 'ed25519';

	/**
	 * 认证类型
	 */
	const AUTH_NONE   = 'none';
	const AUTH_BASIC  = 'basic';
	const AUTH_BEARER = 'bearer';
	const AUTH_TOKEN  = 'token';

	/**
	 * 缓存的源列表
	 *
	 * @var array|null
	 */
	private ?array $cached_sources = null;

	/**
	 * 获取所有源
	 *
	 * @return array
	 */
	public function get_all(): array {
		if ( null === $this->cached_sources ) {
			$this->cached_sources = get_option( self::OPTION_NAME, [] );
			$this->ensure_preset_sources();
		}
		return $this->cached_sources;
	}

	/**
	 * 获取启用的源
	 *
	 * @return array
	 */
	public function get_enabled(): array {
		return array_filter( $this->get_all(), fn( $s ) => ! empty( $s['enabled'] ) );
	}

	/**
	 * 按类型获取源
	 *
	 * @param string $type 源类型
	 * @return array
	 */
	public function get_by_type( string $type ): array {
		return array_filter( $this->get_all(), fn( $s ) => ( $s['type'] ?? '' ) === $type );
	}

	/**
	 * 获取单个源
	 *
	 * @param string $source_key 源唯一键
	 * @return array|null
	 */
	public function get( string $source_key ): ?array {
		foreach ( $this->get_all() as $source ) {
			if ( ( $source['source_key'] ?? '' ) === $source_key ) {
				return $source;
			}
		}
		return null;
	}

	/**
	 * 通过 DID 获取源
	 *
	 * @param string $did 源 DID
	 * @return array|null
	 */
	public function get_by_did( string $did ): ?array {
		foreach ( $this->get_all() as $source ) {
			if ( ( $source['did'] ?? '' ) === $did ) {
				return $source;
			}
		}
		return null;
	}

	/**
	 * 添加源
	 *
	 * @param array $source 源数据
	 * @return string|false 成功返回 source_key，失败返回 false
	 */
	public function add( array $source ) {
		$sources = $this->get_all();

		if ( empty( $source['source_key'] ) ) {
			$source['source_key'] = 'src_' . wp_generate_uuid4();
		}

		if ( $this->get( $source['source_key'] ) ) {
			return false;
		}

		$source               = $this->normalize_source( $source );
		$sources[]            = $source;
		$this->cached_sources = $sources;

		if ( update_option( self::OPTION_NAME, $sources, false ) ) {
			return $source['source_key'];
		}
		return false;
	}

	/**
	 * 更新源
	 *
	 * @param string $source_key 源唯一键
	 * @param array  $data       更新数据
	 * @return bool
	 */
	public function update( string $source_key, array $data ): bool {
		$sources = $this->get_all();

		foreach ( $sources as $index => $source ) {
			if ( ( $source['source_key'] ?? '' ) === $source_key ) {
				unset( $data['source_key'] );
				$sources[ $index ]               = array_merge( $source, $data );
				$sources[ $index ]['updated_at'] = current_time( 'mysql' );
				$this->cached_sources            = $sources;
				return update_option( self::OPTION_NAME, $sources, false );
			}
		}
		return false;
	}

	/**
	 * 删除源
	 *
	 * @param string $source_key 源唯一键
	 * @return bool
	 */
	public function delete( string $source_key ): bool {
		$sources = $this->get_all();

		foreach ( $sources as $index => $source ) {
			if ( ( $source['source_key'] ?? '' ) === $source_key ) {
				if ( ! empty( $source['is_preset'] ) ) {
					return false;
				}
				unset( $sources[ $index ] );
				$sources              = array_values( $sources );
				$this->cached_sources = $sources;
				return update_option( self::OPTION_NAME, $sources, false );
			}
		}
		return false;
	}

	/**
	 * 启用/禁用源
	 *
	 * @param string $source_key 源唯一键
	 * @param bool   $enabled    是否启用
	 * @return bool
	 */
	public function toggle( string $source_key, bool $enabled ): bool {
		return $this->update( $source_key, [ 'enabled' => $enabled ] );
	}

	/**
	 * 规范化源数据
	 *
	 * @param array $source 源数据
	 * @return array
	 */
	private function normalize_source( array $source ): array {
		return wp_parse_args(
			$source,
			[
				'source_key'         => '',
				'name'               => '',
				'type'               => self::TYPE_CUSTOM,
				'base_url'           => '',
				'api_url'            => '',
				'did'                => '',
				'public_key'         => '',
				'signature_scheme'   => self::SIGNATURE_NONE,
				'signature_required' => false,
				'trust_level'        => 50,
				'enabled'            => true,
				'default_priority'   => 50,
				'auth_type'          => self::AUTH_NONE,
				'auth_secret_ref'    => '',
				'headers'            => [],
				'capabilities'       => [],
				'rate_limit'         => [],
				'cache_ttl'          => 43200,
				'is_preset'          => false,
				'last_checked_at'    => null,
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			]
		);
	}

	/**
	 * 确保预置源存在
	 */
	private function ensure_preset_sources(): void {
		$existing_keys = array_column( $this->cached_sources, 'source_key' );
		$needs_update  = false;

		foreach ( $this->get_preset_sources() as $preset ) {
			if ( ! in_array( $preset['source_key'], $existing_keys, true ) ) {
				$this->cached_sources[] = $preset;
				$needs_update           = true;
			}
		}

		if ( $needs_update ) {
			update_option( self::OPTION_NAME, $this->cached_sources, false );
		}
	}

	/**
	 * 获取预置源列表
	 *
	 * @return array
	 */
	private function get_preset_sources(): array {
		$now = current_time( 'mysql' );
		return [
			[
				'source_key'         => 'wporg',
				'name'               => 'WordPress.org',
				'type'               => self::TYPE_WPORG,
				'base_url'           => 'https://wordpress.org',
				'api_url'            => 'https://api.wordpress.org',
				'did'                => '',
				'public_key'         => '',
				'signature_scheme'   => self::SIGNATURE_NONE,
				'signature_required' => false,
				'trust_level'        => 100,
				'enabled'            => true,
				'default_priority'   => 100,
				'auth_type'          => self::AUTH_NONE,
				'auth_secret_ref'    => '',
				'headers'            => [],
				'capabilities'       => [ 'plugins', 'themes', 'core', 'translations' ],
				'rate_limit'         => [],
				'cache_ttl'          => 43200,
				'is_preset'          => true,
				'created_at'         => $now,
				'updated_at'         => $now,
			],
			[
				'source_key'         => 'wenpai-mirror',
				'name'               => '文派开源更新源',
				'type'               => self::TYPE_JSON,
				'base_url'           => 'https://wenpai.org',
				'api_url'            => 'https://updates.wenpai.net/api/v1/plugins/{slug}/info',
				'did'                => '',
				'public_key'         => '',
				'signature_scheme'   => self::SIGNATURE_NONE,
				'signature_required' => false,
				'trust_level'        => 90,
				'enabled'            => true,
				'default_priority'   => 10,
				'auth_type'          => self::AUTH_NONE,
				'auth_secret_ref'    => '',
				'headers'            => [],
				'capabilities'       => [ 'plugins', 'themes' ],
				'rate_limit'         => [],
				'cache_ttl'          => 43200,
				'is_preset'          => true,
				'created_at'         => $now,
				'updated_at'         => $now,
			],
			[
				'source_key'         => 'fair-aspirecloud',
				'name'               => 'FAIR AspireCloud',
				'type'               => self::TYPE_FAIR,
				'base_url'           => 'https://aspirepress.org',
				'api_url'            => 'https://api.aspirecloud.io/v1',
				'did'                => '',
				'public_key'         => '',
				'signature_scheme'   => self::SIGNATURE_ED25519,
				'signature_required' => false,
				'trust_level'        => 85,
				'enabled'            => false,
				'default_priority'   => 20,
				'auth_type'          => self::AUTH_NONE,
				'auth_secret_ref'    => '',
				'headers'            => [],
				'capabilities'       => [ 'plugins', 'themes', 'fair_did' ],
				'rate_limit'         => [],
				'cache_ttl'          => 43200,
				'is_preset'          => true,
				'created_at'         => $now,
				'updated_at'         => $now,
			],
		];
	}

	/**
	 * 获取源类型标签
	 *
	 * @return array
	 */
	public static function get_type_labels(): array {
		return [
			self::TYPE_WPORG    => 'WordPress.org',
			self::TYPE_FAIR     => 'FAIR',
			self::TYPE_CUSTOM   => '自定义',
			self::TYPE_GIT      => 'Git 仓库',
			self::TYPE_MIRROR   => '镜像',
			self::TYPE_JSON     => 'JSON API',
			self::TYPE_ARKPRESS => 'ArkPress',
			self::TYPE_VENDOR   => '供应商',
		];
	}

	/**
	 * 清除缓存
	 */
	public function clear_cache(): void {
		$this->cached_sources = null;
	}
}
