#!/usr/bin/env bash

set -Eeuo pipefail

DEPLOY_DIR="$(cd "$(/usr/bin/dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PROJECT_DIR="$(/usr/bin/dirname "$DEPLOY_DIR")"
SERVER_DIR="$PROJECT_DIR/server"

if [[ "${1:-}" == 'uninstall' ]]; then
    echo '🗑️  正在卸载 MGames 服务...'
    /usr/bin/systemctl disable --now mgames.service
    /usr/bin/rm -f /etc/systemd/system/mgames.service
    /usr/bin/systemctl daemon-reload
    echo '✅ MGames 服务已停止并删除'
    exit 0
fi

cd "$PROJECT_DIR"

echo '🚀 开始发版'
echo '━━━━━━━━━━━━━━━━'
echo '⬇️  正在更新代码...'
BRANCH="$(/usr/bin/git branch --show-current)"
/usr/bin/git pull --ff-only origin "$BRANCH"

echo "🌿 分支: $BRANCH"
echo "🔖 版本: $(/usr/bin/git rev-parse --short HEAD)"
echo "👤 作者: $(/usr/bin/git log -1 --pretty='%an')"
echo "📝 说明: $(/usr/bin/git log -1 --pretty='%s')"

echo '📦 正在安装后端依赖...'
cd "$SERVER_DIR"
/www/server/php/84/bin/php /usr/bin/composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

echo '🎨 正在构建管理端...'
cd "$PROJECT_DIR/saiadmin-artd"
/usr/bin/corepack pnpm install --frozen-lockfile
/usr/bin/corepack pnpm build

echo '🗄️  正在升级数据库...'
cd "$SERVER_DIR"
/www/server/php/84/bin/php webman db:upgrade --dry-run
/www/server/php/84/bin/php webman db:upgrade

if [[ ! -f /etc/systemd/system/mgames.service ]]; then
    echo '⚙️  正在安装 MGames 服务...'
    /usr/bin/install -m 0644 "$DEPLOY_DIR/mgames.service" /etc/systemd/system/mgames.service
    /usr/bin/sed -i "s|^WorkingDirectory=.*|WorkingDirectory=$SERVER_DIR|" /etc/systemd/system/mgames.service
    /usr/bin/systemctl daemon-reload
    /usr/bin/systemctl enable mgames.service
fi

echo '♻️  正在重启 MGames 服务...'
START_TIME=$SECONDS
/usr/bin/systemctl restart mgames.service

HTTP_CODE=''
for ((i = 0; i < 30; i++)); do
    HTTP_CODE=$(/usr/bin/curl -sS -o /dev/null --connect-timeout 1 --max-time 3 -w '%{http_code}' http://127.0.0.1:8787/game/context || true)
    [[ "$HTTP_CODE" == '200' ]] && break
    /usr/bin/sleep 1
done

[[ "$HTTP_CODE" == '200' ]] || { echo '❌ MGames HTTP 健康检查失败'; exit 1; }

echo
echo '✅ 发版完成'
echo '━━━━━━━━━━━━━━━━'
echo "⏱️  服务恢复: $((SECONDS - START_TIME)) 秒"
echo "⚙️  服务状态: $(/usr/bin/systemctl is-active mgames.service)"
echo "🔄 开机启动: $(/usr/bin/systemctl is-enabled mgames.service)"
echo "🪪 主进程 PID: $(/usr/bin/systemctl show mgames.service --property=MainPID --value)"
echo "🌐 HTTP 检查: $HTTP_CODE"
