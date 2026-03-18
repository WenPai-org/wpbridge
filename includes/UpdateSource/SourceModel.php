<?php
/**
 * 更新源数据模型
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource;

use WPBridge\Security\Encryption;
use WPBridge\Security\Validator;
use WPBridge\Core\Logger;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 更新源模型类
 */
class SourceModel {

	/**
	 * 唯一标识
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * 源名称
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * 源类型（见 SourceType 枚举）
	 *
	 * @var string
	 */
	public string $type = '';

	/**
	 * API URL
	 *
	 * @var string
	 */
	public string $api_url = '';

	/**
	 * 插件/主题 slug
	 *
	 * @var string
	 */
	public string $slug = '';

	/**
	 * 项目类型：plugin 或 theme
	 *
	 * @var string
	 */
	public string $item_type = 'plugin';

	/**
	 * 认证令牌
	 *
	 * @var string
	 */
	public string $auth_token = '';

	/**
	 * Git 分支（可选）
	 *
	 * @var string
	 */
	public string $branch = '';

	/**
	 * 是否启用
	 *
	 * @var bool
	 */
	public bool $enabled = true;

	/**
	 * 优先级（数字越小优先级越高）
	 *
	 * @var int
	 */
	public int $priority = 50;

	/**
	 * 是否是预置源
	 *
	 * @var bool
	 */
	public bool $is_preset = false;

	/**
	 * 是否是内联源（项目专属，通过快速设置创建）
	 *
	 * @var bool
	 */
	public bool $is_inline = false;

	/**
	 * 额外元数据
	 *
	 * @var array
	 */
	public array $metadata = [];

	/**
	 * 从数组创建实例
	 *
	 * @param array $data 数据数组
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$model = new self();

		$model->id         = $data['id'] ?? '';
		$model->name       = $data['name'] ?? '';
		$model->type       = $data['type'] ?? SourceType::JSON;
		$model->api_url    = $data['api_url'] ?? '';
		$model->slug       = $data['slug'] ?? '';
		$model->item_type  = $data['item_type'] ?? 'plugin';
		$model->auth_token = $data['auth_token'] ?? '';
		$model->branch     = $data['branch'] ?? '';
		$model->enabled    = (bool) ( $data['enabled'] ?? true );
		$model->priority   = (int) ( $data['priority'] ?? 50 );
		$model->is_preset  = (bool) ( $data['is_preset'] ?? false );
		$model->is_inline  = (bool) ( $data['is_inline'] ?? false );
		$model->metadata   = $data['metadata'] ?? [];

		return $model;
	}

	/**
	 * 转换为数组
	 *
	 * @return array
	 */
	public function to_array(): array {
		return [
			'id'         => $this->id,
			'name'       => $this->name,
			'type'       => $this->type,
			'api_url'    => $this->api_url,
			'slug'       => $this->slug,
			'item_type'  => $this->item_type,
			'auth_token' => $this->auth_token,
			'branch'     => $this->branch,
			'enabled'    => $this->enabled,
			'priority'   => $this->priority,
			'is_preset'  => $this->is_preset,
			'is_inline'  => $this->is_inline,
			'metadata'   => $this->metadata,
		];
	}

	/**
	 * 验证模型
	 *
	 * @return array 错误数组，空数组表示验证通过
	 */
	public function validate(): array {
		$errors = [];

		// 验证类型
		if ( ! SourceType::is_valid( $this->type ) ) {
			$errors['type'] = __( '无效的源类型', 'wpbridge' );
		}

		// 验证 API URL（含 SSRF 防护）
		if ( empty( $this->api_url ) ) {
			$errors['api_url'] = __( 'API URL 不能为空', 'wpbridge' );
		} elseif ( ! Validator::is_valid_url( $this->api_url ) ) {
			$errors['api_url'] = __( '无效的 URL 格式或不允许的地址', 'wpbridge' );
		}

		// 验证项目类型
		if ( ! in_array( $this->item_type, [ 'plugin', 'theme' ], true ) ) {
			$errors['item_type'] = __( '项目类型必须是 plugin 或 theme', 'wpbridge' );
		}

		// 验证优先级
		if ( $this->priority < 0 || $this->priority > 100 ) {
			$errors['priority'] = __( '优先级必须在 0-100 之间', 'wpbridge' );
		}

		return $errors;
	}

	/**
	 * 是否有效
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return empty( $this->validate() );
	}

	/**
	 * 获取处理器实例
	 *
	 * @return Handlers\HandlerInterface|null
	 */
	public function get_handler(): ?Handlers\HandlerInterface {
		$handler_class = SourceType::get_handler_class( $this->type );

		if ( null === $handler_class || ! class_exists( $handler_class ) ) {
			return null;
		}

		return new $handler_class( $this );
	}

	/**
	 * 获取检查 URL
	 *
	 * @return string
	 */
	public function get_check_url(): string {
		$handler = $this->get_handler();
		if ( null === $handler ) {
			return $this->api_url;
		}
		return $handler->get_check_url();
	}

	/**
	 * 获取请求头
	 *
	 * @return array
	 */
	public function get_headers(): array {
		$headers = [];

		if ( ! empty( $this->auth_token ) ) {
			// 解密 auth_token
			$decrypted_token = Encryption::decrypt( $this->auth_token );

			// 如果解密失败且数据看起来是加密的，记录错误并返回空
			if ( empty( $decrypted_token ) ) {
				if ( Encryption::is_encrypted( $this->auth_token ) ) {
					Logger::error( 'Token 解密失败', [ 'source' => $this->id ] );
					return [];
				}
				// 可能是未加密的旧数据，直接使用
				$decrypted_token = $this->auth_token;
			}

			// 根据类型设置不同的认证头
			if ( SourceType::is_git_type( $this->type ) ) {
				$headers['Authorization'] = 'token ' . $decrypted_token;
			} else {
				$headers['X-API-Key'] = $decrypted_token;
			}
		}

		return $headers;
	}
}
