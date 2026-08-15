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
		++$failures;
	}
};

$main         = (string) file_get_contents( $root . '/wpbridge.php' );
$readme       = (string) file_get_contents( $root . '/readme.txt' );
$pot          = (string) file_get_contents( $root . '/languages/wpbridge.pot' );
$package      = json_decode( (string) file_get_contents( $root . '/package.json' ), true );
$package_lock = json_decode( (string) file_get_contents( $root . '/package-lock.json' ), true );

preg_match( '/^ \* Version:\s*(\S+)/m', $main, $header_match );
preg_match( "/define\( 'WPBRIDGE_VERSION', '([^']+)' \)/", $main, $constant_match );
preg_match( '/^ \* Requires at least:\s*(\S+)/m', $main, $requires_wp_match );
preg_match( '/^ \* Requires PHP:\s*(\S+)/m', $main, $requires_php_match );
preg_match( '/^ \* Update URI:\s*(\S+)/m', $main, $update_uri_match );
preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $stable_match );
preg_match( '/^Requires at least:\s*(\S+)/m', $readme, $readme_wp_match );
preg_match( '/^Requires PHP:\s*(\S+)/m', $readme, $readme_php_match );
preg_match( '/^Tested up to:\s*(\S+)/m', $readme, $tested_match );

$version = $header_match[1] ?? null;
$assert( null !== $version, 'Plugin header version is present' );
$assert( $version === ( $constant_match[1] ?? null ), 'Plugin header and runtime constant versions match' );
$assert( $version === ( $package['version'] ?? null ), 'Plugin and npm metadata versions match' );
$assert( $version === ( $package_lock['version'] ?? null ), 'Plugin and npm lockfile versions match' );
$assert( $version === ( $package_lock['packages']['']['version'] ?? null ), 'Plugin and npm root package lock versions match' );
$assert( $version === ( $stable_match[1] ?? null ), 'Plugin header and readme stable tag match' );
$assert( false !== strpos( $readme, '= ' . $version . ' =' ), 'Readme contains a changelog section for the candidate version' );
$assert( false !== strpos( $pot, 'Project-Id-Version: WPBridge ' . $version ), 'Translation template version matches the candidate' );
$assert( 'GPL-2.0-or-later' === ( $package['license'] ?? null ), 'npm metadata uses the plugin GPL license' );

$assert( '5.9' === ( $requires_wp_match[1] ?? null ), 'Plugin minimum WordPress version is 5.9' );
$assert( ( $requires_wp_match[1] ?? null ) === ( $readme_wp_match[1] ?? null ), 'Plugin and readme minimum WordPress versions match' );
$assert( '7.4' === ( $requires_php_match[1] ?? null ), 'Plugin minimum PHP version is 7.4' );
$assert( ( $requires_php_match[1] ?? null ) === ( $readme_php_match[1] ?? null ), 'Plugin and readme minimum PHP versions match' );
$assert( '7.0' === ( $tested_match[1] ?? null ), 'Readme tested WordPress version is 7.0' );
$assert( 'https://updates.wenpai.net' === ( $update_uri_match[1] ?? null ), 'Private Update URI is exact and HTTPS' );

$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
$assert( false !== strpos( $uninstall, 'function wpbridge_uninstall_site()' ) && false !== strpos( $uninstall, "'wpbridge_'" ), 'Uninstall removes all current-site WPBridge options, including dynamic keys' );
$assert( false !== strpos( $uninstall, 'get_sites(' ) && false !== strpos( $uninstall, 'switch_to_blog(' ), 'Uninstall iterates all sites on multisite' );
$assert( false !== strpos( $uninstall, "'_site_transient_wpbridge_'" ), 'Uninstall removes site transient data' );
$assert( false !== strpos( $uninstall, "wp_clear_scheduled_hook( 'wpbridge_update_sources' )" ), 'Uninstall clears the scheduled source update hook' );

$phpcs         = (string) file_get_contents( $root . '/phpcs.xml.dist' );
$release_phpcs = (string) file_get_contents( $root . '/phpcs.release.xml.dist' );
$assert( false !== strpos( $phpcs, 'name="testVersion" value="7.4-"' ), 'Full PHPCS compatibility target matches Requires PHP 7.4' );
$assert( false !== strpos( $phpcs, 'name="minimum_wp_version" value="5.9"' ), 'Full PHPCS WordPress target matches Requires at least 5.9' );
$assert( false !== strpos( $release_phpcs, 'WordPress.Security.ValidatedSanitizedInput' ), 'Release PHPCS profile blocks input-boundary violations' );
$assert( false !== strpos( $release_phpcs, 'WordPress.DB.PreparedSQL' ), 'Release PHPCS profile blocks unprepared SQL' );
$assert( false !== strpos( $release_phpcs, 'PHPCompatibilityWP' ) && false !== strpos( $release_phpcs, 'value="7.4-"' ), 'Release PHPCS profile blocks PHP 7.4 incompatibility' );

