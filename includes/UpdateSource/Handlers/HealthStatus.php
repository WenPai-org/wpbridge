<?php
/**
 * HealthStatus 兼容加载文件
 *
 * @package WPBridge
 */

namespace WPBridge\UpdateSource\Handlers;

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/HandlerInterface.php';
