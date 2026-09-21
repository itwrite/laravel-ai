# CODEMAP — 目录结构与职责地图

> 最后更新：2026-09-16
> 上级入口：[AI.md](../AI.md) · 架构说明：[ARCHITECTURE.md](ARCHITECTURE.md)
> ⚠️ 标注 [生成] 的目录为工具产物，**禁止手动编辑**。

## 根目录

```
laravel-ai/
├── AI.md                     # AI 记忆主入口（先读这个）
├── CLAUDE.md                 # Claude Code 入口（@AI.md 引用）
├── AGENTS.md                 # Laravel Boost 官方指南（Inertia v3/Pest/Pint 等规则）
├── .cursor/rules/project.mdc # Cursor 规则（精简版，指向 AI.md/docs）
├── .ai/mcp/mcp.json          # Boost MCP 配置（php artisan boost:mcp）
├── docs/                     # 本套项目记忆文档
├── composer.json / package.json  # 依赖与脚本（scripts 见 AI.md 命令节）
├── vite.config.ts            # vite-plus 配置（lint/fmt 规则、wayfinder 插件、字体 bunny）
├── tsconfig.json             # TS strict；别名 @/* → resources/js/*
├── phpstan.neon              # Larastan level 7（paths: app/ bootstrap/app.php config/ database/ routes/）
├── pint.json                 # preset: laravel
├── phpunit.xml               # 测试环境（sqlite :memory: 等）
├── components.json           # shadcn-vue 配置（new-york-v4 / neutral / lucide）
└── .env(.example)            # 默认 sqlite；无 AI 供应商密钥
```

## app/（后端应用，共 17 个文件）

```
app/
├── Actions/Fortify/
│   ├── CreateNewUser.php        # 注册新用户（Fortify 注入）
│   └── ResetUserPassword.php    # 密码重置（Fortify 注入）
├── Concerns/
│   ├── PasswordValidationRules.php  # 共享密码验证规则 trait
│   └── ProfileValidationRules.php   # 共享资料验证规则 trait
├── Http/
│   ├── Controllers/
│   │   ├── Controller.php              # 基类（空）
│   │   └── Settings/
│   │       ├── ProfileController.php   # edit/update/destroy（profile.edit|update|destroy）
│   │       └── SecurityController.php  # edit（2FA+Passkeys 数据）/ update（改密码）
│   ├── Middleware/
│   │   ├── HandleAppearance.php        # 主题 cookie → View::share('appearance')
│   │   └── HandleInertiaRequests.php   # Inertia 根模板 + 共享 props（name/auth.user/sidebarOpen）
│   └── Requests/Settings/
│       ├── PasswordUpdateRequest.php
│       ├── ProfileDeleteRequest.php
│       ├── ProfileUpdateRequest.php
│       └── TwoFactorAuthenticationRequest.php  # 2FA 会话状态校验
├── Models/
│   └── User.php                # 唯一模型；#[Fillable]/#[Hidden]；2FA+Passkey traits
└── Providers/
    ├── AppServiceProvider.php  # 全局默认（CarbonImmutable/生产守卫/密码默认）
    └── FortifyServiceProvider.php # Fortify 动作+视图(→Inertia)+限流
```

## routes/

| 文件           | 内容                                                                                                                            |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `web.php`      | `/`（Welcome）、`/dashboard`（auth+verified）、引入 settings.php                                                                |
| `settings.php` | `/settings` 重定向、profile 三条路由、security 两条、password 一条（throttle:6,1）、appearance、`.well-known/passkey-endpoints` |
| `console.php`  | 仅 `inspire` 示例命令                                                                                                           |

## database/

```
database/
├── migrations/
│   ├── 0001_01_01_000000_create_users_table.php
│   ├── 0001_01_01_000001_create_cache_table.php
│   ├── 0001_01_01_000002_create_jobs_table.php
│   ├── 2024_01_01_000000_create_passkeys_table.php
│   └── 2025_08_14_170933_add_two_factor_columns_to_users_table.php
├── factories/UserFactory.php     # 测试造数据唯一入口
├── seeders/DatabaseSeeder.php    # 默认造 1 个 test@example.com 用户
└── database.sqlite               # 默认库文件
```

