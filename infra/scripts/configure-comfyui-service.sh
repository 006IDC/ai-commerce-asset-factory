#!/usr/bin/env bash
set -euo pipefail

CONTROL_PLANE_HOST="${1:-studio.example.com}"
SERVICE_SOURCE="${2:-/tmp/comfyui.service}"
COMFY_ENV_DIR="/etc/asset-factory"
COMFY_ENV_FILE="${COMFY_ENV_DIR}/comfyui.env"
SERVICE_TARGET="/etc/systemd/system/comfyui.service"

if [[ "${EUID}" -ne 0 ]]; then
  echo "This service setup must run as root." >&2
  exit 1
fi
if [[ ! -f "${SERVICE_SOURCE}" ]]; then
  echo "ComfyUI systemd unit not found: ${SERVICE_SOURCE}" >&2
  exit 1
fi
if [[ ! -x /opt/comfyui/.venv/bin/python ]]; then
  echo "ComfyUI runtime is not installed at /opt/comfyui." >&2
  exit 1
fi

install -d -m 0750 -o root -g comfyui "${COMFY_ENV_DIR}"
install -d -m 0750 -o comfyui -g comfyui \
  /var/lib/asset-factory/comfyui \
  /var/lib/asset-factory/comfyui/input \
  /var/lib/asset-factory/comfyui/output \
  /var/lib/asset-factory/comfyui/temp \
  /var/lib/asset-factory/comfyui/user
umask 0077
{
  printf '# ThinkPHP is the only model-account and billing control plane.\n'
  printf 'ASSET_FACTORY_CONTROL_PLANE_URL=http://127.0.0.1\n'
  printf 'ASSET_FACTORY_CONTROL_PLANE_HOST=%s\n' "${CONTROL_PLANE_HOST}"
} > "${COMFY_ENV_FILE}"
chown root:comfyui "${COMFY_ENV_FILE}"
chmod 0640 "${COMFY_ENV_FILE}"

install -m 0644 "${SERVICE_SOURCE}" "${SERVICE_TARGET}"
systemctl daemon-reload
systemctl enable --now comfyui.service

for _ in {1..30}; do
  if curl --fail --silent --show-error \
    http://127.0.0.1:8188/object_info/AssetFactoryRoutedImage \
    >/dev/null; then
    echo "ComfyUI is active on loopback and the controlled image node is available."
    exit 0
  fi
  sleep 1
done

systemctl status comfyui.service --no-pager >&2 || true
journalctl -u comfyui.service -n 80 --no-pager >&2 || true
echo "ComfyUI did not become ready within 30 seconds." >&2
exit 1
