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

// T4: admin template accessibility contracts (label association, external link rel).
// First-party hosts keep the referrer (rel="noopener" only) so source statistics survive; every other
// target="_blank" link, including dynamic PHP-built hrefs, uses rel="noopener noreferrer".
// Allowlist decided by the WPBridge lead bot on 2026-10-19. "*.example.com" matches subdomains only.
const WPBRIDGE_T4_FIRST_PARTY_HOSTS = array( 'wpcy.com', 'mall.weixiaoduo.com', '*.weixiaoduo.com', '*.wenpai.net' );
const T4_PHP_MARKER                 = '__WPBRIDGE_PHP__';
$t4_strip_php = static function ( string $source ): string {
	// Replace PHP blocks with a marker plus their newlines, so tag regexes see plain HTML, dynamic attributes stay detectable and line numbers stay stable.
	return (string) preg_replace_callback(
		'/<\?php.*?(\?>|\z)/s',
		static function ( array $m ): string {
			return T4_PHP_MARKER . str_repeat( "\n", substr_count( $m[0], "\n" ) );
		},
		$source
	);
};
$t4_line      = static function ( string $html, int $offset ): int {
	return substr_count( substr( $html, 0, $offset ), "\n" ) + 1;
};
$t4_templates = array();
$t4_iterator  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/templates', FilesystemIterator::SKIP_DOTS ) );
foreach ( $t4_iterator as $t4_file ) {
	if ( 'php' === $t4_file->getExtension() ) {
		$t4_templates[ substr( $t4_file->getPathname(), strlen( $root . '/templates/' ) ) ] = $t4_strip_php( (string) file_get_contents( $t4_file->getPathname() ) );
	}
}
ksort( $t4_templates );

