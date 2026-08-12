#!/usr/bin/env bash
set -Eeuo pipefail

MANAGER_VERSION="1.2.0"
COMFYUI_VERSION="0.28.0"
COMFYUI_ARCHIVE_SHA256="5bc80c8ea85ba9a4b841be1f547222eb193e0b9534e79168bf482661f85dd3f1"
COMFYUI_ARCHIVE_URL="https://github.com/Comfy-Org/ComfyUI/archive/refs/tags/v${COMFYUI_VERSION}.tar.gz"
INSTALL_ROOT="${COMFYUI_INSTALL_ROOT:-/opt/comfyui}"
DATA_ROOT="${COMFYUI_DATA_ROOT:-/var/lib/asset-factory/comfyui}"
CACHE_ROOT="${COMFYUI_CACHE_ROOT:-/var/cache/asset-factory}"
BASE_URL="${COMFYUI_BASE_URL:-http://127.0.0.1:8188}"
SERVICE_NAME="comfyui.service"
SERVICE_TARGET="/etc/systemd/system/${SERVICE_NAME}"
ENV_DIR="/etc/asset-factory"
ENV_TARGET="${ENV_DIR}/comfyui.env"
SUB2API_CREDENTIAL_SOURCE="${ENV_DIR}/sub2api/asset-factory-comfyui-production.env"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVER_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
PROJECT_ROOT="$(cd "${SERVER_ROOT}/../.." && pwd)"
PACKAGED_RESOURCE_ROOT="${SERVER_ROOT}/resources/comfyui"
REPO_RESOURCE_ROOT="${PROJECT_ROOT}/infra"
REPO_NODE_ROOT="${PROJECT_ROOT}/services/comfyui-asset-factory-nodes"

