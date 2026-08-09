<?php
/**
 * Repository metadata and lifecycle contract checks.
 */

declare(strict_types=1);

$root     = dirname( __DIR__ );
$failures = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	echo ( $condition ? '[PASS] ' : '[FAIL] ' ) . $message . "\n";
	if ( ! $condition ) {
		$failures++;
	}
};

$main = file_get_contents( $root . '/wpbridge.php' );
preg_match( '/^ \\* Version:\\s*(\\S+)/m', $main, $header_match );
preg_match( "/define\\( 'WPBRIDGE_VERSION', '([^']+)' \\)/", $main, $constant_match );
$package = json_decode( file_get_contents( $root . '/package.json' ), true );

$assert( ! empty( $header_match[1] ), 'Plugin header version is present' );
$assert( ( $header_match[1] ?? null ) === ( $constant_match[1] ?? null ), 'Plugin header and runtime constant versions match' );
$assert( ( $header_match[1] ?? null ) === ( $package['version'] ?? null ), 'Plugin and npm metadata versions match' );
$assert( 'GPL-2.0-or-later' === ( $package['license'] ?? null ), 'npm metadata uses the plugin GPL license' );

$uninstall = file_get_contents( $root . '/uninstall.php' );
$assert( false !== strpos( $uninstall, 'function wpbridge_uninstall_site()' ) && false !== strpos( $uninstall, "'wpbridge_'" ), 'Uninstall removes all current-site WPBridge options, including dynamic keys' );
$assert( false !== strpos( $uninstall, 'get_sites(' ) && false !== strpos( $uninstall, 'switch_to_blog(' ), 'Uninstall iterates all sites on multisite' );
$assert( false !== strpos( $uninstall, "'_site_transient_wpbridge_'" ), 'Uninstall removes site transient data' );
$assert( false !== strpos( $uninstall, "wp_clear_scheduled_hook( 'wpbridge_update_sources' )" ), 'Uninstall clears the scheduled source update hook' );

$phpcs = file_get_contents( $root . '/phpcs.xml.dist' );
$assert( false !== strpos( $phpcs, 'name="testVersion" value="7.4-"' ), 'PHPCS compatibility target matches Requires PHP 7.4' );
$assert( false !== strpos( $phpcs, 'name="minimum_wp_version" value="5.9"' ), 'PHPCS WordPress target matches Requires at least 5.9' );

$updater = file_get_contents( $root . '/includes/class-wenpai-updater.php' );
$assert( false !== strpos( $updater, 'version_compare( $new_version, $this->version' ), 'Self-updater rejects downgrade and same-version responses' );
$assert( false !== strpos( $updater, 'wp_parse_url( $package, PHP_URL_SCHEME )' ), 'Self-updater rejects non-HTTPS packages' );

$admin = file_get_contents( $root . '/includes/Admin/AdminPage.php' );
$assert( false !== strpos( $admin, 'Encryption::encrypt( $token )' ), 'Registry credentials are encrypted before option storage' );

exit( $failures > 0 ? 1 : 0 );