// T4-1: target="_blank" anchors (single- or multi-line) carry the rel tokens required by the link policy.
$t4_blank_total   = 0;
$t4_no_opener     = array();
$t4_need_noref    = array();
$t4_first_noref   = array();
$t4_is_first_host = static function ( string $host ): bool {
	$host = strtolower( $host );
	foreach ( WPBRIDGE_T4_FIRST_PARTY_HOSTS as $t4_allowed ) {
		if ( 0 === strpos( $t4_allowed, '*.' ) ) {
			$t4_suffix = substr( $t4_allowed, 1 );
			if ( strlen( $host ) > strlen( $t4_suffix ) && substr( $host, -strlen( $t4_suffix ) ) === $t4_suffix ) {
				return true;
			}
		} elseif ( $host === $t4_allowed ) {
			return true;
		}
	}
	return false;
};
foreach ( $t4_templates as $t4_rel_path => $t4_html ) {
	preg_match_all( '/<a\b[^>]*>/is', $t4_html, $t4_anchors, PREG_OFFSET_CAPTURE );
	foreach ( $t4_anchors[0] as $t4_anchor ) {
		if ( ! preg_match( '/\btarget\s*=\s*["\']_blank["\']/i', $t4_anchor[0] ) ) {
			continue;
		}
		++$t4_blank_total;
		$t4_where   = $t4_rel_path . ':' . $t4_line( $t4_html, $t4_anchor[1] );
		$t4_has_rel = preg_match( '/\brel\s*=\s*["\']([^"\']*)["\']/i', $t4_anchor[0], $t4_rel_match );
		$t4_tokens  = $t4_has_rel ? preg_split( '/\s+/', strtolower( trim( $t4_rel_match[1] ) ) ) : array();
		$t4_href    = preg_match( '/\bhref\s*=\s*"([^"]*)"/i', $t4_anchor[0], $t4_href_match ) ? $t4_href_match[1] : '';
		$t4_dynamic = '' === $t4_href || false !== strpos( $t4_href, T4_PHP_MARKER );
		$t4_host    = $t4_dynamic ? '' : (string) parse_url( $t4_href, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain CLI test, WordPress is not loaded.
		$t4_first   = ! $t4_dynamic && '' !== $t4_host && $t4_is_first_host( $t4_host );
		if ( ! in_array( 'noopener', $t4_tokens, true ) ) {
			$t4_no_opener[] = $t4_where;
		}
		if ( $t4_first && in_array( 'noreferrer', $t4_tokens, true ) ) {
			$t4_first_noref[] = $t4_where . ' (' . $t4_host . ')';
		} elseif ( ! $t4_first && ! in_array( 'noreferrer', $t4_tokens, true ) ) {
			$t4_need_noref[] = $t4_where . ' (' . ( $t4_dynamic ? 'dynamic href' : $t4_host ) . ')';
		}
	}
}
$assert( $t4_blank_total > 0, 'T4-1 template scan finds target="_blank" anchors (' . $t4_blank_total . ' found)' );
$assert( array() === $t4_no_opener, 'T4-1a every target="_blank" anchor has rel noopener' . ( $t4_no_opener ? ' (missing ' . count( $t4_no_opener ) . ': ' . implode( ', ', $t4_no_opener ) . ')' : '' ) );
$assert( array() === $t4_need_noref, 'T4-1b third-party and dynamic-href target="_blank" anchors have rel noreferrer' . ( $t4_need_noref ? ' (missing ' . count( $t4_need_noref ) . ': ' . implode( ', ', $t4_need_noref ) . ')' : '' ) );
$assert( array() === $t4_first_noref, 'T4-1b first-party allowlisted anchors keep the referrer (no noreferrer)' . ( $t4_first_noref ? ' (unexpected ' . count( $t4_first_noref ) . ': ' . implode( ', ', $t4_first_noref ) . ')' : '' ) );

// T4-2: every .wpbridge-form-label in the source editor is associated with a control or names a role=group.
$t4_editor_path = 'admin/source-editor.php';
$t4_editor      = $t4_templates[ $t4_editor_path ] ?? '';
preg_match_all( '/(?<![\w-])id\s*=\s*"([^"]+)"/', $t4_editor, $t4_id_match );
$t4_editor_ids = $t4_id_match[1];
$t4_group_refs = array();
preg_match_all( '/<[a-z]+\b[^>]*>/is', $t4_editor, $t4_tags );
foreach ( $t4_tags[0] as $t4_tag ) {
	if ( preg_match( '/\brole\s*=\s*"group"/i', $t4_tag ) && preg_match( '/\baria-labelledby\s*=\s*"([^"]+)"/i', $t4_tag, $t4_ref ) ) {
		$t4_group_refs = array_merge( $t4_group_refs, preg_split( '/\s+/', trim( $t4_ref[1] ) ) );
	}
}
preg_match_all( '/<label\b[^>]*\bclass\s*=\s*"[^"]*\bwpbridge-form-label\b[^"]*"[^>]*>/is', $t4_editor, $t4_labels, PREG_OFFSET_CAPTURE );
$t4_label_bad = array();
foreach ( $t4_labels[0] as $t4_label ) {
	$t4_ok = false;
	if ( preg_match( '/(?<![\w-])for\s*=\s*"([^"]+)"/i', $t4_label[0], $t4_for ) ) {
		$t4_ok = in_array( $t4_for[1], $t4_editor_ids, true );
	} elseif ( preg_match( '/(?<![\w-])id\s*=\s*"([^"]+)"/i', $t4_label[0], $t4_label_id ) ) {
		$t4_ok = in_array( $t4_label_id[1], $t4_group_refs, true );
	}
	if ( ! $t4_ok ) {
		$t4_label_bad[] = $t4_editor_path . ':' . $t4_line( $t4_editor, $t4_label[1] );
	}
}
$assert( 8 === count( $t4_labels[0] ), 'T4-2 source editor has 8 .wpbridge-form-label elements (' . count( $t4_labels[0] ) . ' found)' );
$assert( array() === $t4_label_bad, 'T4-2 every .wpbridge-form-label has a matching for/id or is referenced by role="group" aria-labelledby' . ( $t4_label_bad ? ' (unassociated ' . count( $t4_label_bad ) . ': ' . implode( ', ', $t4_label_bad ) . ')' : '' ) );

// T4-3: ids introduced for label association are unique across all templates.
$t4_new_ids = array( 'wpbridge-source-name', 'wpbridge-source-auth-token', 'wpbridge-source-priority', 'wpbridge-source-enabled', 'wpbridge-wenpai-pairing-label' );
$t4_id_bad  = array();
foreach ( $t4_new_ids as $t4_id ) {
	$t4_count = 0;
	foreach ( $t4_templates as $t4_html ) {
		$t4_count += preg_match_all( '/(?<![\w-])id\s*=\s*"' . preg_quote( $t4_id, '/' ) . '"/', $t4_html );
	}
	if ( 1 !== $t4_count ) {
		$t4_id_bad[] = $t4_id . '=' . $t4_count;
	}
}
$assert( array() === $t4_id_bad, 'T4-3 label-association ids each appear exactly once in templates' . ( $t4_id_bad ? ' (' . implode( ', ', $t4_id_bad ) . ')' : '' ) );

exit( $failures > 0 ? 1 : 0 );
