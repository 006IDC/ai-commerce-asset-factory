#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    printf '请使用 root 执行：sudo bash scripts/install-worker-service.sh\n' >&2
    exit 1
fi

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN="${WORKER_PHP:-$(command -v php || true)}"
WORKER_USER="${WORKER_USER:-www-data}"
SERVICE_NAME="asset-factory-worker@.service"
SERVICE_FILE="/etc/systemd/system/$SERVICE_NAME"
VIDEO_SERVICE_NAME="asset-factory-video-worker.service"
VIDEO_SERVICE_FILE="/etc/systemd/system/$VIDEO_SERVICE_NAME"

if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
    printf '未找到 PHP CLI。宝塔可这样指定：WORKER_PHP=/www/server/php/83/bin/php bash scripts/install-worker-service.sh\n' >&2
    exit 1
fi
if ! id "$WORKER_USER" >/dev/null 2>&1; then
    printf '运行用户不存在：%s。可通过 WORKER_USER=www 指定宝塔站点用户。\n' "$WORKER_USER" >&2
    exit 1
fi
if [[ ! -f "$ROOT_DIR/think" || ! -f "$ROOT_DIR/.env" ]]; then
    printf '请完成网页安装后，在程序根目录执行本脚本。\n' >&2
    exit 1
fi

install -d -m 0775 -o "$WORKER_USER" -g "$WORKER_USER" "$ROOT_DIR/runtime" "$ROOT_DIR/public/storage"

printf '%s\n' \
    '[Unit]' \
    'Description=AI Commerce Asset Factory Generation Worker Slot %i' \
    'After=network-online.target' \
    'Wants=network-online.target' \
    '' \
    '[Service]' \
    'Type=simple' \
    "User=$WORKER_USER" \
    "Group=$WORKER_USER" \
    "WorkingDirectory=$ROOT_DIR" \
    "ExecStart=$PHP_BIN $ROOT_DIR/think jobs:work --slot=%i" \
    'Restart=always' \
    'RestartSec=5' \
    'UMask=0002' \
    'NoNewPrivileges=true' \
    'PrivateTmp=true' \
    'ProtectHome=true' \
    'ProtectSystem=strict' \
    "ReadWritePaths=$ROOT_DIR/runtime $ROOT_DIR/public/storage $ROOT_DIR/database" \
    '' \
    '[Install]' \
    'WantedBy=multi-user.target' > "$SERVICE_FILE"

printf '%s\n' \
    '[Unit]' \
    'Description=AI Commerce Asset Factory Asynchronous Video Worker' \
    'After=network-online.target' \
    'Wants=network-online.target' \
    '' \
    '[Service]' \
    'Type=simple' \
    "User=$WORKER_USER" \
    "Group=$WORKER_USER" \
    "WorkingDirectory=$ROOT_DIR" \
    "ExecStart=$PHP_BIN $ROOT_DIR/think jobs:work-video" \
    'Restart=always' \
    'RestartSec=5' \
    'UMask=0002' \
    'NoNewPrivileges=true' \
    'PrivateTmp=true' \
    'ProtectHome=true' \
    'ProtectSystem=strict' \
    "ReadWritePaths=$ROOT_DIR/runtime $ROOT_DIR/public/storage $ROOT_DIR/database" \
    '' \
    '[Install]' \
    'WantedBy=multi-user.target' > "$VIDEO_SERVICE_FILE"

systemctl daemon-reload
systemctl disable --now asset-factory-worker.service >/dev/null 2>&1 || true
for slot in {1..10}; do
    systemctl enable --now "asset-factory-worker@${slot}.service"
done
systemctl enable --now "$VIDEO_SERVICE_NAME"
systemctl --no-pager --full status asset-factory-worker@1.service | sed -n '1,12p'
systemctl --no-pager --full status "$VIDEO_SERVICE_NAME" | sed -n '1,12p'
printf '10 个图片 Worker 槽位与 1 个异步视频 Worker 已安装并设置为开机自启。\n'
