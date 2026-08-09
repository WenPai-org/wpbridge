<?php
/**
 * Minimal WP-CLI symbols used by WPBridge, for static analysis only.
 */

namespace {
	class WP_CLI {
		public static function add_command( string $name, $callable ): void {}
		public static function confirm( string $message, array $assoc_args = [] ): void {}
		public static function error( string $message ): void {}
		public static function log( string $message ): void {}
		public static function success( string $message ): void {}
		public static function warning( string $message ): void {}
	}
}

namespace WP_CLI\Utils {
	function get_flag_value( array $assoc_args, string $key, $default = false ) {}
	function format_items( string $format, array $items, array $fields ): void {}
}