$plugin_check = (string) file_get_contents( $root . '/tests/plugin-check/run-profile.sh' );
$assert( false !== strpos( $plugin_check, 'private)' ) && false !== strpos( $plugin_check, 'wordpress-org)' ), 'Plugin Check has explicit private and WordPress.org profiles' );
$assert( false !== strpos( $plugin_check, '--ignore-codes=plugin_updater_detected' ), 'Private profile exempts only the private updater policy code' );
$assert( false === strpos( $plugin_check, '--ignore-warnings' ) && false === strpos( $plugin_check, '--ignore-errors' ), 'Plugin Check profiles do not blanket-ignore warnings or errors' );

$release_builder = (string) file_get_contents( $root . '/tests/release/build-candidate.sh' );
$assert( false !== strpos( $release_builder, 'git archive HEAD' ), 'Release builder packages committed HEAD rather than workspace debris' );
$assert( false !== strpos( $release_builder, 'touch -h -t 198001010000' ) && false !== strpos( $release_builder, 'zip -X' ), 'Release builder normalizes ZIP timestamps and metadata' );
$assert( false !== strpos( $release_builder, 'manifest.json' ), 'Release builder emits a file manifest' );

$release_workflow = (string) file_get_contents( $root . '/.forgejo/workflows/release.yml' );
$assert( false !== strpos( $release_workflow, '只构建并发布 Release；目标站部署必须走独立审批和独立流程' ), 'Release workflow documents the separate deployment approval boundary' );
$assert( false === strpos( $release_workflow, 'DEPLOY_HOST' ) && false === strpos( $release_workflow, 'DEPLOY_SSH_KEY' ), 'Release workflow cannot consume deployment host or SSH credentials' );
$assert( false === strpos( $release_workflow, 'ssh-keyscan' ) && false === strpos( $release_workflow, 'post-release composer repair' ), 'Release workflow cannot perform target-site repair or SSH deployment' );

$updater = (string) file_get_contents( $root . '/includes/class-wenpai-updater.php' );
$assert( false !== strpos( $updater, 'version_compare( $new_version, $this->version' ), 'Self-updater rejects downgrade and same-version responses' );
$assert( false !== strpos( $updater, 'wp_parse_url( $package, PHP_URL_SCHEME )' ), 'Self-updater rejects non-HTTPS packages' );

$admin = (string) file_get_contents( $root . '/includes/Admin/AdminPage.php' );
$assert( false !== strpos( $admin, 'Encryption::encrypt( $token )' ), 'Registry credentials are encrypted before option storage' );
$assert( false !== strpos( $admin, "'update_private_key', 'update_site_url', 'update_product_slug', 'update_bridge_url'" ), 'Changing source type clears stored update authorization metadata' );

$integrity = (string) file_get_contents( $root . '/includes/Security/PackageIntegrityVerifier.php' );
$assert( false !== strpos( $integrity, "hash_file( 'sha256'" ) && false !== strpos( $integrity, 'hash_equals(' ), 'Advertised SHA-256 digests are verified before installation' );
$assert( false !== strpos( $integrity, "const SIGNATURE_SCHEME = 'ed25519'" ) && false !== strpos( $integrity, 'sodium_crypto_sign_verify_detached(' ) && false !== strpos( $integrity, 'WENPAI-RELEASE-SIGNATURE-V1' ) && false !== strpos( $integrity, "'signed_at:'" ), 'Detached Ed25519 verification uses the frozen release canonical contract including signing time' );
$assert( false !== strpos( $integrity, "[ 'active', 'verify-only' ]" ) && false !== strpos( $integrity, 'wpbridge_signature_unknown_key' ), 'Artifact keyring supports verify-only rotation and rejects unknown key ids' );

$resolver = (string) file_get_contents( $root . '/includes/UpdateSource/SourceResolver.php' );
$assert( false !== strpos( $resolver, "source['artifact_public_keys']" ), 'Source runtime carries its locally configured artifact public-key ring' );

$bridge_client = (string) file_get_contents( $root . '/includes/Commercial/BridgeClient.php' );
$assert( false !== strpos( $bridge_client, "'/api/v1/capabilities'" ) && false !== strpos( $bridge_client, "'legacy'      => true" ), 'Bridge client discovers capabilities and preserves a legacy route profile' );
$assert( false !== strpos( $bridge_client, 'Authorization' ) && false !== strpos( $bridge_client, 'Bearer ' ), 'Bridge client can attach short-lived bearer grants to protected metadata and package requests' );

exit( $failures > 0 ? 1 : 0 );
