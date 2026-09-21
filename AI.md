# AI.md — 项目记忆主入口

> 最后更新：2026-09-16
> 本文件是 AI 会话的第一入口。读完本文件即可获得开发所需 80% 的上下文，按需再深入下方文档。

## 📖 请先阅读以下文档（按需加载，不要一次性全读）

| 文档                                         | 用途                                                       | 什么时候读                     |
| -------------------------------------------- | ---------------------------------------------------------- | ------------------------------ |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | 整体架构、分层、数据流、设计决策                           | 修改架构 / 新增模块前          |
| [docs/CODEMAP.md](docs/CODEMAP.md)           | 目录结构 + 每个目录的职责                                  | 定位文件时                     |
| [docs/PROGRESS.md](docs/PROGRESS.md)         | 已完成功能 / 进行中 / 技术债                               | 开始任何任务前（了解现状）     |
| [docs/CONVENTIONS.md](docs/CONVENTIONS.md)   | 命名、代码风格、错误处理约定                               | 写任何代码前                   |
| [docs/MODULES.md](docs/MODULES.md)           | 按功能模块拆分的入口文件与关键类                           | 修改某个具体模块前             |
| [AGENTS.md](AGENTS.md)                       | Laravel Boost 官方指南（含 Inertia v3 / Pest / Pint 规则） | 与 Laravel 生态 API 相关的修改 |

## 🎯 项目一句话概述

基于 **Laravel Vue Starter Kit** 的全栈应用，目标是围绕 **Laravel AI SDK（`laravel/ai`）** 构建 AI 功能。
当前状态：**脚手架初始阶段**——git 仅 1 次提交（`first commit`），工作区干净，Starter Kit 自带功能（认证/设置）完整可用，**AI 业务功能尚未开始开发**（`laravel/ai` 已安装但 app/ 内无任何调用，无 `config/ai.php`，`.env` 无 AI 供应商密钥）。

## 🧱 技术栈（已验证版本）

| 层       | 技术                                                    | 版本                                      |
| -------- | ------------------------------------------------------- | ----------------------------------------- |
| 后端框架 | Laravel                                                 | 13.32.0（PHP ^8.3，本机 8.5）             |
| AI SDK   | laravel/ai                                              | 0.11.2（**已安装、未使用**）              |
| 认证     | laravel/fortify                                         | 1.39.0（登录/注册/2FA/Passkeys/邮箱验证） |
| 前端桥接 | inertiajs/inertia-laravel + @inertiajs/vue3             | 3.3.4 / 3.x                               |
| 前端框架 | Vue + TypeScript(strict)                                | 3.5.x                                     |
| 样式     | Tailwind CSS + shadcn-vue(new-york-v4, reka-ui, lucide) | 4.1.x                                     |
| 路由类型 | laravel/wayfinder + @laravel/vite-plugin-wayfinder      | 0.1.21                                    |
| 构建     | vite-plus（命令为 `vp`）/ Vite 8                        | 0.3.0                                     |
| 数据库   | SQLite（session/cache/queue 均为 database driver）      | —                                         |
| 测试     | Pest                                                    | 5.2.0                                     |
| 静态分析 | Larastan（phpstan level 7）                             | 3.12.1                                    |
| 格式化   | Pint（laravel preset）/ vite-plus fmt                   | 1.32.1                                    |
| AI 辅助  | Laravel Boost（MCP，见 `.ai/mcp/mcp.json`）             | 2.9.0                                     |

其他：`laravel/chisel`（脚手架清理工具，0.1.1）、`laravel/pao`（测试输出 AI 优化）、`laravel/sail`（Docker，未启用）。

## ⚡ 常用命令

```bash
# 开发（同时启动 server + queue + vite）
composer run dev

# 测试（最窄范围优先； Pest + RefreshDatabase，sqlite :memory:）
php artisan test --compact tests/Feature/Settings/ProfileUpdateTest.php
vendor/bin/pest --filter=testName

# 完整检查链（lint check → phpstan → tests）
composer run test          # 本地验证用
composer run ci:check      # CI 全量（npm check + types + test）

# PHP 格式化（改了任何 PHP 文件后必须执行）
vendor/bin/pint --dirty --format agent

# 前端类型检查 / lint / 格式化
npm run types:check        # vue-tsc --noEmit
npm run check              # vite-plus lint（typeAware, denyWarnings）
npm run check:fix
npm run build              # vite-plus build

# 路由查看
php artisan route:list --except-vendor
```

## 🗺️ 关键事实速查（AI 必读，避免重复探索）

