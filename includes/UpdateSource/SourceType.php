<?php
/**
 * 源类型枚举
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 更新源类型枚举
 * 所有源类型的统一定义，确保数据模型与处理器一致
 */
class SourceType {

	// === 基础类型（用户自定义源）===

	/**
	 * 标准 JSON API（Plugin Update Checker 格式）
	 */
	const JSON = 'json';

	/**
	 * GitHub Releases
	 */
	const GITHUB = 'github';

	/**
	 * GitLab Releases
	 */
	const GITLAB = 'gitlab';

	/**
	 * Gitee Releases（国内）
	 */
	const GITEE = 'gitee';

	/**
	 * 菲码源库
	 */
	const WENPAI_GIT = 'wenpai_git';

	/**
	 * 直接 ZIP URL
	 */
	const ZIP = 'zip';

	// === 自托管服务器类型（预置源使用）===

	/**
	 * ArkPress（文派自托管，AspireCloud 分叉）
	 */
	const ARKPRESS = 'arkpress';

	/**
	 * AspireCloud
	 */
	const ASPIRECLOUD = 'aspirecloud';

	/**
	 * FAIR Package Manager
	 */
	const FAIR = 'fair';

	/**
	 * Plugin Update Checker 服务器
	 */
	const PUC = 'puc';

	/**
	 * WPBridge Server（商业插件桥接服务）
	 */
	const BRIDGE_SERVER = 'bridge_server';

	/**
	 * 供应商（通过 VendorManager 桥接）
	 */
	const VENDOR = 'vendor';

	// === 类型分组 ===

	/**
	 * Git 平台类型
	 */
	const GIT_TYPES = [
		self::GITHUB,
		self::GITLAB,
		self::GITEE,
		self::WENPAI_GIT,
	];

	/**
	 * 自托管服务器类型
	 */
	const SERVER_TYPES = [
		self::ARKPRESS,
		self::ASPIRECLOUD,
		self::FAIR,
		self::PUC,
		self::BRIDGE_SERVER,
	];

	/**
	 * 所有类型
	 */
	const ALL_TYPES = [
		self::JSON,
		self::GITHUB,
		self::GITLAB,
		self::GITEE,
		self::WENPAI_GIT,
		self::ZIP,
		self::ARKPRESS,
		self::ASPIRECLOUD,
		self::FAIR,
		self::PUC,
		self::BRIDGE_SERVER,
		self::VENDOR,
	];

	/**
	 * 类型标签映射
	 *
	 * @return array
	 */
	public static function get_labels(): array {
		return [
			self::JSON          => __( 'JSON API', 'wpbridge' ),
			self::GITHUB        => __( 'GitHub', 'wpbridge' ),
			self::GITLAB        => __( 'GitLab', 'wpbridge' ),
			self::GITEE         => __( 'Gitee', 'wpbridge' ),
			self::WENPAI_GIT    => __( '菲码源库', 'wpbridge' ),
			self::ZIP           => __( 'ZIP URL', 'wpbridge' ),
			self::ARKPRESS      => __( 'ArkPress', 'wpbridge' ),
			self::ASPIRECLOUD   => __( 'AspireCloud', 'wpbridge' ),
			self::FAIR          => __( 'FAIR', 'wpbridge' ),
			self::PUC           => __( 'PUC Server', 'wpbridge' ),
			self::BRIDGE_SERVER => __( 'Bridge Server', 'wpbridge' ),
			self::VENDOR        => __( '供应商', 'wpbridge' ),
		];
	}

	/**
	 * 获取类型标签
	 *
	 * @param string $type 类型
	 * @return string
	 */
	public static function get_label( string $type ): string {
		$labels = self::get_labels();
		return $labels[ $type ] ?? $type;
	}

	/**
	 * 检查类型是否有效
	 *
	 * @param string $type 类型
	 * @return bool
	 */
	public static function is_valid( string $type ): bool {
		return in_array( $type, self::ALL_TYPES, true );
	}

	/**
	 * 检查是否是 Git 类型
	 *
	 * @param string $type 类型
	 * @return bool
	 */
	public static function is_git_type( string $type ): bool {
		return in_array( $type, self::GIT_TYPES, true );
	}

	/**
	 * 检查是否是服务器类型
	 *
	 * @param string $type 类型
	 * @return bool
	 */
	public static function is_server_type( string $type ): bool {
		return in_array( $type, self::SERVER_TYPES, true );
	}

	/**
	 * 获取处理器类名
	 *
	 * @param string $type 类型
	 * @return string|null
	 */
	public static function get_handler_class( string $type ): ?string {
		$handlers = [
			self::JSON          => 'WPBridge\\UpdateSource\\Handlers\\JsonHandler',
			self::GITHUB        => 'WPBridge\\UpdateSource\\Handlers\\GitHubHandler',
			self::GITLAB        => 'WPBridge\\UpdateSource\\Handlers\\GitLabHandler',
			self::GITEE         => 'WPBridge\\UpdateSource\\Handlers\\GiteeHandler',
			self::WENPAI_GIT    => 'WPBridge\\UpdateSource\\Handlers\\WenPaiGitHandler',
			self::ZIP           => 'WPBridge\\UpdateSource\\Handlers\\ZipHandler',
			self::ARKPRESS      => 'WPBridge\\UpdateSource\\Handlers\\ArkPressHandler',
			self::ASPIRECLOUD   => 'WPBridge\\UpdateSource\\Handlers\\AspireCloudHandler',
			self::FAIR          => 'WPBridge\\UpdateSource\\Handlers\\FairHandler',
			self::PUC           => 'WPBridge\\UpdateSource\\Handlers\\PUCHandler',
			self::BRIDGE_SERVER => 'WPBridge\\UpdateSource\\Handlers\\BridgeServerHandler',
			self::VENDOR        => 'WPBridge\\UpdateSource\\Handlers\\VendorHandler',
		];

		return $handlers[ $type ] ?? null;
	}
}
