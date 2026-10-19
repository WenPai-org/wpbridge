#!/usr/bin/env bash
# Run tests/wordpress/*.php contracts against real, isolated WordPress sites via `wp eval-file`.
#
# Never point this at a production site: the contracts create sites, write fixture
# plugins under WP_PLUGIN_DIR and run `wp plugin uninstall` (with --skip-delete).
#
# WARNING - DATA LOSS: the multisite uninstall stage runs the real uninstall.php, which deletes
# every per-site `wpbridge_%` option and the network-level WPBridge data (link key pair,
# hub/spoke links, role, ...). It only runs when WPBT_ALLOW_DESTRUCTIVE=1; otherwise it is
# reported as SKIP. A snapshot self-check (write probe options, export, delete, restore,
# compare) must pass before the uninstall is allowed to run.
#
# For every configured site, all per-site and network `wpbridge_%` options are exported before
# the first contract and written back at the end (also from the EXIT/INT/TERM trap), so the
# contracts' own side effects (logs, rollback fixture backups) do not stay behind either. The
# plugin's activation state is restored, rollback fixture backups are deleted and fixture sites
# created by the contracts (paths wpbridge-uninstall-*/wpbridge-lifecycle-*) are deleted.
# Site transients are not restored.
#
# The contract files are read from the site itself ("$WPB_PLUGIN_DIR/tests/wordpress/..."), so
# the plugin on the site must be a full source checkout. A site deployed from deploy.sh or the
# release zip has no tests/ directory and every contract will FAIL.
#
# Configuration (environment variables):
#   WPB_WP_SINGLE  Command prefix that runs WP-CLI against a single-site install,
#                  e.g. "docker exec -w /var/www/html wpbt-single wp". Empty = skip single-site.
#   WPB_WP_MULTI   Same for a multisite install with WPBridge network-active. Empty = skip multisite.
#   WPB_PLUGIN_DIR Path of the plugin as seen by WP-CLI (default wp-content/plugins/wpbridge).
#   WPB_MOCK_PORT  Port for tests/mock-bridge-server.py (default 18765).
#   WPB_MOCK_HOOK  Optional command invoked as "$WPB_MOCK_HOOK up|down single|multi" to start/stop
#                  the mock where WP-CLI can reach 127.0.0.1:$WPB_MOCK_PORT (e.g. inside a container).
#                  The hook must only return 0 once the mock answers /health.
#                  When unset, the mock is started locally with python3 and /health is polled.
#   WPB_LOG_DIR    Where per-test logs and results.tsv go (default: a new mktemp dir).
#   WPBT_ALLOW_DESTRUCTIVE  Set to 1 to run the multisite uninstall stage (see WARNING above).
#
# Exit status: 0 = all executed tests passed (skips allowed), 1 = at least one failure,
# 2 = nothing was executed (neither WPB_WP_SINGLE nor WPB_WP_MULTI is set).
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

PLUGIN_DIR="${WPB_PLUGIN_DIR:-wp-content/plugins/wpbridge}"
MOCK_PORT="${WPB_MOCK_PORT:-18765}"
LOG_DIR="${WPB_LOG_DIR:-$(mktemp -d "${TMPDIR:-/tmp}/wpbridge-wp-integration.XXXXXX")}"
mkdir -p "$LOG_DIR"
RESULTS="$LOG_DIR/results.tsv"
printf 'env\ttest\tresult\texit\tpass_lines\tfail_lines\n' > "$RESULTS"
failures=0
executed=0
local_mock_pid=""
# Per-env cleanup state (bash 3 compatible, no associative arrays), used by env_cleanup / the trap.
# shellcheck disable=SC2034  # read indirectly via ${!name} in env_cleanup
declare sites_before_multi="" was_active_single="" was_active_multi="" \
  snapshot_single="" snapshot_multi="" pending_single=0 pending_multi=0

wp_for() { if [ "$1" = single ]; then echo "${WPB_WP_SINGLE:-}"; else echo "${WPB_WP_MULTI:-}"; fi; }

mock_health() { curl -fsS --max-time 2 "http://127.0.0.1:$MOCK_PORT/health" >/dev/null 2>&1; }

