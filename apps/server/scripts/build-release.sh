#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PROJECT_ROOT="${PROJECT_ROOT:-$(cd "$ROOT_DIR/../.." && pwd)}"
VERSION="${1:-$(date +%Y%m%d-%H%M)}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
DIST_DIR="${DIST_DIR:-$ROOT_DIR/dist}"
SOURCE_REVISION="${SOURCE_REVISION:-}"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-}"
ALLOW_UNVERSIONED_RELEASE="${ALLOW_UNVERSIONED_RELEASE:-0}"
WORK_DIR="$(mktemp -d)"
PACKAGE_DIR="$WORK_DIR/asset-factory"
ARCHIVE="$DIST_DIR/asset-factory-thinkphp-$VERSION.zip"

if [[ ! "$VERSION" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]]; then
    printf 'Invalid release version: %s\n' "$VERSION" >&2
    exit 2
fi

if git -C "$PROJECT_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    GIT_REVISION="$(git -C "$PROJECT_ROOT" rev-parse HEAD)"
    if [[ -z "$SOURCE_REVISION" ]]; then
        SOURCE_REVISION="$GIT_REVISION"
    fi
    if [[ "$SOURCE_REVISION" != "$GIT_REVISION" && "$ALLOW_UNVERSIONED_RELEASE" != "1" ]]; then
        printf 'SOURCE_REVISION must match the current Git commit: %s\n' "$GIT_REVISION" >&2
        exit 2
    fi
    if [[ -n "$(git -C "$PROJECT_ROOT" status --porcelain --untracked-files=all)" && "$ALLOW_UNVERSIONED_RELEASE" != "1" ]]; then
        printf 'Refusing a production release from a dirty Git worktree. Commit or explicitly set ALLOW_UNVERSIONED_RELEASE=1.\n' >&2
        exit 2
    fi
    if [[ -z "$SOURCE_DATE_EPOCH" ]]; then
        SOURCE_DATE_EPOCH="$(git -C "$PROJECT_ROOT" show -s --format=%ct "$GIT_REVISION")"
    fi
fi
if [[ ! "$SOURCE_REVISION" =~ ^[0-9a-f]{40}$ && "$ALLOW_UNVERSIONED_RELEASE" != "1" ]]; then
    printf 'SOURCE_REVISION must be a full 40-character Git commit hash.\n' >&2
    exit 2
fi
if [[ -z "$SOURCE_REVISION" ]]; then
    SOURCE_REVISION="unversioned"
fi
if [[ -z "$SOURCE_DATE_EPOCH" ]]; then
    SOURCE_DATE_EPOCH="$(date +%s)"
fi
if [[ ! "$SOURCE_DATE_EPOCH" =~ ^[0-9]+$ ]]; then
    printf 'SOURCE_DATE_EPOCH must be an integer.\n' >&2
    exit 2
fi

cleanup() {
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

mkdir -p "$DIST_DIR" "$PACKAGE_DIR"
rm -f "$ARCHIVE"
rsync -a "$ROOT_DIR/" "$PACKAGE_DIR/" \
    --exclude '.env' \
    --exclude '.git' \
    --exclude '.travis.yml' \
    --exclude 'database/*.sqlite' \
    --exclude 'database/*.sqlite-*' \
    --exclude 'dist' \
    --exclude 'node_modules' \
    --exclude 'vendor' \
    --exclude 'artifacts' \
    --exclude 'docs' \
    --exclude 'runtime/*' \
    --exclude 'public/storage/*' \
    --exclude 'tests' \
    --exclude '.DS_Store' \
    --exclude '._*'

mkdir -p "$PACKAGE_DIR/runtime" "$PACKAGE_DIR/public/storage" "$PACKAGE_DIR/database"
if ! command -v "$COMPOSER_BIN" >/dev/null 2>&1; then
    printf 'Composer executable not found: %s\n' "$COMPOSER_BIN" >&2
    exit 2
fi
"$COMPOSER_BIN" validate --working-dir="$PACKAGE_DIR" --strict
"$COMPOSER_BIN" install \
    --working-dir="$PACKAGE_DIR" \
    --no-dev \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --classmap-authoritative \
    --no-interaction
php -r '
$installed = require $argv[1] . "/vendor/composer/installed.php";
$versions = $installed["versions"] ?? [];
foreach (["topthink/think-dumper", "topthink/think-trace", "symfony/var-dumper"] as $package) {
    if (isset($versions[$package])) {
        fwrite(STDERR, "Development dependency leaked into release: {$package}\n");
        exit(1);
    }
}
' "$PACKAGE_DIR"
mkdir -p "$PACKAGE_DIR/resources/comfyui/custom_nodes/asset_factory_nodes"
cp "$PROJECT_ROOT/infra/comfyui/requirements-0.28.0-cpu.lock" \
    "$PACKAGE_DIR/resources/comfyui/requirements-0.28.0-cpu.lock"
cp "$PROJECT_ROOT/infra/systemd/comfyui.service" \
    "$PACKAGE_DIR/resources/comfyui/comfyui.service"
cp "$PROJECT_ROOT/infra/systemd/comfyui-slot@.service" \
    "$PACKAGE_DIR/resources/comfyui/comfyui-slot@.service"
rsync -a --delete \
    --exclude '__pycache__' \
    --exclude '*.pyc' \
    "$PROJECT_ROOT/services/comfyui-asset-factory-nodes/" \
    "$PACKAGE_DIR/resources/comfyui/custom_nodes/asset_factory_nodes/"
chmod +x "$PACKAGE_DIR/scripts/manage-comfyui-runtime.sh"
chmod +x "$PACKAGE_DIR/scripts/install-comfyui-slots.sh"
find "$PACKAGE_DIR" -name '._*' -delete
find "$PACKAGE_DIR" -name '.DS_Store' -delete
rm -f "$PACKAGE_DIR/runtime/install.lock"

php -r '
$root = $argv[1];
$installed = require $root . "/vendor/composer/installed.php";
$dependencies = [];
foreach (($installed["versions"] ?? []) as $name => $package) {
    if ($name === "topthink/think" || !isset($package["pretty_version"])) {
        continue;
    }
    $dependencies[$name] = (string) $package["pretty_version"];
}
ksort($dependencies, SORT_STRING);
$manifest = [
    "release" => $argv[2],
    "source_revision" => $argv[3],
    "source_date_epoch" => (int) $argv[4],
    "php_platform" => "8.3.6",
    "production_dependencies" => $dependencies,
];
file_put_contents(
    $root . "/BUILD_MANIFEST.json",
    json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
);
' "$PACKAGE_DIR" "$VERSION" "$SOURCE_REVISION" "$SOURCE_DATE_EPOCH"

(
    cd "$PACKAGE_DIR"
    zip -qr "$ARCHIVE" .
)

printf '%s\n' "$ARCHIVE"
