<?php
/**
 * Test-only libsodium polyfill.
 *
 * Loaded by tests/run-tests.sh (auto_prepend_file) before every PHP test script.
 * Does nothing when ext-sodium is available; otherwise loads
 * paragonie/sodium_compat from vendor/ (require-dev) so CI runners whose PHP
 * lacks ext-sodium run the signature / Hub-Spoke tests instead of fataling.
 * Product code does not load this file.
 *
 * @package WPBridge
 */

if ( ! extension_loaded( 'sodium' ) ) {
	$wpbridge_sodium_compat = dirname( __DIR__ ) . '/vendor/paragonie/sodium_compat/autoload.php';
	if ( is_readable( $wpbridge_sodium_compat ) ) {
		require_once $wpbridge_sodium_compat;
	} else {
		fwrite( STDERR, "ext-sodium is not loaded and vendor/paragonie/sodium_compat is missing; run composer install.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
	unset( $wpbridge_sodium_compat );
}