## resources/js/（前端，@/ 别名根）

```
resources/js/
├── app.ts                  # Inertia 装配：标题/布局映射/进度条/主题/flash toast
├── pages/                  # 路由页面（页面名=Inertia component 名）
│   ├── Welcome.vue             # 首页（无布局）
│   ├── Dashboard.vue           # 仪表盘（AppLayout，auth+verified）
│   ├── auth/                   # Fortify 页面（AuthLayout）
│   │   ├── Login.vue / Register.vue
│   │   ├── ForgotPassword.vue / ResetPassword.vue
│   │   ├── ConfirmPassword.vue / VerifyEmail.vue
│   │   └── TwoFactorChallenge.vue
│   └── settings/               # 设置页（[AppLayout, SettingsLayout]）
│       ├── Profile.vue / Security.vue / Appearance.vue
├── layouts/
│   ├── AppLayout.vue / AuthLayout.vue        # 布局路由器
│   ├── app/AppHeaderLayout.vue / AppSidebarLayout.vue
│   ├── auth/AuthCardLayout.vue / AuthSimpleLayout.vue / AuthSplitLayout.vue
│   └── settings/Layout.vue                   # 设置页侧栏容器
├── components/             # 业务组件（手写）
│   ├── App 壳：AppShell / AppHeader / AppSidebar / AppSidebarHeader / AppContent
│   │          NavMain / NavFooter / NavUser / UserMenuContent / Breadcrumbs
│   │          AppLogo / AppLogoIcon / PlaceholderPattern / Heading / AppearanceTabs
│   ├── 表单：AlertError / InputError / PasswordInput / TextLink / DeleteUser
│   ├── 账户：UserInfo / ManageTwoFactor / TwoFactorSetupModal / TwoFactorRecoveryCodes
│   └── Passkey：ManagePasskeys / PasskeyRegister / PasskeyVerify / PasskeyItem
│   └── ui/  [生成] shadcn-vue 基础件（alert/avatar/badge/.../sidebar/sonner/tooltip 等 24 组）
├── composables/            # useAppearance / useInitials / useCurrentUrl / useTwoFactorAuth
├── lib/
│   ├── utils.ts            # cn()（clsx + tailwind-merge）
│   └── flashToast.ts       # Inertia flash 事件 → vue-sonner toast
├── types/                  # TS 类型（auth / navigation / ui，index.ts 统一 re-export）
├── actions/  [生成] Wayfinder 控制器函数（App/Illuminate/Inertia/Laravel）
├── routes/  [生成] Wayfinder 命名路由函数（login/profile/security/two-factor/...）
└── wayfinder/ [生成] Wayfinder 运行时
```

## 其他目录

| 目录                            | 说明                                                                                                                                                   |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `resources/views/app.blade.php` | 唯一 Blade（Inertia 根模板）                                                                                                                           |
| `resources/css/app.css`         | Tailwind 4 入口（CSS-first 配置、主题变量、bunny 字体 Instrument Sans）                                                                                |
| `config/`                       | 标准 Laravel 配置 + fortify.php / inertia.php；**无 ai.php**（laravel/ai 未发布配置）                                                                  |
| `tests/`                        | Pest；`Pest.php` 绑定 TestCase+RefreshDatabase→Feature/；详见 [PROGRESS.md](PROGRESS.md)                                                               |
| `.github/skills/`               | 8 个 AI 领域技能文档（ai-sdk / fortify / inertia-vue / infer-conventions / laravel-best-practices / tailwindcss / testing-best-practices / wayfinder） |
| `.github/workflows/tests.yml`   | CI 测试工作流                                                                                                                                          |
| `bootstrap/`                    | `app.php`（中间件/异常配置）；`ssr/` [生成]                                                                                                            |
| `public/`                       | 入口 index.php；`build/` [生成]                                                                                                                        |
