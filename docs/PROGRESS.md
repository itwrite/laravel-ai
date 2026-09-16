# PROGRESS — 进度与状态

> 最后更新：2026-09-16
> 上级入口：[AI.md](../AI.md) · 模块明细：[MODULES.md](MODULES.md)

## 📊 当前状态总览

**脚手架初始阶段**。git 历史仅 1 次提交（`b15c752 first commit`），工作区干净，无未提交改动。
项目名 `laravel-ai` 表明目标是构建 AI 应用；`laravel/ai` SDK 已通过 composer 安装，但**业务开发尚未启动**。

判断依据（可复核）：`config/` 无 `ai.php`；`.env` 无任何 `AI_*` / 供应商密钥；`app/` 与 `resources/js/` 内无 `Laravel\Ai` 引用；无自定义 Artisan 命令、无 Job/Event/Notification、无 API 路由。

## ✅ 已完成（Starter Kit 自带，开箱即用）

| 功能 | 状态 | 入口 | 测试 |
|---|---|---|---|
| 首页 Welcome | ✅ | `routes/web.php` → `pages/Welcome.vue` | ExampleTest |
| Dashboard（需登录+验证邮箱） | ✅ | `routes/web.php` → `pages/Dashboard.vue` | DashboardTest |
| 登录/登出 | ✅ | Fortify → `pages/auth/Login.vue` | AuthenticationTest |
| 注册 | ✅ | `Actions/Fortify/CreateNewUser.php` → `pages/auth/Register.vue` | RegistrationTest |
| 忘记/重置密码 | ✅ | `Actions/Fortify/ResetUserPassword.php` → `pages/auth/{Forgot,Reset}Password.vue` | PasswordResetTest |
| 邮箱验证 | ✅ | Fortify → `pages/auth/VerifyEmail.vue` | EmailVerificationTest、VerificationNotificationTest |
| 密码确认 | ✅ | Fortify → `pages/auth/ConfirmPassword.vue` | PasswordConfirmationTest |
| 双因素认证 2FA（TOTP+恢复码+确认流） | ✅ | `Settings\SecurityController` + `ManageTwoFactor*` 组件 + `useTwoFactorAuth` | TwoFactorChallengeTest、SecurityTest |
| Passkeys（WebAuthn 注册/验证/管理） | ✅ | `ManagePasskeys*` 组件 + `passkeys` 表 + `.well-known/passkey-endpoints` | SecurityTest |
| 资料（查看/更新/删除账号） | ✅ | `Settings\ProfileController` → `pages/settings/Profile.vue` | ProfileUpdateTest |
| 改密码（throttle:6,1） | ✅ | `Settings\SecurityController@update` → `pages/settings/Security.vue` | SecurityTest |
| 外观设置（明/暗/system，cookie 持久化） | ✅ | `pages/settings/Appearance.vue` + `HandleAppearance` | — |
| 布局体系（App 壳/认证三式/设置壳） | ✅ | `app.ts` + `layouts/**` | — |
| 主题/布局基础设施（shadcn-vue 24 组 UI 件） | ✅ | `components/ui/**` [生成] | — |
| 工具链（Pint/Larastan lv7/vue-tsc/vite-plus lint/fmt、CI workflow） | ✅ | 各配置文件 | `composer run test` |

测试基线：**12 个测试文件**（Feature 11 + Unit 1），实测 **39 passed / 136 assertions**（2026-09-16，`php artisan test --compact`）。

## 🚧 进行中 / 待启动

| 任务 | 状态 | 说明 |
|---|---|---|
| **laravel/ai 接入**（项目核心目标） | ⬜ 未开始 | 已安装 v0.11.2，需要：① 发布配置（`config/ai.php`）② `.env` 配置供应商密钥（OpenAI/Anthropic/Gemini/Ollama 等任选）③ 编写首个调用（命名空间 `Laravel\Ai\`，`ai()` 门面）。开发前必读 `.github/skills/ai-sdk-development/SKILL.md` |
| 首个 AI 业务功能 | ⬜ 未开始 | 功能形态待用户定义（对话/Agent/嵌入/结构化输出等）。建议服务类落位 `app/Ai/`（新目录需用户批准） |
| README.md | ⬜ 缺失 | 项目根目录无 README（如需补，须用户明确要求） |

## ⚠️ 已知问题 / 技术债

| # | 问题 | 影响 | 建议 |
|---|---|---|---|
| 1 | `laravel/ai` 为 0.x 版本（0.11.2），API 尚未稳定 | 升级可能有 breaking changes | 锁定版本使用；升级前读 changelog + ai-sdk skill |
| 2 | `tests/Console` 无测试，`routes/console.php` 仅有示例 `inspire` 命令 | 无 | 示例命令属脚手架，可忽略 |
| 3 | Starter Kit 脚手架残留：Welcome 页营销内容、`PlaceholderPattern`、未使用的 `laravel/sail` 依赖 | 体积/噪音 | 如需清理，用 `laravel/chisel`（已安装，专为移除脚手架设计）；删依赖需用户批准 |
| 4 | `DatabaseSeeder` 仅造 1 个测试用户 | 本地开发数据少 | 按需扩展；测试一律走 `UserFactory` |
| 5 | 无 README（新协作者/AI 冷启动靠本套 docs） | 低 | 本记忆系统已覆盖；README 待用户要求再建 |
| 6 | User 模型未实现 `MustVerifyEmail`（接口 import 被注释） | 邮箱验证流程依赖 Fortify 特性开关仍可用 | 保持现状（Starter Kit 默认） |

## 📝 变更记录

- 2026-09-16：初始创建本记忆系统（基于 `first commit` 后的代码全量勘察）。

---

**给 AI 的维护规则**：完成/启动任何上表任务后，更新对应行与"最后更新"日期；新增模块时同步更新 [MODULES.md](MODULES.md) 与 [CODEMAP.md](CODEMAP.md)。
