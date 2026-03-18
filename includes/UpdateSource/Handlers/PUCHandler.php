<?php
/**
 * PUC 处理器
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource\Handlers;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Update Checker 处理器
 * 复用 JSON 处理逻辑
 */
class PUCHandler extends JsonHandler {
}