mock_up() {
  if [ -n "${WPB_MOCK_HOOK:-}" ]; then $WPB_MOCK_HOOK up "$1"; return; fi
  [ -n "$local_mock_pid" ] && return 0
  if mock_health; then
    echo "FAIL: port $MOCK_PORT already answers /health before the mock was started; refusing to test against another process" | tee "$LOG_DIR/mock.log"
    return 1
  fi
  python3 tests/mock-bridge-server.py "$MOCK_PORT" >"$LOG_DIR/mock.log" 2>&1 &
  local_mock_pid=$!
  for _ in $(seq 1 50); do
    if ! kill -0 "$local_mock_pid" 2>/dev/null; then
      echo "FAIL: mock exited during startup (port $MOCK_PORT in use?)"; cat "$LOG_DIR/mock.log"
      local_mock_pid=""; return 1
    fi
    mock_health && return 0
    sleep 0.2
  done
  echo "FAIL: mock did not answer /health on port $MOCK_PORT within 10s"
  mock_down "$1"; return 1
}
mock_down() {
  if [ -n "${WPB_MOCK_HOOK:-}" ]; then $WPB_MOCK_HOOK down "$1"; return; fi
  [ -n "$local_mock_pid" ] && kill "$local_mock_pid" 2>/dev/null; local_mock_pid=""
}

# record <env> <label> <exit> <logfile>
# PASS requires exit 0, at least one PASS line and no FAIL line (leading whitespace allowed).
record() {
  local env=$1 label=$2 code=$3 log=$4 result pass fail
  pass=$(grep -cE '^[[:space:]]*PASS' "$log" || true); fail=$(grep -cE '^[[:space:]]*FAIL' "$log" || true)
  if [ "$code" -eq 2 ] && grep -qE '^[[:space:]]*SKIP' "$log"; then result=SKIP
  elif [ "$code" -eq 0 ] && [ "$fail" -eq 0 ] && [ "$pass" -gt 0 ]; then result=PASS
  else result=FAIL; failures=$((failures + 1)); fi
  printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$env" "$label" "$result" "$code" "$pass" "$fail" >> "$RESULTS"
  echo "[$result] $env $label (exit $code, PASS lines $pass, FAIL lines $fail)"
}

