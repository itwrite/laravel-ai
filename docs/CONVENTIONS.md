# CONVENTIONS — 开发约定

> 最后更新：2026-09-16
> 上级入口：[AI.md](../AI.md)
> 本文件浓缩自现有代码的实际风格；与 `AGENTS.md`（Boost 指南）互补，冲突时以本文件+现有代码为准。

## 1. PHP / Laravel 约定

### 1.1 风格（Pint preset: laravel，改后必须跑 `vendor/bin/pint --dirty --format agent`）

- 控制结构一律花括号（含单行体）。
- 显式参数类型 + 返回类型：`public function edit(Request $request): Response`。
- PHP 8 构造器属性提升；不留空的零参 `__construct()`。
- PHPDoc 块优先于行内注释；仅极复杂逻辑才写行内注释。
- 私有配置方法模式：见 `FortifyServiceProvider`（`configureActions/configureViews/configureRateLimiting` 拆分 `boot()`）。

### 1.2 Model（Laravel 13 新式，参照 `app/Models/User.php`）

```php
#[Fillable(['name', 'email', 'password'])]           // 替代 $fillable 数组属性
#[Hidden(['password', 'two_factor_secret', ...])]    // 替代 $hidden
class User extends Authenticatable
{
    protected function casts(): array { ... }        // casts 是方法（Laravel 11+）
}
```

- 模型必须配套 Factory（`php artisan make:model --help` 查看选项），测试一律用 Factory。
- 关系用 Laravel 13 属性写法时保持与 `User::passkeys()` 一致的常规方法式亦可。

### 1.3 控制器 / 验证 / 路由

- 控制器按模块分子目录（`Controllers/Settings/`），FormRequest 镜像同结构（`Requests/Settings/`）。
- 验证规则放 FormRequest 或 `app/Concerns/*ValidationRules.php` trait（密码/资料规则已有现成 trait，先复用）。
- 简单无数据页面直接 `Route::inertia('uri', 'PageName')->name('x.y')`，不建空控制器。
- 路由命名：`模块.动作`（如 `profile.update`、`user-password.update`、`security.edit`）。
- 敏感操作路由按需加中间件：`auth` / `verified` / `RequirePassword` / `throttle:6,1`。
- PHPDoc 数组形状：`@return array<string, mixed>`。

### 1.4 错误处理与用户反馈（统一模式）

- 表单验证失败：FormRequest 自动回传 errors，前端无需处理。
- 成功提示：**不要**用 session flash + Blade；统一 `Inertia::flash('toast', ['type' => 'success', 'message' => __('...')])`（前端 vue-sonner 自动弹）。
- 消息文案用 `__()` 包裹（本地化预留）。type 取值：`success` / `error`（见 `types/ui.ts` 的 `FlashToast`）。
- 服务端日志：默认 stack/single，未自定义 channel。

## 2. Vue / TypeScript 约定

### 2.1 文件与命名

- 页面：`pages/<模块>/PascalCase.vue`，**文件名即 Inertia 组件名**（`settings/Profile`）。
- 业务组件：`components/PascalCase.vue`；基础件：`components/ui/<kebab-case>/`（shadcn 生成，勿手改）。
- composables：`useXxx.ts`；纯函数工具：`lib/`；类型：`types/` 并经 `index.ts` re-export。
- Vue SFC 单根元素（硬性要求）。

### 2.2 布局与导航

- **不给页面手动挂 layout**——由 `app.ts` 按页面名前缀映射；新模块若需专属布局，改 `app.ts` 的 switch。
- 跳转/提交**禁止手写 URL 字符串**：用 Wayfinder 产物 `@/actions/...`（控制器）或 `@/routes/...`（命名路由），支持 `formVariants`。

### 2.3 TS 风格（`vue-tsc --noEmit` + `vp check` typeAware+denyWarnings）

- `strict: true`；导出加类型；props 用类型化 `defineProps<...>()`。
- 格式化（vite-plus fmt）：printWidth 80、tabWidth 4、单引号、分号；Tailwind class 自动排序（clsx/cn/cva）。
- 需要 UI 状态时优先 composable；**禁止引入 Pinia 等状态库**（当前无、也未经批准引入）。
- 样式只用 Tailwind 4 工具类 + `cn()`（`lib/utils.ts`）；主题色走 CSS 变量（`resources/css/app.css`）。

## 3. 测试约定（Pest 5）

- 创建：`php artisan make:test --pest XxxTest`（Feature 默认；`--unit` 才进 Unit）。命名不带目录前缀。
- Feature 自动获得 `TestCase + RefreshDatabase`（`tests/Pest.php`）。
- 造数据只用 `UserFactory`（先查有没有可用的 state）。
- 断言模式参照现有测试：`actingAs($user)->get(...)` / `assertRedirect` / `assertDatabaseHas` / `assertAuthenticated`。
- 运行最窄范围：`php artisan test --compact <file>`；改一次跑一次；**不删测试**。

## 4. 数据库 / 迁移

- 默认 SQLite；写迁移须兼容 sqlite（测试环境 `:memory:`）。
- 迁移命名：`YYYY_MM_DD_HHMMSS_动作_表名.php`（artisan 生成）。
- session/cache/queue 走 database driver——新增缓存/队列使用零配置即可用。

## 5. Git / 流程

- 提交信息无既定规范（仅一次 commit），保持简洁英文祈使句即可。
- `.env` 不入库；`.env.example` 改动需与配置同步。

## 6. 禁止事项（红线）

1. ❌ 手动编辑生成目录：`resources/js/{actions,routes,wayfinder}/**`、`components/ui/**`、`bootstrap/ssr/**`、`public/build/**`。
2. ❌ 未经批准新增 composer/npm 依赖、新建顶层目录、删除测试文件。
3. ❌ 引入第三方 AI SDK 替代 `laravel/ai`（项目已定此为唯一 AI 通道）。
4. ❌ 全量扫描 `vendor/`、`node_modules/`、生成目录（token 浪费）。
5. ❌ 在控制器写验证规则堆/在模型写业务逻辑堆（分层见 [ARCHITECTURE.md](ARCHITECTURE.md) §3）。
6. ❌ 前端手写 URL、绕过 Wayfinder。
7. ❌ 自建文档文件（除非用户明确要求）。
8. ❌ 手动编辑 `AGENTS.md` 中 `<laravel-boost-guidelines>` 标记**内**的内容（`composer update` 时 `boost:update` 会整体重写该区域）；自定义信息一律写在标记外（文件顶部导航头区域）。
