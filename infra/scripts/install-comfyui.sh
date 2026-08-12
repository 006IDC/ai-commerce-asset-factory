#!/usr/bin/env bash
set -euo pipefail

COMFYUI_VERSION="0.28.0"
COMFYUI_ARCHIVE="${1:-/tmp/comfyui-v${COMFYUI_VERSION}.tar.gz}"
CUSTOM_NODE_SOURCE="${2:-/tmp/comfyui-asset-factory-nodes}"
INSTALL_ROOT="/opt/comfyui"
EXPECTED_ARCHIVE_SHA256="5bc80c8ea85ba9a4b841be1f547222eb193e0b9534e79168bf482661f85dd3f1"

if [[ "${EUID}" -ne 0 ]]; then
  echo "This installer must run as root." >&2
  exit 1
fi
if [[ ! -f "${COMFYUI_ARCHIVE}" ]]; then
  echo "ComfyUI archive not found: ${COMFYUI_ARCHIVE}" >&2
  exit 1
fi
if [[ ! -f "${CUSTOM_NODE_SOURCE}/__init__.py" ]]; then
  echo "Asset Factory custom node source is incomplete." >&2
  exit 1
fi

actual_sha256="$(sha256sum "${COMFYUI_ARCHIVE}" | awk '{print $1}')"
if [[ "${actual_sha256}" != "${EXPECTED_ARCHIVE_SHA256}" ]]; then
  echo "ComfyUI archive checksum mismatch." >&2
  exit 1
fi
if [[ -e "${INSTALL_ROOT}" ]]; then
  echo "${INSTALL_ROOT} already exists; refusing to overwrite an installed service." >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends \
  ca-certificates \
  python3.12-venv \
  python3-pip \
  libgl1 \
  libglib2.0-0

if ! id -u comfyui >/dev/null 2>&1; then
  useradd --system --home-dir "${INSTALL_ROOT}" --shell /usr/sbin/nologin comfyui
fi

staging="$(mktemp -d /opt/comfyui-install.XXXXXX)"
trap 'rm -rf "${staging}"' EXIT
tar -xzf "${COMFYUI_ARCHIVE}" --strip-components=1 -C "${staging}"
printf '%s\n' "${COMFYUI_VERSION}" > "${staging}/ASSET_FACTORY_COMFYUI_VERSION"
python3.12 -m venv "${staging}/.venv"
"${staging}/.venv/bin/pip" install --disable-pip-version-check --upgrade "pip==25.1.1" "setuptools==80.9.0" "wheel==0.45.1"
"${staging}/.venv/bin/pip" install --disable-pip-version-check \
  --index-url https://download.pytorch.org/whl/cpu \
  torch torchvision torchaudio
"${staging}/.venv/bin/pip" install --disable-pip-version-check -r "${staging}/requirements.txt"
"${staging}/.venv/bin/pip" check
"${staging}/.venv/bin/pip" freeze > "${staging}/ASSET_FACTORY_REQUIREMENTS_LOCK.txt"

install -d -m 0750 "${staging}/custom_nodes/asset_factory_nodes"
cp -a "${CUSTOM_NODE_SOURCE}/." "${staging}/custom_nodes/asset_factory_nodes/"
find "${staging}/custom_nodes/asset_factory_nodes" -type d -name __pycache__ -prune -exec rm -rf {} +
install -d -m 0750 "${staging}/input" "${staging}/output" "${staging}/temp" "${staging}/user"
chown -R comfyui:comfyui "${staging}"
chmod -R o-rwx "${staging}"
mv "${staging}" "${INSTALL_ROOT}"
trap - EXIT

echo "ComfyUI ${COMFYUI_VERSION} installed at ${INSTALL_ROOT}."
