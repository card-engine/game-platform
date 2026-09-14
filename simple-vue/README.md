# DuoZhiGame

DuoZhiGame 是基于 Vue 3 开发的响应式游戏大厅，兼容桌面端和移动端，支持 PWA 安装、游戏分类、品牌筛选、搜索、主题与语言切换、访问密码校验以及游戏内悬浮操作菜单。

## 技术栈

- Vue 3 + TypeScript
- Vite
- Element Plus
- Pinia
- Vue I18n
- TanStack Query
- Axios
- Vitest
- Vite PWA

## 环境要求

- Node.js 20.19 及以上版本，或 Node.js 22.12 及以上版本
- pnpm 10.28.0，或 npm 10 及以上版本

## 安装依赖

项目推荐使用 pnpm，同时兼容 npm。首次运行时任选一种方式：

```bash
# pnpm
pnpm install

# npm
npm install
```

在持续集成或部署环境中，建议根据锁文件安装依赖：

```bash
# pnpm
pnpm install --frozen-lockfile

# npm
npm ci
```

## 环境配置

创建本地环境文件：

```bash
cp .env.example .env.local
```

可配置项如下：

| 变量 | 说明 |
| --- | --- |
| `VITE_GAME_ACCESS_PASSWORD` | 用户点击游戏时需要输入的访问密码 |
| `VITE_GAME_ACCESS_TTL_DAYS` | 密码验证状态在当前浏览器中的有效天数 |

`.env.local` 仅用于本机，不应提交到 Git。需要新增公共配置项时，请同步更新 `.env.example`。

访问密码由前端完成校验，适用于演示或基础访问限制。构建后的前端代码无法安全保存秘密；正式业务如需严格鉴权，应由服务端验证用户身份和权限。

## 开发运行

启动开发服务器：

```bash
# pnpm
pnpm dev

# npm
npm run dev
```

默认从 `http://localhost:5173/` 开始选择可用端口；端口被占用时，以终端输出的地址为准。如需让同一局域网内的手机访问，可执行：

```bash
# pnpm
pnpm dev --host

# npm
npm run dev -- --host
```

开发服务器会按照 `vite.config.ts` 的配置转发 `/api` 和 `/operation` 请求。更换接口服务时，只需修改该文件中的开发代理目标。

## 测试与检查

运行自动化测试：

```bash
# pnpm
pnpm test

# npm
npm test
```

执行 TypeScript 类型检查：

```bash
# pnpm
pnpm exec vue-tsc --noEmit

# npm
npx vue-tsc --noEmit
```

## 生产构建

执行以下命令生成生产文件：

```bash
# pnpm
pnpm install --frozen-lockfile
pnpm build

# npm
npm ci
npm run build
```

构建命令会先执行 TypeScript 类型检查，再将生产文件输出到 `dist/`。`dist/` 是可重新生成的构建产物，不提交到 Git。

在部署前可以本地预览构建结果：

```bash
# pnpm
pnpm preview

# npm
npm run preview
```

默认从 `http://localhost:4173/` 开始选择可用端口；端口被占用时，以终端输出的地址为准。该命令仅用于检查构建结果，不应作为生产服务器使用。

## PWA 安装

生产构建会生成 `manifest.webmanifest`、`sw.js` 和 Workbox 运行文件。页面右下角提供安装按钮：

- iOS Safari 或 Chrome 点击后显示“添加到主屏幕”教程
- Android、桌面 Chrome 等支持原生安装提示的浏览器会直接打开安装窗口
- 应用已经以独立模式运行时，安装按钮自动隐藏

除 `localhost` 外，PWA 必须部署在 HTTPS 环境。浏览器还会根据安装状态、访问频率及自身策略决定是否提供原生安装提示。

Service Worker 会预缓存页面构建资源，并缓存已成功加载的图片；接口数据和游戏页面仍需要网络连接，不属于完整离线游戏。

## 部署说明

将 `dist/` 目录中的全部文件部署到 Nginx、Apache、对象存储静态站点或其他静态托管服务即可。当前构建按站点根目录生成资源路径，建议部署在独立域名或域名根路径下。

每次部署必须先在最新源码上重新构建，并同步删除服务器中已经失效的哈希文件。以 rsync 为例：

```bash
git pull --ff-only
pnpm install --frozen-lockfile
pnpm build
rsync -av --delete dist/ /path/to/site/
```

不要复用旧的 `dist/`，也不要只上传部分文件；`index.html`、`sw.js`、JavaScript 和 CSS 必须来自同一次构建。

生产环境还需要在同一域名下将以下路径转发到实际接口服务：

- `/api`
- `/operation`

前端始终请求上述相对路径，因此无需在浏览器端配置接口域名，也可以避免跨域问题。转发规则及接口服务地址应由实际部署环境提供，不要写入前端源码。

部署服务器不要缓存入口页面和 PWA 更新文件，带哈希的构建资源则可以长期缓存。Nginx 可以增加以下规则，并放在 SPA 的 `location /` 之前：

```nginx
location = / {
    try_files /index.html =404;
    add_header Cache-Control "no-cache, no-store, must-revalidate";
}

location ~ ^/(index\.html|sw\.js|registerSW\.js|manifest\.webmanifest)$ {
    try_files $uri =404;
    add_header Cache-Control "no-cache, no-store, must-revalidate";
}

location ^~ /assets/ {
    try_files $uri =404;
    add_header Cache-Control "public, max-age=31536000, immutable";
}
```

如果前面还有 CDN，应让 `/`、`index.html`、`sw.js`、`registerSW.js` 和 `manifest.webmanifest` 绕过 CDN 缓存。

部署完成后必须核对线上引用的构建哈希与本地 `dist/` 一致：

```bash
grep -oE 'assets/index-[^" ]+\.(js|css)' dist/index.html
curl -fsSL https://your-domain.example/ | grep -oE 'assets/index-[^" ]+\.(js|css)'
```

两条命令的 JavaScript 和 CSS 文件名必须分别一致，否则本次部署未生效，不应继续用浏览器缓存作为判断依据。

部署后至少检查以下功能：

- 首页数据、图片和余额正常加载
- 分类、品牌、搜索及自动加载正常工作
- 点击游戏后能完成密码校验并进入游戏
- 刷新页面后语言、主题、筛选项和列表位置能够恢复
- PWA 安装按钮、iOS 安装教程及应用图标正常
- `manifest.webmanifest` 和 `sw.js` 能够正常访问
- 桌面端与移动端布局正常

## 目录说明

```text
public/          保持固定路径的静态资源
src/api/         接口请求
src/assets/      由 Vite 打包的图片资源
src/components/  页面组件
src/stores/      全局状态
src/styles/      全局样式
src/views/       页面视图
dist/            生产构建产物
```

业务源码、`public/`、`pnpm-lock.yaml`、`package-lock.json` 和 `.env.example` 需要纳入版本管理；依赖目录、本地配置、构建产物、日志及中间文件由 `.gitignore` 排除。

项目同时维护 pnpm 和 npm 锁文件。修改依赖后，需要执行以下命令并一并提交两个锁文件：

```bash
pnpm install
npm install --package-lock-only
```
