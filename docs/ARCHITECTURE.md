# ARCHITECTURE — 整体架构

> 最后更新：2026-09-16
> 上级入口：[AI.md](../AI.md) · 模块明细：[MODULES.md](MODULES.md) · 进度：[PROGRESS.md](PROGRESS.md)

## 1. 架构总览

单进程 **Laravel 13 + Inertia v3 SSR SPA** 架构（无独立 API 层，无前后端分离部署）：

```
浏览器 (Vue 3 SPA, TypeScript strict)
   │  Inertia 协议（首次请求返回完整 HTML，后续请求返回 JSON page props）
   ▼
Laravel HTTP 内核 (bootstrap/app.php)
   ├─ Middleware(web): HandleAppearance → HandleInertiaRequests → AddLinkHeadersForPreloadedAssets
   ├─ 路由层 routes/{web,settings,console}.php
   │    ├─ Route::inertia() 直渲染（无控制器页面）
   │    └─ Controller (app/Http/Controllers/**)
   │          └─ FormRequest 验证 (app/Http/Requests/**)
   ├─ Fortify 1.39（接管全部认证路由，视图替换为 Inertia 页面）
   │    └─ Actions (app/Actions/Fortify/**)
   ├─ Eloquent (app/Models/**) → SQLite (database/database.sqlite)
   └─ laravel/ai SDK（已安装，待接入 —— 未来 AI 能力的统一入口，命名空间 Laravel\Ai\）
          ↕ (session/cache/queue → database driver，无 Redis 依赖)
```

## 2. 请求/数据流

### 2.1 页面请求（Inertia）

1. 首次 GET → `resources/views/app.blade.php`（Inertia 根模板，`HandleInertiaRequests::$rootView = 'app'`）+ Vite 资源 + preload 头。
2. 后续导航 → XHR 返回 JSON `{ component, props, url, version }`，Vue 端按页面名懒加载 `resources/js/pages/**` 组件渲染。
3. 布局由 `resources/js/app.ts` 的 `layout` 回调按页面名**前缀**决定（见 §4.2）。
4. 共享 props（每次请求都带）：`name`、`auth.user`、`sidebarOpen`（`HandleInertiaRequests::share()`）。

### 2.2 表单提交（非 API 模式）

1. 前端用 Wayfinder 生成的函数（`@/actions/`、`@/routes/`）发起 Inertia 表单请求。
2. 后端 Controller + FormRequest 验证；失败自动返回 `errors` prop（前端 `AlertError.vue` / `InputError.vue` 展示）。
3. 成功 → `RedirectResponse`（`back()` / `to_route()`）+ 可选 `Inertia::flash('toast', ...)`。
4. 前端 `lib/flashToast.ts` 监听 Inertia `flash` 事件 → vue-sonner toast 弹窗。

### 2.3 认证流（Fortify）

- Fortify 服务端接管 `/login`、`/register`、`/forgot-password`、`/reset-password/{token}`、`/verify-email` 等全部认证端点。
- `app/Providers/FortifyServiceProvider.php` 用 `Fortify::loginView()` 等钩子把视图替换为 Inertia 渲染（`auth/Login` 等），并注册 `CreateNewUser` / `ResetUserPassword` action 与登录限流。
- 2FA（TOTP）与 Passkeys（WebAuthn）已启用：`User` 模型 use `TwoFactorAuthenticatable` + `PasskeyAuthenticatable`；challenge 页 `auth/TwoFactorChallenge.vue`；`settings/security` 页管理。
- `.well-known/passkey-endpoints` 由 `routes/settings.php` 提供（passkey 自动填充发现）。

## 3. 后端分层与职责

| 层       | 位置                      | 职责                                                                                | 约束                                                |
| -------- | ------------------------- | ----------------------------------------------------------------------------------- | --------------------------------------------------- |
| 路由     | `routes/*.php`            | 只做注册与分组（`auth`/`verified`/`throttle`/`RequirePassword` 中间件）             | 简单静态页用 `Route::inertia()`，有数据才建控制器   |
| 控制器   | `app/Http/Controllers/**` | 编排：取数据、渲染/跳转                                                             | 薄控制器；按模块分子目录（`Settings/`）             |
| 验证     | `app/Http/Requests/**`    | FormRequest 类，与控制器同目录结构                                                  | 验证规则集中在 FormRequest，不散落控制器            |
| 动作     | `app/Actions/**`          | Fortify 等包的自定义行为注入点                                                      | 目前仅 `Fortify/CreateNewUser`、`ResetUserPassword` |
| 模型     | `app/Models/**`           | Eloquent；Laravel 13 新式 `#[Fillable]`/`#[Hidden]` 属性                            | 目前仅 `User`                                       |
| 中间件   | `app/Http/Middleware/**`  | `HandleAppearance`（主题 cookie→View share）、`HandleInertiaRequests`（共享 props） | 在 `bootstrap/app.php` 注册                         |
| Provider | `app/Providers/**`        | `AppServiceProvider`（全局默认值）、`FortifyServiceProvider`                        | 见 §5 设计决策                                      |

