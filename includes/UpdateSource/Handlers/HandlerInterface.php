<?php
/**
 * 处理器接口
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource\Handlers;

use WPBridge\UpdateSource\SourceModel;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 更新源处理器接口
 */
interface HandlerInterface {

	/**
	 * 构造函数
	 *
	 * @param SourceModel $source 源模型
	 */
	public function __construct( SourceModel $source );

	/**
	 * 获取能力列表
	 *
	 * @return array
	 */
	public function get_capabilities(): array;

	/**
	 * 获取检查 URL
	 *
	 * @return string
	 */
	public function get_check_url(): string;

	/**
	 * 获取请求头
	 *
	 * @return array
	 */
	public function get_headers(): array;

	/**
	 * 检查更新
	 *
	 * @param string $slug    插件/主题 slug
	 * @param string $version 当前版本
	 * @return UpdateInfo|null
	 */
	public function check_update( string $slug, string $version ): ?UpdateInfo;

	/**
	 * 获取项目信息
	 *
	 * @param string $slug 插件/主题 slug
	 * @return array|null
	 */
	public function get_info( string $slug ): ?array;

	/**
	 * 验证认证信息
	 *
	 * @return bool
	 */
	public function validate_auth(): bool;

	/**
	 * 测试连通性
	 *
	 * @return HealthStatus
	 */
	public function test_connection(): HealthStatus;
}

/**
 * 更新信息类
 */
class UpdateInfo {

	/**
	 * 插件/主题 slug
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * 新版本号
	 *
	 * @var string
	 */
	public string $version = '';

	/**
	 * 下载 URL
	 *
	 * @var string
	 */
	public string $download_url = '';

	/**
	 * 详情 URL
	 *
	 * @var string
	 */
	public string $details_url = '';

	/**
	 * 最低 WordPress 版本
	 *
	 * @var string
	 */
	public string $requires = '';

	/**
	 * 测试通过的 WordPress 版本
	 *
	 * @var string
	 */
	public string $tested = '';

	/**
	 * 最低 PHP 版本
	 *
	 * @var string
	 */
	public string $requires_php = '';

	/**
	 * 最后更新时间
	 *
	 * @var string
	 */
	public string $last_updated = '';

	/**
	 * 图标
	 *
	 * @var array
	 */
	public array $icons = [];

	/**
	 * 横幅
	 *
	 * @var array
	 */
	public array $banners = [];

	/**
	 * 更新日志
	 *
	 * @var string
	 */
	public string $changelog = '';

	/**
	 * 描述
	 *
	 * @var string
	 */
	public string $description = '';

	/**
	 * 从数组创建
	 *
	 * @param array $data 数据
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$info = new self();

		$info->slug         = $data['slug'] ?? '';
		$info->version      = $data['version'] ?? '';
		$info->download_url = $data['download_url'] ?? $data['package'] ?? $data['download_link'] ?? '';
		$info->details_url  = $data['details_url'] ?? $data['url'] ?? '';
		$info->requires     = $data['requires'] ?? '';
		$info->tested       = $data['tested'] ?? '';
		$info->requires_php = $data['requires_php'] ?? '';
		$info->last_updated = $data['last_updated'] ?? '';
		$info->icons        = $data['icons'] ?? [];
		$info->banners      = $data['banners'] ?? [];
		$info->changelog    = $data['changelog'] ?? $data['sections']['changelog'] ?? '';
		$info->description  = $data['description'] ?? $data['sections']['description'] ?? '';

		return $info;
	}

	/**
	 * 转换为 WordPress 更新对象格式
	 *
	 * @return object
	 */
	public function to_wp_update_object(): object {
		return (object) [
			'slug'         => $this->slug,
			'new_version'  => $this->version,
			'package'      => $this->download_url,
			'url'          => $this->details_url,
			'requires'     => $this->requires,
			'tested'       => $this->tested,
			'requires_php' => $this->requires_php,
			'icons'        => $this->icons,
			'banners'      => $this->banners,
		];
	}

	/**
	 * 转换为 plugins_api 响应格式
	 *
	 * @param string $name 插件名称
	 * @return object
	 */
	public function to_plugins_api_response( string $name = '' ): object {
		return (object) [
			'name'          => $name ?: $this->slug,
			'slug'          => $this->slug,
			'version'       => $this->version,
			'download_link' => $this->download_url,
			'requires'      => $this->requires,
			'tested'        => $this->tested,
			'requires_php'  => $this->requires_php,
			'last_updated'  => $this->last_updated,
			'sections'      => [
				'description' => $this->description,
				'changelog'   => $this->changelog,
			],
			'icons'         => $this->icons,
			'banners'       => $this->banners,
		];
	}
}

/**
 * 健康状态类
 */
class HealthStatus {

	const STATUS_HEALTHY  = 'healthy';
	const STATUS_DEGRADED = 'degraded';
	const STATUS_FAILED   = 'failed';

	/**
	 * 状态
	 *
	 * @var string
	 */
	public string $status = self::STATUS_FAILED;

	/**
	 * 响应时间（毫秒）
	 *
	 * @var int
	 */
	public int $response_time = 0;

	/**
	 * 错误信息
	 *
	 * @var string
	 */
	public string $error = '';

	/**
	 * 检查时间
	 *
	 * @var int
	 */
	public int $checked_at = 0;

	/**
	 * 创建健康状态
	 *
	 * @param int $response_time 响应时间
	 * @return self
	 */
	public static function healthy( int $response_time ): self {
		$status                = new self();
		$status->status        = self::STATUS_HEALTHY;
		$status->response_time = $response_time;
		$status->checked_at    = time();
		return $status;
	}

	/**
	 * 创建降级状态
	 *
	 * @param int    $response_time 响应时间
	 * @param string $reason        原因
	 * @return self
	 */
	public static function degraded( int $response_time, string $reason = '' ): self {
		$status                = new self();
		$status->status        = self::STATUS_DEGRADED;
		$status->response_time = $response_time;
		$status->error         = $reason;
		$status->checked_at    = time();
		return $status;
	}

	/**
	 * 创建失败状态
	 *
	 * @param string $error 错误信息
	 * @return self
	 */
	public static function failed( string $error ): self {
		$status             = new self();
		$status->status     = self::STATUS_FAILED;
		$status->error      = $error;
		$status->checked_at = time();
		return $status;
	}

	/**
	 * 是否健康
	 *
	 * @return bool
	 */
	public function is_healthy(): bool {
		return $this->status === self::STATUS_HEALTHY;
	}

	/**
	 * 是否可用（健康或降级）
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->status !== self::STATUS_FAILED;
	}
}
