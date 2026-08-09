=== WPBridge ===
Contributors: wenpai
Tags: updates, mirror, plugin updates, theme updates, bridge
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.3
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

= 1.2.3 =
* Keep the self-updater class unique to avoid collisions with other WenPai plugins.
* Harden updater response handling and source data migration.
* Improve uninstall cleanup and test coverage.