# record_text <env> <label> <exit> <text>: record a result produced by this script itself.
record_text() {
  local log="$LOG_DIR/$1-$2.log"
  printf '%s\n' "$4" > "$log"
  cat "$log"
  record "$1" "$2" "$3" "$log"
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

# --- wpbridge_% option snapshot (multisite uninstall stage) ---------------------------------
# Raw rows are exported (serialized values and autoload as stored), so a restore is byte-exact.
# shellcheck disable=SC2016  # PHP code, $ is not a shell expansion
SNAPSHOT_EXPORT_PHP='
global $wpdb;
$like = $wpdb->esc_like( "wpbridge_" ) . "%";
$out  = array( "network" => array(), "sites" => array() );
if ( is_multisite() ) {
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key LIKE %s", get_current_network_id(), $like ), ARRAY_A );
	foreach ( $rows as $r ) { $out["network"][ $r["meta_key"] ] = $r["meta_value"]; }
	ksort( $out["network"] );
	$ids = get_sites( array( "fields" => "ids", "number" => 0 ) );
} else {
	$ids = array( get_current_blog_id() );
}
foreach ( $ids as $id ) {
	if ( is_multisite() ) { switch_to_blog( (int) $id ); }
	$o    = array();
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s", $like ), ARRAY_A );
	foreach ( $rows as $r ) { $o[ $r["option_name"] ] = array( $r["option_value"], $r["autoload"] ); }
	ksort( $o );
	$out["sites"][ (string) $id ] = $o;
	if ( is_multisite() ) { restore_current_blog(); }
}
echo "WPBSNAP:" . base64_encode( wp_json_encode( $out ) ) . "\n";
'
# shellcheck disable=SC2016
SNAPSHOT_RESTORE_PHP='
$s = json_decode( base64_decode( $wpb_snapshot ), true );
if ( ! is_array( $s ) || ! isset( $s["network"], $s["sites"] ) ) { echo "FAIL: snapshot unreadable\n"; exit( 1 ); }
global $wpdb;
$like = $wpdb->esc_like( "wpbridge_" ) . "%";
if ( is_multisite() ) {
	$nid = get_current_network_id();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key LIKE %s", $nid, $like ) );
	foreach ( $s["network"] as $k => $v ) { $wpdb->insert( $wpdb->sitemeta, array( "site_id" => $nid, "meta_key" => $k, "meta_value" => $v ) ); }
}
foreach ( $s["sites"] as $id => $opts ) {
	if ( is_multisite() ) {
		if ( ! get_site( (int) $id ) ) { echo "FAIL: site $id from the snapshot no longer exists\n"; continue; }
		switch_to_blog( (int) $id );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	foreach ( $opts as $k => $v ) { $wpdb->insert( $wpdb->options, array( "option_name" => $k, "option_value" => $v[0], "autoload" => $v[1] ) ); }
	if ( is_multisite() ) { restore_current_blog(); }
}
wp_cache_flush();
echo "PASS: restored wpbridge_% options (network " . count( $s["network"] ) . ", sites " . count( $s["sites"] ) . ")\n";
'
# shellcheck disable=SC2016
SNAPSHOT_PROBE_PHP='
$net = "wpbridge_snapshot_probe_network"; $opt = "wpbridge_snapshot_probe";
if ( "write" === $wpb_probe ) {
	update_site_option( $net, array( "n" => 1, "s" => "network \"probe\"" ) );
	foreach ( get_sites( array( "fields" => "ids", "number" => 0 ) ) as $id ) {
		switch_to_blog( (int) $id ); update_option( $opt, array( "site" => (int) $id, "v" => "probe-" . $id ), false ); restore_current_blog();
	}
} else {
	delete_site_option( $net );
	foreach ( get_sites( array( "fields" => "ids", "number" => 0 ) ) as $id ) { switch_to_blog( (int) $id ); delete_option( $opt ); restore_current_blog(); }
}
wp_cache_flush();
echo "PASS: probe $wpb_probe\n";
'
# shellcheck disable=SC2016
FIXTURE_SITES_PHP='
$keep = array_map( "intval", array_filter( explode( ",", $wpb_keep ) ) );
$n = 0;
foreach ( get_sites( array( "fields" => "ids", "number" => 0 ) ) as $id ) {
	$site = get_site( (int) $id );
	if ( in_array( (int) $id, $keep, true ) || ! preg_match( "#/wpbridge-(uninstall|lifecycle)-[A-Za-z0-9]{8}/$#", $site->path ) ) { continue; }
	$r = wp_delete_site( (int) $id );
	echo is_wp_error( $r ) ? "FAIL: delete fixture site $id: " . $r->get_error_message() . "\n" : "deleted fixture site $id {$site->path}\n";
	$n++;
}
echo "PASS: fixture sites deleted: $n\n";
'
# shellcheck disable=SC2016
FIXTURE_BACKUPS_PHP='
$key = "plugin:wpbridge-r2-fixture/fixture.php"; $n = 0;
if ( class_exists( "WPBridge\\Core\\BackupManager" ) ) {
	$all = (array) get_option( "wpbridge_backups", array() );
	foreach ( (array) ( $all[ $key ] ?? array() ) as $b ) {
		if ( isset( $b["id"] ) && \WPBridge\Core\BackupManager::get_instance()->delete_backup( $key, (string) $b["id"] ) ) { $n++; }
	}
}
echo "PASS: rollback fixture backups deleted: $n\n";
'

# snapshot_export <env> <outfile>: writes the base64 snapshot to <outfile>.
snapshot_export() {
  local wp; wp=$(wp_for "$1")
  $wp eval "$SNAPSHOT_EXPORT_PHP" 2>"$2.err" | sed -n 's/^WPBSNAP://p' > "$2"
  [ -s "$2" ]
}
snapshot_restore() {  # snapshot_restore <env> <snapshot file> <log>
  local wp; wp=$(wp_for "$1")
  $wp eval "\$wpb_snapshot = '$(cat "$2")'; $SNAPSHOT_RESTORE_PHP" >"$3" 2>&1
  local code=$?
  [ "$code" -eq 0 ] && ! grep -qE '^[[:space:]]*FAIL' "$3"
}
snapshot_probe() {  # snapshot_probe <env> write|delete
  local wp; wp=$(wp_for "$1")
  $wp eval "\$wpb_probe = '$2'; $SNAPSHOT_PROBE_PHP" >/dev/null 2>&1
}
site_ids() { local wp; wp=$(wp_for "$1"); $wp site list --field=blog_id --format=csv 2>/dev/null | grep -E '^[0-9]+$' | paste -sd, -; }
delete_fixture_sites() {  # delete_fixture_sites <env> <ids to keep> <log>
  local wp; wp=$(wp_for "$1")
  $wp eval "\$wpb_keep = '$2'; $FIXTURE_SITES_PHP" >"$3" 2>&1 && ! grep -qE '^[[:space:]]*FAIL' "$3"
}

# snapshot_selfcheck <env>: probe options -> export -> delete -> restore -> compare, then clean up.
snapshot_selfcheck() {
  local env=$1 d="$LOG_DIR/$1-snapshot-selfcheck" ok=1 msg=""
  mkdir -p "$d"
  snapshot_probe "$env" write || { ok=0; msg="probe write failed"; }
  [ $ok = 1 ] && { snapshot_export "$env" "$d/with-probe.b64" || { ok=0; msg="export failed"; }; }
  [ $ok = 1 ] && { snapshot_probe "$env" delete || { ok=0; msg="probe delete failed"; }; }
  [ $ok = 1 ] && { snapshot_export "$env" "$d/deleted.b64" || { ok=0; msg="export after delete failed"; }; }
  [ $ok = 1 ] && cmp -s "$d/with-probe.b64" "$d/deleted.b64" && { ok=0; msg="probe delete had no effect"; }
  [ $ok = 1 ] && { snapshot_restore "$env" "$d/with-probe.b64" "$d/restore.log" || { ok=0; msg="restore failed"; }; }
  [ $ok = 1 ] && { snapshot_export "$env" "$d/restored.b64" || { ok=0; msg="export after restore failed"; }; }
  [ $ok = 1 ] && ! cmp -s "$d/with-probe.b64" "$d/restored.b64" && { ok=0; msg="restored options differ from the export"; }
  snapshot_probe "$env" delete
  if [ $ok = 1 ]; then
    record_text "$env" snapshot-selfcheck 0 "PASS: wpbridge_% export/delete/restore round-trip identical"
  else
    record_text "$env" snapshot-selfcheck 1 "FAIL: snapshot self-check: $msg"
  fi
  [ $ok = 1 ]
}

is_active() {  # is_active <env>: network activation on multisite, plain activation on single
  local wp; wp=$(wp_for "$1")
  if [ "$1" = multi ]; then $wp plugin is-active wpbridge --network >/dev/null 2>&1; else $wp plugin is-active wpbridge >/dev/null 2>&1; fi
}
delete_fixture_backups() {  # delete_fixture_backups <env>
  local wp; wp=$(wp_for "$1")
  $wp eval "$FIXTURE_BACKUPS_PHP" >"$LOG_DIR/$1-cleanup-fixture-backups.log" 2>&1 \
    || record "$1" cleanup-fixture-backups 1 "$LOG_DIR/$1-cleanup-fixture-backups.log"
}

# env_begin <env>: remember activation state and site list, export wpbridge_% options.
env_begin() {
  local env=$1 snap="$LOG_DIR/$1-snapshot-before.b64"
  if is_active "$env"; then printf -v "was_active_$env" 1; else printf -v "was_active_$env" 0; fi
  [ "$env" = multi ] && sites_before_multi=$(site_ids multi)
  if ! snapshot_export "$env" "$snap"; then
    record_text "$env" snapshot-export 1 "FAIL: could not export wpbridge_% options; not running $env contracts"
    return 1
  fi
  printf -v "snapshot_$env" '%s' "$snap"
  printf -v "pending_$env" 1
}

# env_cleanup <env>: undo everything the contracts changed. Idempotent; also called from the trap.
env_cleanup() {
  local env=$1 pending_var="pending_$1" active_var="was_active_$1" snap_var="snapshot_$1" wp
  [ "${!pending_var}" = 1 ] || return 0
  printf -v "$pending_var" 0
  wp=$(wp_for "$env")
  echo "=== $env: cleanup ==="
  if [ "${!active_var}" = 1 ] && ! is_active "$env"; then
    local net=""; [ "$env" = multi ] && net=--network
    $wp plugin activate wpbridge $net >"$LOG_DIR/$env-cleanup-reactivate.log" 2>&1 \
      || record "$env" cleanup-reactivate 1 "$LOG_DIR/$env-cleanup-reactivate.log"
  fi
  delete_fixture_backups "$env"
  if [ "$env" = multi ] && [ -n "$sites_before_multi" ]; then
    delete_fixture_sites multi "$sites_before_multi" "$LOG_DIR/multi-cleanup-fixture-sites.log" \
      || record multi cleanup-fixture-sites 1 "$LOG_DIR/multi-cleanup-fixture-sites.log"
    cat "$LOG_DIR/multi-cleanup-fixture-sites.log"
  fi
  if snapshot_restore "$env" "${!snap_var}" "$LOG_DIR/$env-cleanup-restore.log" \
    && snapshot_export "$env" "$LOG_DIR/$env-snapshot-after.b64" \
    && cmp -s "${!snap_var}" "$LOG_DIR/$env-snapshot-after.b64"; then
    echo "wpbridge_% options restored and identical to the pre-run export"
  else
    cat "$LOG_DIR/$env-cleanup-restore.log"
    record_text "$env" cleanup-restore-options 1 "FAIL: wpbridge_% options differ from the pre-run export (${!snap_var})"
  fi
}

on_exit() {
  env_cleanup single
  env_cleanup multi
  mock_down single >/dev/null 2>&1; mock_down multi >/dev/null 2>&1
}
trap on_exit EXIT
trap 'echo "interrupted"; exit 130' INT TERM

run_common() {
  local env=$1
  # encryption-rotation.php encrypts with a fixed "previous" key and expects WPBridge to decrypt it
  # via WPBRIDGE_ENCRYPTION_PREVIOUS_KEYS; define it before WordPress loads instead of editing wp-config.
  run_eval "$env" encryption-rotation encryption-rotation.php \
    "--exec=define('WPBRIDGE_ENCRYPTION_PREVIOUS_KEYS', 'round2-previous-key-material');"
  run_eval "$env" rollback-contract rollback-contract.php
  delete_fixture_backups "$env"
  run_eval "$env" ssrf-contract ssrf-contract.php
  if mock_up "$env" >"$LOG_DIR/${env}-mock-up.log" 2>&1; then
    run_eval "$env" bridge-contract bridge-contract.php "$MOCK_PORT"
  else
    cat "$LOG_DIR/${env}-mock-up.log"; record "$env" bridge-contract-mock 1 "$LOG_DIR/${env}-mock-up.log"
  fi
  mock_down "$env"
}

if [ -n "${WPB_WP_SINGLE:-}" ]; then
  executed=$((executed + 1))
  if env_begin single; then
    run_common single
    run_eval single multisite-lifecycle-skip multisite-lifecycle.php
    env_cleanup single
  fi
else
  echo "WPB_WP_SINGLE not set; skipping single-site contracts"
fi

if [ -n "${WPB_WP_MULTI:-}" ]; then
  executed=$((executed + 1))
  if env_begin multi; then
    run_common multi
    run_eval multi multisite-lifecycle multisite-lifecycle.php
    if [ "${WPBT_ALLOW_DESTRUCTIVE:-}" != 1 ]; then
      record_text multi multisite-uninstall 2 "SKIP: multisite uninstall wipes all WPBridge network and site data; set WPBT_ALLOW_DESTRUCTIVE=1 to run it"
    elif ! snapshot_selfcheck multi; then
      echo "Not running the uninstall stage: the wpbridge_% snapshot could not be verified"
    else
      run_eval multi multisite-uninstall-prepare multisite-uninstall.php prepare
      if run_step multi network-deactivate plugin deactivate wpbridge --network \
        && run_step multi plugin-uninstall plugin uninstall wpbridge --skip-delete; then
        run_eval multi multisite-uninstall-verify multisite-uninstall.php verify
      fi
      run_step multi network-reactivate plugin activate wpbridge --network || true
    fi
    env_cleanup multi
  fi
else
  echo "WPB_WP_MULTI not set; skipping multisite contracts"
fi

echo
if (( executed == 0 )); then
  echo "SKIP: no WordPress site configured (set WPB_WP_SINGLE and/or WPB_WP_MULTI); nothing was run."
  exit 2
fi
echo "Results: $RESULTS"
column -t -s $'\t' "$RESULTS" 2>/dev/null || cat "$RESULTS"
if (( failures > 0 )); then echo "WordPress integration failures: $failures"; exit 1; fi
echo "All executed WordPress integration contracts passed."
