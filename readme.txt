=== WPBridge ===
Contributors: wenpai
Tags: updates, mirror, plugin updates, theme updates, bridge
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WPBridge lets administrators configure and prioritize external update sources for WordPress plugins and themes.

== Description ==

WPBridge provides source registration, per-plugin and per-theme routing, vendor connections, update caching, version locks, backups, rollback, an opt-in REST API, WP-CLI commands, and Site Health diagnostics.

The plugin uses an external Update URI and is distributed through WenPai infrastructure; it is not intended for the WordPress.org plugin directory in its current form.

== Installation ==

1. Upload the `wpbridge` directory to `/wp-content/plugins/`.
2. Activate WPBridge in WordPress.
3. Open WPBridge in the administrator menu and configure trusted update sources.

== Changelog ==

= 1.3.1 =
* Derive reproducible CI package names from the plugin version instead of the retired 1.2.4 literal.
* Keep upgrade and rollback completion fail-closed when WordPress replaces plugin files during the active request.

= 1.3.0 =
* Promote the hub-spoke credential/authorization architecture, protected update grants, and artifact Ed25519 signing/keyring verification to a minor release (previously staged under 1.2.4).
* No functional change from the 1.2.4 candidate; version level corrected to reflect the feature-scope additions.

= 1.2.4 =
* Validate and pin public DNS addresses on every remote request and redirect hop.
* Replace backups atomically and restore the previous directory when a swap fails.
* Add multisite new-site initialization and deletion/uninstall cleanup.
* Support encryption-key rotation and fail closed when stored credentials cannot be decrypted.
* Harden migrations, administrator input handling, PHP 7.4 compatibility, and private-release checks.

= 1.2.3 =
* Keep the self-updater class unique to avoid collisions with other WenPai plugins.
* Harden updater response handling and source data migration.
* Improve uninstall cleanup and test coverage.
