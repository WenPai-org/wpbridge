<?php
/**
 * 自动加载器
 *
 * @package WPBridge
 */

namespace WPBridge\Core;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-4 风格自动加载器
 */
class Loader {

	/**
	 * 命名空间前缀
	 *
	 * @var string
	 */
	private static string $namespace_prefix = 'WPBridge\\';

	/**
	 * 基础目录
	 *
	 * @var string
	 */
	private static string $base_dir = '';

	/**
	 * 注册自动加载器
	 */
	public static function register(): void {
		self::$base_dir = WPBRIDGE_PATH . 'includes/';
		spl_autoload_register( [ __CLASS__, 'autoload' ] );
	}

	/**
	 * 自动加载类
	 *
	 * @param string $class 完整类名
	 */
	public static function autoload( string $class ): void {
		// 检查是否是我们的命名空间
		$len = strlen( self::$namespace_prefix );
		if ( strncmp( self::$namespace_prefix, $class, $len ) !== 0 ) {
			return;
		}

		// 获取相对类名
		$relative_class = substr( $class, $len );

		// 转换为文件路径
		$file = self::$base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		// 如果文件存在则加载
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

// 立即注册自动加载器
Loader::register();
