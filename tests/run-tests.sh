#!/usr/bin/env bash
set -uo pipefail
cd "$(dirname "$0")/.."
failures=0
# PHP_BIN: optional PHP binary (default: php). Used e.g. to run the suite on a PHP without ext-sodium.
export PHP_BIN="${PHP_BIN:-php}"
# Test-only libsodium polyfill, prepended to every PHP test script (no-op when ext-sodium is loaded).
SODIUM_PREPEND="-dauto_prepend_file=$PWD/tests/sodium-polyfill.php"

run() {
  local label="$1"
  shift
  echo "=== ${label} ==="
  if "$@"; then
    echo "[PASS] ${label}"
  else
    status=$?
    echo "[FAIL] ${label} (exit ${status})"
    failures=$((failures + 1))
  fi
}

run "PHP syntax (all plugin PHP files)" bash -c 'find . -path ./node_modules -prune -o -path ./vendor -prune -o -path ./.git -prune -o -name "*.php" -print0 | xargs -0 -n1 "$PHP_BIN" -l >/tmp/wpbridge-php-lint.log && cat /tmp/wpbridge-php-lint.log'
run "Security regressions" "$PHP_BIN" "$SODIUM_PREPEND" tests/security-regression-test.php
run "REST repo param (no dot segments)" "$PHP_BIN" "$SODIUM_PREPEND" tests/repo-param-dotdot-test.php
run "Updater regressions" "$PHP_BIN" "$SODIUM_PREPEND" tests/updater-regression-test.php
run "Updater version guard and Site Health" "$PHP_BIN" "$SODIUM_PREPEND" tests/version-guard-contract-test.php
run "Self-update signature policy (dedicated key: strict)" "$PHP_BIN" "$SODIUM_PREPEND" tests/selfupdate-signature-policy-test.php dedicated
run "Self-update signature policy (shared key only)" "$PHP_BIN" "$SODIUM_PREPEND" tests/selfupdate-signature-policy-test.php shared
run "Self-update signature policy (no key)" "$PHP_BIN" "$SODIUM_PREPEND" tests/selfupdate-signature-policy-test.php none
run "Self-update signature policy (dedicated constant: empty array)" "$PHP_BIN" "$SODIUM_PREPEND" tests/selfupdate-signature-policy-test.php empty
run "Self-update signature policy (dedicated constant: string)" "$PHP_BIN" "$SODIUM_PREPEND" tests/selfupdate-signature-policy-test.php string
run "Self-update signature policy (dedicated constant: invalid key)" "$PHP_BIN" "$SODIUM_PREPEND" tests/selfupdate-signature-policy-test.php badkey
run "Self-update integrity logging (admin context)" "$PHP_BIN" "$SODIUM_PREPEND" tests/selfupdate-logger-context-test.php admin
run "Self-update integrity logging (cli context)" "$PHP_BIN" "$SODIUM_PREPEND" tests/selfupdate-logger-context-test.php cli
run "Self-update integrity logging (cron context)" "$PHP_BIN" "$SODIUM_PREPEND" tests/selfupdate-logger-context-test.php cron
run "Self-update integrity logging (switching context)" "$PHP_BIN" "$SODIUM_PREPEND" tests/selfupdate-logger-context-test.php switching
run "Self-update integrity logging (unavailable context)" "$PHP_BIN" "$SODIUM_PREPEND" tests/selfupdate-logger-context-test.php unavailable
run "Update authorization" "$PHP_BIN" "$SODIUM_PREPEND" tests/update-authorization-test.php
run "Hub-Spoke contract" "$PHP_BIN" "$SODIUM_PREPEND" tests/hub-spoke-contract-test.php
run "Hub credential boundary" "$PHP_BIN" "$SODIUM_PREPEND" tests/hub-credential-boundary-test.php
run "Hub bridge credential atomicity" "$PHP_BIN" "$SODIUM_PREPEND" tests/hub-bridge-credential-atomic-test.php
run "Hub feature-off cleanup" "$PHP_BIN" "$SODIUM_PREPEND" tests/hub-flag-off-cleanup-test.php
run "Hub multisite uninstall" "$PHP_BIN" "$SODIUM_PREPEND" tests/hub-multisite-uninstall-test.php
run "Hub protected package E2E" "$PHP_BIN" "$SODIUM_PREPEND" tests/hub-protected-package-e2e-test.php
run "Hub stream guard" "$PHP_BIN" "$SODIUM_PREPEND" tests/hub-stream-guard-test.php
run "Hub controller package E2E" "$PHP_BIN" "$SODIUM_PREPEND" tests/hub-controller-package-e2e-test.php
run "Artifact signatures" "$PHP_BIN" "$SODIUM_PREPEND" tests/artifact-signature-test.php
run "Sodium safe wrappers" "$PHP_BIN" "$SODIUM_PREPEND" tests/sodium-safe-test.php
run "Site Health sources" "$PHP_BIN" "$SODIUM_PREPEND" tests/site-health-sources-test.php
run "Repository contracts" "$PHP_BIN" "$SODIUM_PREPEND" tests/static-contract-test.php
run "Admin JavaScript syntax" node --check assets/js/admin.js

if (( failures > 0 )); then
  echo "Test groups failed: ${failures}"
  exit 1
fi

echo "All implemented test groups passed."
