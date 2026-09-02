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

## 服务管理

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
