<?php
/** WP-CLI eval-file integration test for backup rollback. */

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
$result = $manager->rollback( 'plugin:wpbridge-r2-fixture/fixture.php', $backup['id'] );
if ( true !== $result || false === strpos( file_get_contents( $file ), 'old-marker' ) ) {
	echo "FAIL: valid rollback did not restore backup\n";
	exit( 1 );
}
echo "PASS: valid rollback restores backup\n";

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
