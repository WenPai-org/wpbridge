#!/usr/bin/env bash
set -euo pipefail

profile="${1:-}"
plugin="${2:-wpbridge}"
output="${3:-/tmp/wpbridge-plugin-check-${profile}.txt}"

case "$profile" in
  private)
    # Product-approved exception: WPBridge is distributed through FeiCode/WenPai.
    # Only the WordPress.org updater policy code is exempt; warnings and every
    # other error remain visible and blocking.
    profile_args=(--ignore-codes=plugin_updater_detected)
    ;;
  wordpress-org)
    # No policy exceptions. This profile is expected to reject the current
    # private updater until a separate WordPress.org distribution is created.
    profile_args=()
    ;;
  *)
    echo "Usage: $0 <private|wordpress-org> [plugin-slug] [raw-output]" >&2
    exit 64
    ;;
esac

wp_args=()
if [[ -n "${WPBRIDGE_WP_PATH:-}" ]]; then
  wp_args+=("--path=${WPBRIDGE_WP_PATH}")
fi
if [[ -n "${WPBRIDGE_WP_URL:-}" ]]; then
  wp_args+=("--url=${WPBRIDGE_WP_URL}")
fi

mkdir -p "$(dirname "$output")"
wp plugin check "$plugin" "${wp_args[@]}" "${profile_args[@]}" --format=json --no-color > "$output"
summary="${output%.*}.summary.json"
php "$(dirname "$0")/parse-results.php" "$output" "$summary" "$profile"
