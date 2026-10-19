# Changelog

All notable changes to WPBridge are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

`readme.txt` keeps the user-facing `== Changelog ==` section for the WordPress
update screen. For 1.2.3 and later the bullets below match `readme.txt`
word for word; when releasing, update both files in the same commit.
Entries before 1.2.3 were reconstructed from the git history and release tags,
because `readme.txt` did not carry a changelog at the time.

## [Unreleased]

_Nothing merged to `main` since 1.3.0 yet. The 1.3.1 rollback-safety fix is
in review._

## [1.3.0] - 2026-08-15

### Changed
- Promote the hub-spoke credential/authorization architecture, protected update
  grants, and artifact Ed25519 signing/keyring verification to a minor release
  (previously staged under 1.2.4).
- No functional change from the 1.2.4 candidate; version level corrected to
  reflect the feature-scope additions.

## [1.2.4] - 2026-08-13 (private candidate, superseded by 1.3.0)

### Security
- Validate and pin public DNS addresses on every remote request and redirect hop.
- Support encryption-key rotation and fail closed when stored credentials cannot
  be decrypted.
- Harden migrations, administrator input handling, PHP 7.4 compatibility, and
  private-release checks.

### Fixed
- Replace backups atomically and restore the previous directory when a swap fails.

### Added
- Multisite new-site initialization and deletion/uninstall cleanup.

## [1.2.3] - 2026-07-27

### Fixed
- Keep the self-updater class unique to avoid collisions with other WenPai
  plugins.
- Harden updater response handling and source data migration.
- Improve uninstall cleanup and test coverage.

## [1.2.2] - 2026-03-18

### Fixed
- Plugin list template no longer leaks the vendor-name variable between rows.
- AutoMatcher no longer binds free plugins to vendor (commercial) updates.

## [1.2.1] - 2026-03-18

### Security
- Code-review fixes for 3 critical and 5 high-severity bugs.
- Zip Slip path-traversal protection in `BackupManager::extract_zip`.
- Complete SSRF protection for remote requests and enable `sslverify` for
  WooCommerce API Manager requests.
- Fix the 16 issues raised in the security audit report.

### Fixed
- `VendorManager` decrypts credentials stored as secure options.
- Self-updater filters generated summaries and cleans up checksum tables in
  release notes.

## [1.2.0] - 2026-03-11

### Changed
- Redesigned admin: tabs consolidated from 8 to 5, update sources merged into
  the vendors (channels) tab, diagnostics and logs folded into settings,
  rebuilt overview and vendors pages, unified visual style.
- Merged `escapeHtml` helpers and added AJAX concurrency control.

### Fixed
- Short cache for negative results so temporary failures do not block features
  for long.
- Vendor download failures (failure cache plus multi-path download URL
  extraction).
- Slug case handling when taking over vendor updates.

## [1.1.0] - 2026-03-10 (untagged)

### Added
- `SubscriptionManager` for commercial product subscriptions, with AJAX
  endpoints and detection on preset activation.
- Kestrel API Manager vendor integration.
- MySQL version and server details on the overview system panel.

### Removed
- Twelve non-core modules (about 6,500 lines): AI bridge (now WPMind's job),
  notifications, the FAIR module (the FAIR handler stays), source groups,
  LicenseProxy, GPLValidator, CommercialDetector, ChangelogManager (WordPress
  already shows changelogs), MigrationManager, and unused performance helpers.

## [1.0.0] - 2026-03-08 (untagged)

### Added
- Integration with the wenpai-bridge service across all configuration paths.
- Vendor system for third-party GPL plugin sources, `BridgeClient` for the Go
  bridge server, `BridgeServerHandler`, and encrypted API key storage.
- Redesigned vendors page with preset cards and Bridge API connections.

## [0.9.6] - 2026-02-14

### Added
- Forgejo release workflow.

## Pre-0.9.6 development (2026-02-04 to 2026-02-05, untagged)

- 0.9.5: code-review fixes.
- 0.9.4: asynchronous batch detection of plugin types.
- 0.9.0: version locking and aggregated changelog display.
- 0.8.0: configuration import and export.
- 0.7.x: overview dashboard, diagnostics page, commercial plugin detection from
  remote JSON configuration.
- 0.5.0: Gutenberg-style admin interface.
- 0.4.0: Cloud API (Bridge API).
- 0.3.0: AI bridge layer, source groups, commercial plugin support.
- 0.2.0: first complete plugin implementation.

[Unreleased]: https://feicode.com/WenPai-org/wpbridge/compare/v1.3.0...main
[1.3.0]: https://feicode.com/WenPai-org/wpbridge/compare/v1.2.3...v1.3.0
[1.2.3]: https://feicode.com/WenPai-org/wpbridge/compare/v1.2.2...v1.2.3
[1.2.2]: https://feicode.com/WenPai-org/wpbridge/compare/v1.2.1...v1.2.2
[1.2.1]: https://feicode.com/WenPai-org/wpbridge/compare/v1.2.0...v1.2.1
[1.2.0]: https://feicode.com/WenPai-org/wpbridge/compare/v0.9.6...v1.2.0
[0.9.6]: https://feicode.com/WenPai-org/wpbridge/releases/tag/v0.9.6
