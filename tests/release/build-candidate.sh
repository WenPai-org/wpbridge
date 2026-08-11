#!/usr/bin/env bash
set -euo pipefail

version=""
output_dir="dist"
slug="wpbridge"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) version="$2"; shift 2 ;;
    --output-dir) output_dir="$2"; shift 2 ;;
    --slug) slug="$2"; shift 2 ;;
    *) echo "Unknown argument: $1" >&2; exit 64 ;;
  esac
done

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$repo_root"

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "Refusing to package a dirty tracked tree; commit the exact candidate first." >&2
  exit 2
fi

header_version="$(sed -n 's/^ \* Version:[[:space:]]*//p' wpbridge.php | head -1 | tr -d '\r')"
runtime_version="$(sed -n "s/^define( 'WPBRIDGE_VERSION', '\([^']*\)' );/\1/p" wpbridge.php | head -1)"
readme_version="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -1 | tr -d '\r')"
package_version="$(jq -r '.version' package.json)"
lock_version="$(jq -r '.version' package-lock.json)"
requires_wp="$(sed -n 's/^ \* Requires at least:[[:space:]]*//p' wpbridge.php | head -1 | tr -d '\r')"
requires_php="$(sed -n 's/^ \* Requires PHP:[[:space:]]*//p' wpbridge.php | head -1 | tr -d '\r')"
update_uri="$(sed -n 's/^ \* Update URI:[[:space:]]*//p' wpbridge.php | head -1 | tr -d '\r')"
version="${version:-$header_version}"

for pair in "header:$header_version" "runtime:$runtime_version" "readme:$readme_version" "package:$package_version" "lock:$lock_version"; do
  label="${pair%%:*}"; value="${pair#*:}"
  if [[ "$value" != "$version" ]]; then
    echo "Version mismatch: expected=$version $label=$value" >&2
    exit 3
  fi
done
if [[ "$requires_wp" != "5.9" || "$requires_php" != "7.4" ]]; then
  echo "Minimum runtime mismatch: WP=$requires_wp PHP=$requires_php" >&2
  exit 3
fi
if [[ "$update_uri" != "https://updates.wenpai.net" ]]; then
  echo "Unexpected Update URI: $update_uri" >&2
  exit 3
fi
if ! grep -Fq "= $version =" readme.txt; then
  echo "Missing readme changelog section for $version" >&2
  exit 3
fi

head="$(git rev-parse HEAD)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

mkdir -p "$tmp/source" "$tmp/build/$slug" "$output_dir"
output_dir="$(cd "$output_dir" && pwd)"
git archive HEAD | tar -x -C "$tmp/source"

rsync -a   --exclude=".git"   --exclude=".github"   --exclude=".forgejo"   --exclude=".gitignore"   --exclude=".gitleaks.toml"   --exclude=".wp-env.json"   --exclude=".gitattributes"   --exclude=".editorconfig"   --exclude=".env*"   --exclude=".agent"   --exclude=".vscode"   --exclude="node_modules"   --exclude="vendor"   --exclude="tests"   --exclude="docs"   --exclude="examples"   --exclude="backups"   --exclude="deploy.sh"   --exclude="lib"   --exclude="phpunit.xml*"   --exclude="phpcs*.xml*"   --exclude="phpstan.neon*"   --exclude="composer.json"   --exclude="composer.lock"   --exclude="package.json"   --exclude="package-lock.json"   --exclude="Gruntfile.js"   --exclude="webpack.config.js"   --exclude="playwright.config.js"   --exclude="*.md"   --exclude="Makefile"   "$tmp/source/" "$tmp/build/$slug/"

find "$tmp/build/$slug" -exec touch -h -t 198001010000 {} +
list="$tmp/file-list.txt"
(
  cd "$tmp/build"
  find "$slug" -print | LC_ALL=C sort > "$list"
)

zip_name="$slug-$version.zip"
zip_path="$output_dir/$zip_name"
rm -f "$zip_path"
(
  cd "$tmp/build"
  zip -X -q "$zip_path" -@ < "$list"
)

sha="$(sha256sum "$zip_path" | awk '{print $1}')"
size="$(wc -c < "$zip_path" | tr -d ' ')"
printf '%s  %s\n' "$sha" "$zip_name" > "$zip_path.sha256"

files='[]'
while IFS= read -r path; do
  [[ -f "$tmp/build/$path" ]] || continue
  file_sha="$(sha256sum "$tmp/build/$path" | awk '{print $1}')"
  file_size="$(wc -c < "$tmp/build/$path" | tr -d ' ')"
  mode="$(stat -c '%a' "$tmp/build/$path")"
  files="$(jq -c --arg path "$path" --arg sha256 "$file_sha" --argjson size "$file_size" --arg mode "$mode" '. + [{path:$path,sha256:$sha256,size:$size,mode:$mode}]' <<< "$files")"
done < "$list"

manifest="$output_dir/$slug-$version.manifest.json"
jq -n   --arg schema "wpbridge-release-manifest/v1"   --arg slug "$slug"   --arg version "$version"   --arg head "$head"   --arg update_uri "$update_uri"   --arg requires_wp "$requires_wp"   --arg requires_php "$requires_php"   --arg zip "$zip_name"   --arg zip_sha256 "$sha"   --argjson zip_size "$size"   --argjson files "$files"   '{schema:$schema,slug:$slug,version:$version,head:$head,update_uri:$update_uri,requires_wp:$requires_wp,requires_php:$requires_php,artifact:{file:$zip,sha256:$zip_sha256,size:$zip_size},files:$files}'   > "$manifest"

printf 'HEAD=%s\nVERSION=%s\nZIP=%s\nSHA256=%s\nMANIFEST=%s\n' "$head" "$version" "$zip_path" "$sha" "$manifest"
