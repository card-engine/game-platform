# 数据库升级

数据库以当前代码为准，不依赖 SaiAdmin 安装流程：

- `schema.sql` 保存完整表结构，不包含业务数据、动态月份表和删除语句。
- `system.php` 保存菜单、权限、内置角色、系统配置定义、字典、初始账号和 MG/MGS 定时任务。
- `php webman db:upgrade --dry-run` 预览结构差异。
- `php webman db:upgrade` 同步结构和内置数据，可重复执行。

升级命令在当前业务库内创建 `__mg_schema_<进程号>_*` 临时对比表并在结束时清理，不创建临时数据库，也不要求应用账号拥有全局建库权限。

首次安装前必须在 `.env` 配置 `INITIAL_ADMIN_PASSWORD` 和 `INITIAL_GAME_ADMIN_PASSWORD`。已有账号的密码不会被升级覆盖。

表结构变更直接修改 `schema.sql`。升级只新增或修正基准中存在的表、字段和索引，不自动删除目标库的额外结构；确需删除时单独评审执行。`mg_bets_template`、`mg_bills_template`、`mgs_bets_template` 和 `mgs_bills_template` 的结构会同步到已有月份表。

菜单使用 `code`、按钮使用 `slug` 定位，禁止依赖自增 ID。内置角色权限以 `system.php` 为准精确同步；配置实际值和定时任务启停状态只在首次创建时写入，后续升级保留后台设置。

生产环境使用 `deploy/mgames.service` 托管 Webman。部署代码和依赖后执行数据库升级，再由 systemd 重启服务。
