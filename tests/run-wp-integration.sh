#!/usr/bin/env bash
# Run tests/wordpress/*.php contracts against real, isolated WordPress sites via `wp eval-file`.
#
# Never point this at a production site: the contracts create sites, write fixture
# plugins under WP_PLUGIN_DIR and run `wp plugin uninstall` (with --skip-delete).
#
# Configuration (environment variables):
#   WPB_WP_SINGLE  Command prefix that runs WP-CLI against a single-site install,
#                  e.g. "docker exec -w /var/www/html wpbt-single wp". Empty = skip single-site.
#   WPB_WP_MULTI   Same for a multisite install with WPBridge network-active. Empty = skip multisite.
#   WPB_PLUGIN_DIR Path of the plugin as seen by WP-CLI (default wp-content/plugins/wpbridge).
#   WPB_MOCK_PORT  Port for tests/mock-bridge-server.py (default 18765).
#   WPB_MOCK_HOOK  Optional command invoked as "$WPB_MOCK_HOOK up|down single|multi" to start/stop
#                  the mock where WP-CLI can reach 127.0.0.1:$WPB_MOCK_PORT (e.g. inside a container).
#                  When unset, the mock is started locally with python3.
#   WPB_LOG_DIR    Where per-test logs and results.tsv go (default: a new mktemp dir).
#
# Exit status: 0 = all executed tests passed (skips allowed), 1 = at least one failure.
set -uo pipefail
cd "$(dirname "$0")/.."

PLUGIN_DIR="${WPB_PLUGIN_DIR:-wp-content/plugins/wpbridge}"
MOCK_PORT="${WPB_MOCK_PORT:-18765}"
LOG_DIR="${WPB_LOG_DIR:-$(mktemp -d "${TMPDIR:-/tmp}/wpbridge-wp-integration.XXXXXX")}"
mkdir -p "$LOG_DIR"
RESULTS="$LOG_DIR/results.tsv"
printf 'env\ttest\tresult\texit\tpass_lines\tfail_lines\n' > "$RESULTS"
failures=0
local_mock_pid=""

wp_for() { if [ "$1" = single ]; then echo "${WPB_WP_SINGLE:-}"; else echo "${WPB_WP_MULTI:-}"; fi; }

mock_up() {
  if [ -n "${WPB_MOCK_HOOK:-}" ]; then $WPB_MOCK_HOOK up "$1"; return; fi
  [ -n "$local_mock_pid" ] && return
  python3 tests/mock-bridge-server.py "$MOCK_PORT" >"$LOG_DIR/mock.log" 2>&1 &
  local_mock_pid=$!
  sleep 1
}
mock_down() {
  if [ -n "${WPB_MOCK_HOOK:-}" ]; then $WPB_MOCK_HOOK down "$1"; return; fi
  [ -n "$local_mock_pid" ] && kill "$local_mock_pid" 2>/dev/null; local_mock_pid=""
}
trap 'mock_down single >/dev/null 2>&1; mock_down multi >/dev/null 2>&1' EXIT

# record <env> <label> <exit> <logfile>
record() {
  local env=$1 label=$2 code=$3 log=$4 result pass fail
  pass=$(grep -c '^PASS' "$log" || true); fail=$(grep -c '^FAIL' "$log" || true)
  if [ "$code" -eq 2 ] && grep -q '^SKIP' "$log"; then result=SKIP
  elif [ "$code" -eq 0 ] && [ "$fail" -eq 0 ]; then result=PASS
  else result=FAIL; failures=$((failures + 1)); fi
  printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$env" "$label" "$result" "$code" "$pass" "$fail" >> "$RESULTS"
  echo "[$result] $env $label (exit $code, PASS lines $pass, FAIL lines $fail)"
}

# run_eval <env> <label> <file> [args...]
run_eval() {
  local env=$1 label=$2 file=$3; shift 3
  local wp log="$LOG_DIR/${env}-${label}.log"
  wp=$(wp_for "$env")
  echo "=== $env: $label ==="
  $wp eval-file "$PLUGIN_DIR/tests/wordpress/$file" "$@" >"$log" 2>&1
  local code=$?
  cat "$log"
  record "$env" "$label" "$code" "$log"
}

run_step() {  # run_step <env> <label> <wp args...>  (setup steps, recorded only on failure)
  local env=$1 label=$2; shift 2
  local wp log="$LOG_DIR/${env}-${label}.log"
  wp=$(wp_for "$env")
  if ! $wp "$@" >"$log" 2>&1; then
    cat "$log"; record "$env" "$label" 1 "$log"; return 1
  fi
}

run_common() {
  local env=$1
  # encryption-rotation.php encrypts with a fixed "previous" key and expects WPBridge to decrypt it
  # via WPBRIDGE_ENCRYPTION_PREVIOUS_KEYS; define it before WordPress loads instead of editing wp-config.
  run_eval "$env" encryption-rotation encryption-rotation.php \
    "--exec=define('WPBRIDGE_ENCRYPTION_PREVIOUS_KEYS', 'round2-previous-key-material');"
  run_eval "$env" rollback-contract rollback-contract.php
  run_eval "$env" ssrf-contract ssrf-contract.php
  mock_up "$env"
  run_eval "$env" bridge-contract bridge-contract.php "$MOCK_PORT"
  mock_down "$env"
}

if [ -n "${WPB_WP_SINGLE:-}" ]; then
  run_common single
  run_eval single multisite-lifecycle-skip multisite-lifecycle.php
else
  echo "WPB_WP_SINGLE not set; skipping single-site contracts"
fi

if [ -n "${WPB_WP_MULTI:-}" ]; then
  run_common multi
  run_eval multi multisite-lifecycle multisite-lifecycle.php
  run_eval multi multisite-uninstall-prepare multisite-uninstall.php prepare
  if run_step multi network-deactivate plugin deactivate wpbridge --network \
    && run_step multi plugin-uninstall plugin uninstall wpbridge --skip-delete; then
    run_eval multi multisite-uninstall-verify multisite-uninstall.php verify
  fi
  run_step multi network-reactivate plugin activate wpbridge --network || true
else
  echo "WPB_WP_MULTI not set; skipping multisite contracts"
fi

echo
echo "Results: $RESULTS"
column -t -s $'\t' "$RESULTS" 2>/dev/null || cat "$RESULTS"
if (( failures > 0 )); then echo "WordPress integration failures: $failures"; exit 1; fi
echo "All executed WordPress integration contracts passed."