## 4. 前端架构

### 4.1 入口与装配

- `resources/js/app.ts`：`createInertiaApp` + 标题模板（`${title} - ${appName}`）+ 进度条（#4B5563）+ 主题初始化 + flash toast 初始化。
- 路径别名 `@/* → resources/js/*`（`tsconfig.json`）；TS `strict: true`。

### 4.2 布局系统（关键约定）

`app.ts` 中按页面名匹配（**顺序敏感**）：

| 页面名                 | 布局                                                  |
| ---------------------- | ----------------------------------------------------- |
| `Welcome`              | 无布局                                                |
| `auth/*`               | `AuthLayout`（内部再分 Card/Simple/Split 三种子布局） |
| `settings/*`           | `[AppLayout, SettingsLayout]` 嵌套                    |
| 其他（`Dashboard` 等） | `AppLayout`（Header/Sidebar 双形态，`AppShell` 组织） |

**新增页面不需要传 layout prop**；页面名前缀即布局选择器。

### 4.3 类型化路由（Wayfinder）

- Vite 插件 `wayfinder({ formVariants: true })` 在 dev/build 时扫描路由 → 生成 `resources/js/actions/**`（控制器函数）与 `resources/js/routes/**`（命名路由函数）。
- 前端**禁止手写 URL 字符串**，一律 `import { xxx } from '@/actions/...'` 或 `'@/routes/...'`。

### 4.4 UI 体系

- shadcn-vue（`components.json`：style `new-york-v4`、baseColor `neutral`、icons `lucide`）→ 基础组件在 `resources/js/components/ui/**`（生成物，勿手改）。
- 业务组件在 `resources/js/components/*.vue`（AppShell、AppSidebar、NavUser、ManageTwoFactor、Passkey* 等）。
- 样式：Tailwind 4（CSS-first，`resources/css/app.css` 为入口，无 tailwind.config.js）；`cn()` 工具在 `lib/utils.ts`（clsx + tailwind-merge）。

### 4.5 状态管理

- **无 Pinia/Vuex**。状态策略：服务端为唯一数据源（Inertia props）+ 少量 composables（`useAppearance`、`useInitials`、`useCurrentUrl`、`useTwoFactorAuth`）封装本地 UI 状态。新增全局状态优先走共享 props 或 composable，避免引入状态库。

## 5. 核心设计决策（已定型，勿轻易推翻）

1. **Inertia 单体而非前后分离 API**：认证态、CSRF、会话都走同一域；`bootstrap/app.php` 中仅 `api/*` 或 `expectsJson()` 才返回 JSON 异常。
2. **SQLite 为默认数据库**，session/cache/queue 全部 database driver——零外部服务依赖，本地/测试开箱即用；切换其他 DB 只需改 `.env`。
3. **Fortify（无 Breeze 脚手架）+ Inertia 视图替换**：认证逻辑在包内，视图自主可控。
4. **全局守卫集中在 `AppServiceProvider::configureDefaults()`**：`CarbonImmutable`、生产禁destructive命令、生产强密码默认。
5. **主题（明/暗/system）双通道**：cookie（`appearance`，不加密）+ `HandleAppearance` 服务端共享 + `useAppearance` 前端初始化，避免 FOUC。
6. **生成代码与手写代码严格分离**：Wayfinder 三目录 + shadcn ui 目录被 lint/fmt/watch 全部忽略。
7. **laravel/ai 作为未来 AI 能力唯一 SDK**（未接入）：规划中的 AI 功能应经 `Laravel\Ai\` 门面/Agent 模式实现，不再引入第三方 AI 包。

## 6. 依赖关系图（应用层）

```
routes/web.php ──┬─> (无控制器页面)
routes/settings.php ─┬─> Settings\ProfileController ─> Requests\Settings\* ─> User
                     └─> Settings\SecurityController ─┘        │
Fortify 路由 ──> FortifyServiceProvider ─> Actions\Fortify\* ──┘
app.ts ─> layouts/** ─> components/** ─> components/ui/** (生成)
        ─> pages/**（由路由/Inertia 页面名驱动）
(待开发) app/Ai/** ─> laravel/ai (Laravel\Ai\)
```

无事件/队列/任务/邮件自定义代码；队列默认 database driver 但目前无 Job。

## 7. 测试架构

- Pest 5，`tests/Pest.php` 将 `TestCase + RefreshDatabase` 绑定到 `Feature/` 全部测试。
- `phpunit.xml`：sqlite `:memory:`、array cache/session/mail、sync queue（测试零外部依赖）。
- 现有覆盖：Auth 7 个、Settings 2 个、Dashboard 1 个、冒烟 2 个（见 [PROGRESS.md](PROGRESS.md)）。
