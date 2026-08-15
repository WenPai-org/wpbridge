<?php
/** WP-CLI eval-file integration test for backup rollback. */

if ( ! defined( 'WPBRIDGE_FILE' ) ) {
	require dirname( __DIR__, 2 ) . '/wpbridge.php';
}

use WPBridge\Core\BackupManager;

$dir  = WP_PLUGIN_DIR . '/wpbridge-r2-fixture';
$file = $dir . '/fixture.php';
wp_mkdir_p( $dir );
file_put_contents( $file, "<?php\n/* Plugin Name: WPBridge R2 Fixture\nVersion: 1.0.0 */\n// old-marker\n" );

$manager = BackupManager::get_instance();
$backup  = $manager->create_backup( 'plugin:wpbridge-r2-fixture/fixture.php', $dir );
if ( ! is_array( $backup ) ) {
	echo "FAIL: create backup\n";
	exit( 1 );
}

file_put_contents( $file, "<?php\n/* Plugin Name: WPBridge R2 Fixture\nVersion: 2.0.0 */\n// new-marker\n" );
file_put_contents( $dir . '/new-only.txt', 'must disappear after directory swap' );
$result = $manager->rollback( 'plugin:wpbridge-r2-fixture/fixture.php', $backup['id'] );
if ( true !== $result || false === strpos( file_get_contents( $file ), 'old-marker' ) || file_exists( $dir . '/new-only.txt' ) ) {
	echo "FAIL: valid rollback did not restore backup\n";
	exit( 1 );
}
echo "PASS: valid rollback restores backup\n";

sleep( 1 );
$backup = $manager->create_backup( 'plugin:wpbridge-r2-fixture/fixture.php', $dir );
file_put_contents( $file, "<?php\n/* Plugin Name: WPBridge R2 Fixture\nVersion: 2.5.0 */\n// preserved-after-failure\n" );
$fail_swap = static function ( $override, $from, $to ) use ( $dir ) {
	if ( false !== strpos( $from, '/.wpbridge-restore-' ) && $to === $dir ) {
		return false;
	}
	return $override;
};
add_filter( 'wpbridge_atomic_rename', $fail_swap, 10, 3 );
$result = $manager->rollback( 'plugin:wpbridge-r2-fixture/fixture.php', $backup['id'] );
remove_filter( 'wpbridge_atomic_rename', $fail_swap, 10 );
if ( ! is_wp_error( $result ) || 'restore_swap_failed' !== $result->get_error_code() || false === strpos( file_get_contents( $file ), 'preserved-after-failure' ) ) {
	echo "FAIL: failed atomic swap did not restore current version\n";
	exit( 1 );
}
echo "PASS: failed atomic swap restores current version\n";

sleep( 1 );
$backup = $manager->create_backup( 'plugin:wpbridge-r2-fixture/fixture.php', $dir );
$path   = $manager->get_backup_dir() . '/' . $backup['filename'];
$zip    = new ZipArchive();
$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( '../escape.php', '<?php // escape' );
$zip->close();
file_put_contents( $file, "<?php\n/* Plugin Name: WPBridge R2 Fixture\nVersion: 3.0.0 */\n// safe-current\n" );
$result = $manager->rollback( 'plugin:wpbridge-r2-fixture/fixture.php', $backup['id'] );
if ( ! is_wp_error( $result ) || false === strpos( file_get_contents( $file ), 'safe-current' ) || file_exists( WP_PLUGIN_DIR . '/escape.php' ) ) {
	echo "FAIL: unsafe rollback changed filesystem\n";
	exit( 1 );
}
echo "PASS: unsafe rollback is rejected before extraction\n";

$manager->delete_backup( 'plugin:wpbridge-r2-fixture/fixture.php', $backup['id'] );
wp_delete_file( $file );
@rmdir( $dir );
