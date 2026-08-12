#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
    printf '%s\n' '请使用 root 运行 ComfyUI 多执行槽安装脚本。' >&2
    exit 1
fi

SERVER_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
UNIT_SOURCE="${SERVER_ROOT}/resources/comfyui/comfyui-slot@.service"
if [[ ! -f "${UNIT_SOURCE}" ]]; then
    printf '%s\n' "缺少多执行槽 unit：${UNIT_SOURCE}" >&2
    exit 1
fi
if [[ ! -x /opt/comfyui/.venv/bin/python ]]; then
    printf '%s\n' 'ComfyUI 运行时尚未安装。' >&2
    exit 1
fi

install -m 0644 "${UNIT_SOURCE}" /etc/systemd/system/comfyui-slot@.service
install -d -m 0750 -o root -g comfyui /etc/asset-factory/comfyui-slots
printf 'COMFYUI_PORT=8189\n' > /etc/asset-factory/comfyui-slots/2.env
printf 'COMFYUI_PORT=8190\n' > /etc/asset-factory/comfyui-slots/3.env
chown root:comfyui /etc/asset-factory/comfyui-slots/2.env /etc/asset-factory/comfyui-slots/3.env
chmod 0640 /etc/asset-factory/comfyui-slots/2.env /etc/asset-factory/comfyui-slots/3.env

systemctl daemon-reload
systemctl enable --now comfyui-slot@2.service comfyui-slot@3.service
systemctl is-active --quiet comfyui-slot@2.service
systemctl is-active --quiet comfyui-slot@3.service
printf '%s\n' 'ComfyUI 执行槽 2、3 已启动（127.0.0.1:8189、127.0.0.1:8190）。'
