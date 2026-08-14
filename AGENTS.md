# AI 代码开发规范

本文件适用于整个仓库。开发前先阅读相邻模块，优先复用现有写法；官方参考：[SaiAdmin 6.x 文档](https://saithink.top/documents/v6/)。

## 1. 核心边界

- `server/`：Webman 2.x + SaiAdmin 6.x，PHP 8.1+。
- `saiadmin-artd/`：Vue 3 + TypeScript + Element Plus + Tailwind CSS + Art Design Pro。
- SaiAdmin 是持续升级的产品依赖。`server/plugin/**` 和前端内置产品模块默认只读，业务开发不得修改。
- **所有自有后端业务必须写在 `server/app/`，所有自有 Model 必须写在 `server/app/model/`。**
- **所有自有前端业务必须写在主应用目录，禁止写入 `saiadmin-artd/src/views/plugin/`。**
- 可以引用 SaiAdmin 的基类、组件和 Hook，但不得复制后改写产品源码。确需改产品代码时，必须由任务明确授权并说明升级影响。
- 不执行 `sai:plugin`、`sai:upgrade`、`sai:orm`，除非用户明确要求；这些命令会创建或覆盖插件代码。

## 2. 业务目录

后端按业务模块分组，模型统一放在 `app/model`：

```text
server/app/
├── controller/<业务>/XxxController.php
├── logic/<业务>/XxxLogic.php
├── validate/<业务>/XxxValidate.php
├── model/Xxx.php
└── service/<业务>/...              # 仅跨 Logic 复用时使用
```

- 路由统一写在 `server/config/route.php`，中间件写在 `server/config/middleware.php`。
- 前端页面放 `saiadmin-artd/src/views/<业务>/<功能>/`，API 放 `saiadmin-artd/src/api/<业务>/<功能>.ts`。
- 页面私有组件放功能目录的 `modules/`；真正跨模块复用的业务组件才放 `src/components/business/`。
- 新业务目录不得占用 `system`、`safeguard`、`tool`、`plugin` 等产品内置名称。

## 3. 修改原则

- 只改任务需要的文件，保留工作区已有改动；不要顺手重构、升级依赖或修改锁文件。
- 先查找同类功能并保持命名、响应结构、组件用法和代码风格一致。
- 不提交 `.env`、密钥、Token、账号、生产地址或运行时文件。
- 优先通过新增业务文件和封装适配完成需求，不侵入产品核心。

## 4. 后端规范

采用 `Controller -> Logic -> Model -> DB` 分层，`Validate` 负责输入校验。

- Controller：只取参、调用验证场景、调用 Logic、返回 `success()` / `fail()`；不要堆业务逻辑。
- Logic：处理业务规则、权限范围、事务和数据组装；复杂写操作使用 `BaseLogic::transaction()`。
- Model：只放表映射、关联、类型转换、查询作用域和 `searchXxxAttr` 搜索器。
- Validate：继承 `BaseValidate`，为 `save`、`update` 等操作定义明确场景；前端校验不能代替后端校验。
- 管理端 Controller 继承 `BaseController`；只有明确公开且无需登录的接口才继承 `OpenController`。
- Controller 构造时先设置 `$this->logic`、`$this->validate`，再调用 `parent::__construct()`，确保管理员上下文能传入 Logic。
- `OpenController` 不会自动跳过登录中间件；免登录方法还必须显式加入控制器的 `$noNeedLogin`。
- 标准 CRUD 使用 `fastRoute()`，HTTP 方法保持：列表/详情 `GET`、新增 `POST`、更新 `PUT`、删除 `DELETE`。
- 主应用路由没有插件路径前缀；在 `server/config/route.php` 中显式分组，前端 URL 必须与其完全一致。
- 管理接口使用 `#[Permission('名称', '权限码')]`。自有权限码统一为 `app:业务:功能:动作`，并与菜单配置、前端 `v-permission` 完全一致。
- 主应用必须自行接入 SaiAdmin 的 `CheckLogin`、`CheckAuth`、`SystemLog` 中间件，并在 `server/config/exception.php` 使用兼容 SaiAdmin 响应结构的异常处理器；不能假设插件配置会作用于 `server/app`。
- 公开接口必须显式划分路由和认证边界，不能为方便而关闭整个主应用的权限校验。

## 5. ORM 与数据库

**当前项目统一使用 Eloquent ORM。** 新代码必须继承：

- `plugin\saiadmin\basic\eloquent\BaseModel`
- `plugin\saiadmin\basic\eloquent\BaseLogic`

ThinkORM 依赖仍可能存在，但不要在新业务中混用，也不要自行执行 `php webman sai:orm`。

- Model 命名空间统一为 `app\model`；禁止把自有 Model 放入任何 `plugin` 目录。
- Model 继承 SaiAdmin 的 Eloquent `BaseModel`，不要继承脚手架示例中的 `support\Model`。
- 使用 `protected $primaryKey` 和 `protected $table`，不要使用 ThinkORM 的 `$pk`、`field()` 等写法。
- 基类默认使用 `create_time`、`update_time`、`delete_time`，启用软删除，并自动维护存在的 `created_by`、`updated_by`。
- 自定义 `casts()` 时使用 `array_merge(parent::casts(), [...])`，不要丢失基类转换。
- 基类允许批量赋值；写入数据库前必须显式筛选允许字段，禁止把未经处理的 `$request->post()` 直接持久化。
- 查询条件放入 `searchXxxAttr($query, $value)`；关联查询使用 Eloquent 关联并按需预加载，避免 N+1。
- 表结构变更使用独立 SQL 或迁移文件；数据修复与结构变更分开，禁止在请求代码中自动改表。
- 未经明确授权，不执行删表、清库、批量删除等不可逆操作。

## 6. 前端规范

- 使用 Vue 3 `<script setup lang="ts">`，遵循现有 ESLint、Prettier 和 Stylelint 配置。
- `src/components/sai`、`src/hooks/core`、`src/router/core` 及内置页面只复用不修改；需要扩展时在业务目录封装一层。
- API 统一通过 `@/utils/http`，常规方法命名为 `list/read/save/update/delete`，请求方法必须与后端路由一致。
- 列表页优先使用 `useTable`；新增、编辑、删除状态优先使用 `useSaiAdmin`。
- 常规功能按 `index.vue + modules/table-search.vue + modules/edit-dialog.vue` 拆分，避免单文件过大。
- 字典、开关、图片、文件、富文本、导入导出等优先复用 `src/components/sai` 中的组件。
- 操作按钮使用 `v-permission`；它只控制展示，真正的权限必须由后端校验。
- 表单定义 TypeScript 类型和 Element Plus 校验规则；弹窗打开时重置初始值，成功后关闭并刷新列表。
- 列表返回使用 `Api.Common.ApiPage`，树形/普通数据使用匹配的 `Api.Common.ApiData` 类型；不要随意使用 `any` 扩散未知结构。
- 后台菜单的组件路径直接对应 `src/views`，例如 `/order/refund` 对应 `src/views/order/refund/index.vue`。
- 页面需兼顾窄屏，不写只在固定桌面宽度下成立的布局。

## 7. 验证与交付

- PHP 文件至少执行 `php -l <改动文件>`；路由、配置或常驻进程代码变化后重启或 reload Webman。
- 前端改动在 `saiadmin-artd/` 下执行 `pnpm lint`；功能完成后执行 `pnpm build`。
- 联调 CRUD 时核对 URL、HTTP 方法、请求字段、分页结构、错误响应和权限码。
- 提交前执行 `git diff --check`，并确认没有无关文件、调试输出、敏感信息或意外生成物。
- 若受环境限制未能执行某项验证，交付时明确说明，不能默认宣称通过。
