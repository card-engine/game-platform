# MGames 发版说明

`deploy.sh` 负责更新代码、安装依赖、构建管理端、升级数据库和重启 Webman。首次发布时会自动安装根目录的 `mgames.service`。

## 环境要求

- Linux 使用 systemd。
- 以 `root` 用户执行脚本。
- PHP 8.4 安装在 `/www/server/php/84/bin/php`。
- Composer 安装在 `/usr/bin/composer`，由 PHP 8.4 执行。
- Git 和 Corepack 安装在 `/usr/bin/`。
- 存在 `www` 用户和用户组。
- `server/.env` 已正确配置数据库、Redis 和业务参数。
- 首次安装前配置 `INITIAL_ADMIN_PASSWORD` 和 `INITIAL_GAME_ADMIN_PASSWORD`。

## 发版

```bash
cd /www/wwwroot/game-platform
./deploy.sh
```

脚本会以自身所在目录为项目根目录，并依次执行：

1. 拉取当前 Git 分支。
2. 安装生产环境 Composer 依赖。
3. 安装并构建前端依赖。
4. 预览并执行数据库升级。
5. 首次发布时安装并启用 `mgames.service`。
6. 重启 Webman，检查 systemd 状态和 HTTP 响应。

`mgames.service` 已存在时，脚本不会覆盖它。

### 大厅 PWA 缓存

将 `simple-vue/nginx-cache.conf` 包含在大厅对应的 Nginx `server` 块内，检查 `nginx -t` 后 reload。仅入口 HTML、`sw.js` 和 manifest 不做长期缓存，带 hash 的 `/assets/` 资源继续缓存。

若 CDN 已缓存旧 `sw.js`，还需失效该 URL 的旧缓存；改变源站响应头不会立即清除已存在的 CDN 缓存。发布验收应核对实际加载的 JS hash，不以页面刷新成功代替版本确认。

## 服务管理

### 首次切换充值新表

发版脚本发现旧充值表会停止，避免创建空表后忽略旧数据。先备份数据库、停止服务和资金写入，再执行：

```bash
cd /www/wwwroot/game-platform/server
systemctl stop mgames.service
/www/server/php/84/bin/php webman mgs:recharge-schema --apply
/www/server/php/84/bin/php webman db:upgrade --dry-run
/www/server/php/84/bin/php webman db:upgrade
systemctl start mgames.service
```

转换保留原 ID、业务单号及旧流水引用，旧表改名为 `_legacy_...` 备份，不删除。新旧表同时有数据或旧请求号不符合 UUID 时停止转换，先人工核实；此时服务仍处于停止状态。

配置充值开关和收款地址后，服务启动自动扫描最新固化块；实时扫描健康即可接单，无需初始化或人工确认。重启缺口进入队列补扫，不阻断新充值；Redis 断点丢失时需按历史时间范围补扫，避免漏掉旧收款。

`php webman mgs:tron-scan` 查看状态，`--from=高度 --to=高度` 提交补扫。`MGS 充值报价`任务默认启用，每分钟刷新 TRX-USDT 行情；升级保留已有任务启停状态，旧环境需在后台启用该任务。`php webman mgs:recharge-price` 可立即刷新报价。USDT 和 TRX 共用收款地址，TRX 报价失效时仅暂停 TRX。所有命令使用线上 PHP 8.4 路径。

```bash
systemctl status mgames.service
systemctl restart mgames.service
systemctl reload mgames.service
systemctl stop mgames.service
journalctl -u mgames.service -f
```

Webman 由 systemd 统一管理，不要再手动启动守护进程：

```bash
/www/server/php/84/bin/php webman start -d
/www/server/php/84/bin/php webman restart -d
```

## 卸载服务

```bash
cd /www/wwwroot/game-platform
./deploy.sh uninstall
```

卸载命令会停止服务、取消开机启动、删除 `/etc/systemd/system/mgames.service` 并重新加载 systemd。项目代码、数据库和日志不会被删除。

## 更新 service 模板

已安装的 service 不会被发版脚本自动覆盖。确认模板变更后，可以先卸载再重新发布：

```bash
./deploy.sh uninstall
./deploy.sh
```
