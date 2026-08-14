#!/usr/bin/env bash
set -uo pipefail
cd "$(dirname "$0")/.."
failures=0

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

run "PHP syntax (all plugin PHP files)" bash -c 'find . -path ./node_modules -prune -o -path ./vendor -prune -o -path ./.git -prune -o -name "*.php" -print0 | xargs -0 -n1 php -l >/tmp/wpbridge-php-lint.log && cat /tmp/wpbridge-php-lint.log'
run "Security regressions" php tests/security-regression-test.php
run "Updater regressions" php tests/updater-regression-test.php
run "Update authorization" php tests/update-authorization-test.php
run "Hub-Spoke contract" php tests/hub-spoke-contract-test.php
run "Hub protected package E2E" php tests/hub-protected-package-e2e-test.php
run "Hub stream guard" php tests/hub-stream-guard-test.php
run "Hub controller package E2E" php tests/hub-controller-package-e2e-test.php
run "Artifact signatures" php tests/artifact-signature-test.php
run "Repository contracts" php tests/static-contract-test.php
run "Admin JavaScript syntax" node --check assets/js/admin.js

if (( failures > 0 )); then
  echo "Test groups failed: ${failures}"
  exit 1
fi

echo "All implemented test groups passed."