1. **页面布局映射**在 `resources/js/app.ts`：`Welcome`→无布局；`auth/*`→`AuthLayout`；`settings/*`→`[AppLayout, SettingsLayout]`；其余→`AppLayout`。新增页面无需手动指定布局。
2. **Inertia 共享 props** 在 `app/Http/Middleware/HandleInertiaRequests.php`：`name`、`auth.user`、`sidebarOpen`。
3. **后端 → 前端 toast**：后端用 `Inertia::flash('toast', ['type' => 'success', 'message' => ...])`，前端 `resources/js/lib/flashToast.ts` 自动监听并调 vue-sonner。
4. **Laravel 13 新式 Model 写法**（见 `app/Models/User.php`）：用 PHP 属性 `#[Fillable([...])]`、`#[Hidden([...])]` 而非数组属性；`casts()` 为方法。
5. **路由文件**仅 3 个：`routes/web.php`（home/dashboard）、`routes/settings.php`（profile/security/password/appearance + `.well-known/passkey-endpoints`）、`routes/console.php`。
6. **Fortify 视图替换**：`app/Providers/FortifyServiceProvider.php` 把所有 Fortify 认证页渲染为 Inertia 页面（`auth/*`）。
7. **生产环境守卫**在 `app/Providers/AppServiceProvider.php`：`Date::use(CarbonImmutable)`、生产环境 `DB::prohibitDestructiveCommands()` + 强密码默认规则（12 位混合）。
8. **不加密 Cookie**：`appearance`、`sidebar_state`（`bootstrap/app.php`）。
9. **外观主题（明/暗）**：后端 `HandleAppearance` 中间件读 cookie 共享给 Blade，前端 `composables/useAppearance.ts` 初始化。
10. **数据库默认 SQLite**：`database/database.sqlite`；测试用 `:memory:`（见 `phpunit.xml`）。

## 🚫 生成文件目录（绝对不要手动编辑）

- `resources/js/actions/**` — Wayfinder 生成的控制器 TS 函数
- `resources/js/routes/**` — Wayfinder 生成的命名路由 TS 函数
- `resources/js/wayfinder/**` — Wayfinder 运行时
- `resources/js/components/ui/**` — shadcn-vue 基础组件（vite lint 已忽略）
- `bootstrap/ssr/**`、`public/build/**`、`vendor/**`、`node_modules/**`

修改路由/控制器后由 `npm run dev` 或 `npm run build` 自动重新生成。

## 🤖 AI 开发约束（重要：节约 token）

1. **优先只读相关模块**：先查 [docs/CODEMAP.md](docs/CODEMAP.md) 定位，再读具体文件；**禁止全量扫描** `vendor/`、`node_modules/`、生成目录。
2. **修改前列出文件清单**：任何多文件改动，先向用户确认目标文件列表再动手。
3. **写代码前**：读 [docs/CONVENTIONS.md](docs/CONVENTIONS.md) + 目标目录的兄弟文件（保持风格一致）。
4. **Laravel 生态 API**：先读 `AGENTS.md` 对应章节或使用 Boost 的 `search-docs`；Inertia v3 与 v1/v2 有大量差异（如 `Inertia::optional()` 替代 `lazy()`）。
5. **Skills**：`.github/skills/` 下有 8 个领域技能文档（`ai-sdk-development`、`inertia-vue-development`、`fortify-development`、`testing-best-practices`、`laravel-best-practices`、`tailwindcss-development`、`wayfinder-development`、`infer-conventions`），进入对应领域工作时必须先读。
6. **AI SDK 开发**：使用 `laravel/ai` 前必读 `.github/skills/ai-sdk-development/SKILL.md`（命名空间 `Laravel\Ai\`，Agent 模式：实现 `Agent` 接口 + `Promptable` trait）。
7. **改完必须验证**：PHP 文件 → `vendor/bin/pint --dirty --format agent`；行为变更 → 写 Pest 测试并运行最窄范围。
8. **不新增依赖**、**不新建顶层目录**、**不删除测试**，除非用户明确批准。
9. 完成功能后**更新 [docs/PROGRESS.md](docs/PROGRESS.md)**（如新增模块，同步更新 [docs/MODULES.md](docs/MODULES.md)）。

## 📂 新增功能的推荐落点

| 需求       | 落点                                                                                          |
| ---------- | --------------------------------------------------------------------------------------------- |
| 新页面     | `resources/js/pages/<模块>/Xxx.vue` + 路由（`Route::inertia()` 或控制器 `Inertia::render()`） |
| 新控制器   | `app/Http/Controllers/<模块>/`，用 `php artisan make:controller`                              |
| 表单验证   | `app/Http/Requests/<模块>/`，参考 `Settings/ProfileUpdateRequest.php`                         |
| AI 功能    | 服务类放 `app/`（如 `app/Ai/`），调用 `Laravel\Ai\` SDK；先读 ai-sdk skill                    |
| 共享数据   | `HandleInertiaRequests::share()`                                                              |
| UI 组件    | `resources/js/components/`（业务）/ `components/ui/`（shadcn 生成，勿手改）                   |
| 可复用逻辑 | `resources/js/composables/useXxx.ts`                                                          |
| 类型       | `resources/js/types/`，经 `index.ts` re-export                                                |

## 🔗 文档间导航

- 本文件 → 全部 docs/*.md + AGENTS.md
- [ARCHITECTURE.md](docs/ARCHITECTURE.md) ⇄ [MODULES.md](docs/MODULES.md)（架构 ↔ 模块明细）
- [PROGRESS.md](docs/PROGRESS.md) → [MODULES.md](docs/MODULES.md)（进度引用模块状态）
- [CONVENTIONS.md](docs/CONVENTIONS.md) ← 所有写代码场景