COMMAND="${1:-status}"
if [[ $# -gt 0 ]]; then
    shift
fi
ARCHIVE_PATH=""
FORCE=0

usage() {
    cat <<'EOF'
Usage:
  manage-comfyui-runtime.sh check
  manage-comfyui-runtime.sh status
  sudo manage-comfyui-runtime.sh install [--archive /path/to/archive]
  sudo manage-comfyui-runtime.sh upgrade [--archive /path/to/archive] [--force]
  sudo manage-comfyui-runtime.sh repair [--force]

Commands:
  check    Validate packaged runtime resources without changing the server.
  status   Show service, API, queue, version, and controlled-node status.
  install  Install the reviewed ComfyUI runtime and systemd service.
  upgrade  Rebuild the reviewed runtime, preserve data, and roll back on failure.
  repair   Re-sync the controlled node and service definition, then restart.

The runtime listens on 127.0.0.1:8188 and is not exposed to merchant browsers.
EOF
}

log() {
    printf '[comfyui-manager] %s\n' "$*" >&2
}

fail() {
    printf '[comfyui-manager] ERROR: %s\n' "$*" >&2
    exit 1
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --archive)
            [[ $# -ge 2 ]] || fail "--archive requires a file path."
            ARCHIVE_PATH="$2"
            shift 2
            ;;
        --force)
            FORCE=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            fail "Unknown option: $1"
            ;;
    esac
done

first_existing_path() {
    local candidate
    for candidate in "$@"; do
        if [[ -e "${candidate}" ]]; then
            printf '%s\n' "${candidate}"
            return 0
        fi
    done
    return 1
}

REQUIREMENTS_SOURCE="$(first_existing_path \
    "${PACKAGED_RESOURCE_ROOT}/requirements-${COMFYUI_VERSION}-cpu.lock" \
    "${REPO_RESOURCE_ROOT}/comfyui/requirements-${COMFYUI_VERSION}-cpu.lock" || true)"
SERVICE_SOURCE="$(first_existing_path \
    "${PACKAGED_RESOURCE_ROOT}/comfyui.service" \
    "${REPO_RESOURCE_ROOT}/systemd/comfyui.service" || true)"
NODE_SOURCE="$(first_existing_path \
    "${PACKAGED_RESOURCE_ROOT}/custom_nodes/asset_factory_nodes" \
    "${REPO_NODE_ROOT}" || true)"

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command is missing: $1"
}

require_root() {
    [[ "${EUID}" -eq 0 ]] || fail "This command must run as root. Re-run it with sudo."
}

check_resources() {
    [[ -n "${REQUIREMENTS_SOURCE}" && -f "${REQUIREMENTS_SOURCE}" ]] \
        || fail "Pinned Python requirements are missing from the release package."
    [[ -n "${SERVICE_SOURCE}" && -f "${SERVICE_SOURCE}" ]] \
        || fail "The ComfyUI systemd unit is missing from the release package."
    [[ -n "${NODE_SOURCE}" && -f "${NODE_SOURCE}/__init__.py" && -f "${NODE_SOURCE}/asset_factory_nodes.py" ]] \
        || fail "The controlled Asset Factory node is missing or incomplete."
}

require_sub2api_credential() {
    [[ -f "${SUB2API_CREDENTIAL_SOURCE}" ]] \
        || fail "The protected ComfyUI Sub2API service credential is missing: ${SUB2API_CREDENTIAL_SOURCE}"
    [[ "$(stat -c '%a' "${SUB2API_CREDENTIAL_SOURCE}")" == "600" ]] \
        || fail "The ComfyUI Sub2API credential must use mode 0600."
    [[ "$(stat -c '%U:%G' "${SUB2API_CREDENTIAL_SOURCE}")" == "root:root" ]] \
        || fail "The ComfyUI Sub2API credential must be owned by root:root."
}

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
        return
    fi
    shasum -a 256 "$1" | awk '{print $1}'
}

prepare_archive() {
    require_command curl
    require_command tar
    local archive="${ARCHIVE_PATH}"
    if [[ -z "${archive}" ]]; then
        archive="${CACHE_ROOT}/comfyui-v${COMFYUI_VERSION}.tar.gz"
        install -d -m 0750 "${CACHE_ROOT}"
        if [[ ! -f "${archive}" ]]; then
            log "Downloading reviewed ComfyUI v${COMFYUI_VERSION} source archive."
            curl --fail --location --retry 3 --retry-delay 2 \
                --output "${archive}.download" "${COMFYUI_ARCHIVE_URL}"
            mv "${archive}.download" "${archive}"
        fi
    fi
    [[ -f "${archive}" ]] || fail "ComfyUI archive not found: ${archive}"
    local actual_hash
    actual_hash="$(sha256_file "${archive}")"
    [[ "${actual_hash}" == "${COMFYUI_ARCHIVE_SHA256}" ]] \
        || fail "ComfyUI archive checksum mismatch. Refusing to install unreviewed source."
    printf '%s\n' "${archive}"
}

queue_counts() {
    local body
    body="$(curl --fail --silent --show-error --max-time 5 "${BASE_URL}/queue" 2>/dev/null || true)"
    if [[ -z "${body}" ]]; then
        printf '0 0\n'
        return
    fi
    python3 -c '
import json, sys
try:
    payload = json.load(sys.stdin)
except Exception:
    print("0 0")
    raise SystemExit(0)
def count(value):
    if isinstance(value, list):
        return len(value)
    if isinstance(value, dict):
        return len(value)
    try:
        return max(0, int(value))
    except Exception:
        return 0
print(count(payload.get("queue_running", payload.get("running", []))),
      count(payload.get("queue_pending", payload.get("pending", []))))
' <<<"${body}"
}

guard_idle_queue() {
    [[ "${FORCE}" -eq 1 ]] && return
    local running pending
    read -r running pending < <(queue_counts)
    if (( running > 0 || pending > 0 )); then
        fail "ComfyUI has ${running} running and ${pending} pending task(s). Wait for the queue or use --force."
    fi
}

install_system_packages() {
    export DEBIAN_FRONTEND=noninteractive
    require_command apt-get
    apt-get update
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libgl1 \
        libglib2.0-0 \
        fonts-noto-cjk \
        python3.12-venv \
        python3-pip \
        rsync
}

ensure_runtime_user() {
    if ! id -u comfyui >/dev/null 2>&1; then
        useradd --system --home-dir "${INSTALL_ROOT}" --shell /usr/sbin/nologin comfyui
    fi
}

ensure_environment_file() {
    install -d -m 0750 -o root -g comfyui "${ENV_DIR}"
    umask 0077
    {
        printf '# Managed by Asset Factory ComfyUI runtime manager.\n'
        printf 'ASSET_FACTORY_CONTROL_PLANE_URL=http://127.0.0.1\n'
        printf 'ASSET_FACTORY_CONTROL_PLANE_HOST=studio.example.com\n'
    } > "${ENV_TARGET}"
    chown root:comfyui "${ENV_TARGET}"
    chmod 0640 "${ENV_TARGET}"
}

sync_controlled_node() {
    local root="$1"
    local target="${root}/custom_nodes/asset_factory_nodes"
    install -d -m 0750 "${target}"
    rsync -a --delete \
        --exclude '__pycache__' \
        --exclude '*.pyc' \
        "${NODE_SOURCE}/" "${target}/"
}

write_runtime_manifest() {
    local root="$1"
    printf '%s\n' "${COMFYUI_VERSION}" > "${root}/ASSET_FACTORY_COMFYUI_VERSION"
    cat > "${root}/ASSET_FACTORY_RUNTIME_MANIFEST.json" <<EOF
{
  "runtime": "comfyui",
  "version": "${COMFYUI_VERSION}",
  "manager_version": "${MANAGER_VERSION}",
  "archive_sha256": "${COMFYUI_ARCHIVE_SHA256}",
  "installed_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF
}

link_shared_data() {
    local root="$1"
    local name target current
    install -d -m 0750 -o comfyui -g comfyui "${DATA_ROOT}"
    for name in input output temp user; do
        target="${DATA_ROOT}/${name}"
        install -d -m 0750 -o comfyui -g comfyui "${target}"
        current="${root}/${name}"
        if [[ -L "${current}" ]]; then
            continue
        fi
        if [[ -d "${current}" ]]; then
            if [[ -z "$(find "${target}" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
                rmdir "${target}"
                mv "${current}" "${target}"
                chown -R comfyui:comfyui "${target}"
            else
                rsync -a "${current}/" "${target}/"
                rm -rf "${current}"
            fi
        elif [[ -e "${current}" ]]; then
            fail "Cannot manage unexpected runtime path: ${current}"
        fi
        ln -s "${target}" "${current}"
    done
}

build_staging_runtime() {
    local archive="$1"
    local staging="$2"
    mkdir -p "${staging}"
    tar -xzf "${archive}" --strip-components=1 -C "${staging}"
    [[ -f "${staging}/main.py" ]] || fail "The reviewed archive does not contain ComfyUI main.py."
    python3.12 -m venv "${staging}/.venv"
    "${staging}/.venv/bin/pip" install --disable-pip-version-check \
        --upgrade "pip==25.1.1" "setuptools==80.9.0" "wheel==0.45.1"
    "${staging}/.venv/bin/pip" install --disable-pip-version-check \
        --extra-index-url https://download.pytorch.org/whl/cpu \
        -r "${REQUIREMENTS_SOURCE}"
    "${staging}/.venv/bin/pip" check
    sync_controlled_node "${staging}"
    write_runtime_manifest "${staging}"
    chown -R comfyui:comfyui "${staging}"
    chmod -R o-rwx "${staging}"
}

install_service_definition() {
    require_sub2api_credential
    ensure_environment_file
    install -d -m 0700 -o comfyui -g comfyui "${DATA_ROOT}/provider-state"
    install -m 0644 "${SERVICE_SOURCE}" "${SERVICE_TARGET}"
    systemctl daemon-reload
    systemctl enable "${SERVICE_NAME}" >/dev/null
}

wait_for_health() {
    local attempts="${1:-60}"
    local attempt
    for ((attempt = 1; attempt <= attempts; attempt++)); do
        if curl --fail --silent --show-error --max-time 4 \
            "${BASE_URL}/system_stats" >/dev/null 2>&1 \
            && curl --fail --silent --show-error --max-time 4 \
            "${BASE_URL}/object_info/AssetFactoryRoutedImage" >/dev/null 2>&1 \
            && curl --fail --silent --show-error --max-time 4 \
            "${BASE_URL}/object_info/AssetFactorySub2ApiImage" >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    return 1
}

show_status() {
    local installed_version="not installed"
    local service_state="not installed"
    local api_state="offline"
    local controlled_node="unavailable"
    local provider_node="unavailable"
    local running=0
    local pending=0

    if [[ -f "${INSTALL_ROOT}/ASSET_FACTORY_COMFYUI_VERSION" ]]; then
        installed_version="$(tr -d '[:space:]' < "${INSTALL_ROOT}/ASSET_FACTORY_COMFYUI_VERSION")"
    fi
    if command -v systemctl >/dev/null 2>&1 && systemctl cat "${SERVICE_NAME}" >/dev/null 2>&1; then
        service_state="$(systemctl is-active "${SERVICE_NAME}" 2>/dev/null || true)"
    fi
    if command -v curl >/dev/null 2>&1 \
        && curl --fail --silent --show-error --max-time 4 "${BASE_URL}/system_stats" >/dev/null 2>&1; then
        api_state="ready"
        read -r running pending < <(queue_counts)
        if curl --fail --silent --show-error --max-time 4 \
            "${BASE_URL}/object_info/AssetFactoryRoutedImage" >/dev/null 2>&1; then
            controlled_node="ready"
        fi
        if curl --fail --silent --show-error --max-time 4 \
            "${BASE_URL}/object_info/AssetFactorySub2ApiImage" >/dev/null 2>&1; then
            provider_node="ready"
        fi
    fi

    printf 'ComfyUI runtime\n'
    printf '  managed version: %s\n' "${installed_version}"
    printf '  systemd service: %s\n' "${service_state}"
    printf '  API:             %s (%s)\n' "${api_state}" "${BASE_URL}"
    printf '  queue:           %s running / %s pending\n' "${running}" "${pending}"
    printf '  controlled node: %s\n' "${controlled_node}"
    printf '  Sub2API V2 node: %s\n' "${provider_node}"

    [[ "${api_state}" == "ready" && "${controlled_node}" == "ready" && "${provider_node}" == "ready" ]]
}

install_runtime() {
    require_root
    check_resources
    [[ ! -e "${INSTALL_ROOT}" ]] || fail "${INSTALL_ROOT} already exists. Use repair or upgrade."
    install_system_packages
    ensure_runtime_user
    local archive staging
    archive="$(prepare_archive)"
    staging="$(mktemp -d "$(dirname "${INSTALL_ROOT}")/comfyui-install.XXXXXX")"
    trap 'rm -rf "${staging:-}"' EXIT
    build_staging_runtime "${archive}" "${staging}"
    link_shared_data "${staging}"
    mv "${staging}" "${INSTALL_ROOT}"
    trap - EXIT
    install_service_definition
    systemctl restart "${SERVICE_NAME}"
    if ! wait_for_health 60; then
        systemctl status "${SERVICE_NAME}" --no-pager >&2 || true
        journalctl -u "${SERVICE_NAME}" -n 80 --no-pager >&2 || true
        fail "ComfyUI did not become healthy after installation."
    fi
    log "ComfyUI v${COMFYUI_VERSION} installed and healthy on ${BASE_URL}."
}

upgrade_runtime() {
    require_root
    check_resources
    if [[ ! -d "${INSTALL_ROOT}" ]]; then
        install_runtime
        return
    fi
    guard_idle_queue
    install_system_packages
    ensure_runtime_user
    local archive staging backup failed_root
    archive="$(prepare_archive)"
    staging="$(mktemp -d "$(dirname "${INSTALL_ROOT}")/comfyui-upgrade.XXXXXX")"
    trap 'rm -rf "${staging:-}"' EXIT
    build_staging_runtime "${archive}" "${staging}"
    systemctl stop "${SERVICE_NAME}" 2>/dev/null || true
    link_shared_data "${INSTALL_ROOT}"
    link_shared_data "${staging}"
    backup="${INSTALL_ROOT}.backup-$(date +%Y%m%d%H%M%S)"
    mv "${INSTALL_ROOT}" "${backup}"
    mv "${staging}" "${INSTALL_ROOT}"
    trap - EXIT
    install_service_definition
    systemctl restart "${SERVICE_NAME}"
    if wait_for_health 60; then
        log "ComfyUI upgraded to v${COMFYUI_VERSION}. Rollback copy retained at ${backup}."
        return
    fi

    log "Health check failed. Restoring the previous runtime."
    systemctl stop "${SERVICE_NAME}" 2>/dev/null || true
    failed_root="${INSTALL_ROOT}.failed-$(date +%Y%m%d%H%M%S)"
    mv "${INSTALL_ROOT}" "${failed_root}"
    mv "${backup}" "${INSTALL_ROOT}"
    systemctl restart "${SERVICE_NAME}"
    wait_for_health 60 || true
    fail "Upgrade failed and the previous runtime was restored. Failed build retained at ${failed_root}."
}

repair_runtime() {
    require_root
    check_resources
    [[ -x "${INSTALL_ROOT}/.venv/bin/python" ]] \
        || fail "ComfyUI is not installed at ${INSTALL_ROOT}. Use install first."
    guard_idle_queue
    ensure_runtime_user
    systemctl stop "${SERVICE_NAME}" 2>/dev/null || true
    link_shared_data "${INSTALL_ROOT}"
    sync_controlled_node "${INSTALL_ROOT}"
    write_runtime_manifest "${INSTALL_ROOT}"
    chown -R comfyui:comfyui "${INSTALL_ROOT}/custom_nodes/asset_factory_nodes"
    install_service_definition
    systemctl restart "${SERVICE_NAME}"
    if ! wait_for_health 60; then
        systemctl status "${SERVICE_NAME}" --no-pager >&2 || true
        journalctl -u "${SERVICE_NAME}" -n 80 --no-pager >&2 || true
        fail "ComfyUI repair completed, but the runtime did not become healthy."
    fi
    log "ComfyUI runtime definition and controlled node were repaired successfully."
}

case "${COMMAND}" in
    check)
        check_resources
        require_command curl
        require_command python3
        require_command rsync
        require_command tar
        log "Runtime resources are complete for reviewed ComfyUI v${COMFYUI_VERSION}."
        ;;
    status)
        show_status
        ;;
    install)
        install_runtime
        ;;
    upgrade)
        upgrade_runtime
        ;;
    repair)
        repair_runtime
        ;;
    *)
        usage >&2
        fail "Unknown command: ${COMMAND}"
        ;;
esac
