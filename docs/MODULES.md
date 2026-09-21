# MODULES — 功能模块明细

> 最后更新：2026-09-16
> 上级入口：[AI.md](../AI.md) · 架构背景：[ARCHITECTURE.md](ARCHITECTURE.md) · 状态汇总：[PROGRESS.md](PROGRESS.md)

状态图例：✅ 完成可用 · 🚧 进行中 · ⬜ 未开始

---

## 1. 认证模块（Auth）✅

由 **Fortify 1.39** 驱动，无自建认证控制器；视图全部替换为 Inertia 页面。

| 项       | 内容                                                                                                                                                                                         |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 服务注册 | `app/Providers/FortifyServiceProvider.php`（动作绑定、Inertia 视图钩子、登录限流 `Limit::perSecond(5)` 附近策略）                                                                            |
| 动作     | `app/Actions/Fortify/CreateNewUser.php`、`ResetUserPassword.php`                                                                                                                             |
| 共享规则 | `app/Concerns/PasswordValidationRules.php`                                                                                                                                                   |
| 端点     | Fortify 内建（`/login`、`/register`、`/logout`、`/forgot-password`、`/reset-password/{token}`、`/email/verification-notification`、`/confirm-password`、2FA 六件套、passkey 注册/验证/删除） |
| 页面     | `resources/js/pages/auth/*`（Login、Register、ForgotPassword、ResetPassword、ConfirmPassword、VerifyEmail、TwoFactorChallenge）                                                              |
| 前端支撑 | `layouts/AuthLayout.vue` → `auth/{AuthCard,AuthSimple,AuthSplit}Layout.vue`；`composables/useTwoFactorAuth.ts`                                                                               |
| 数据     | `users` 表（含 2FA 列）+ `passkeys` 表                                                                                                                                                       |
| 依赖     | `Laravel\Fortify\*`（`Features`、`Fortify` 门面）                                                                                                                                            |
| 测试     | `tests/Feature/Auth/`（Authentication / Registration / PasswordReset / EmailVerification / VerificationNotification / PasswordConfirmation / TwoFactorChallenge，共 7 个）                   |

**修改认证行为** → 动作类或 `FortifyServiceProvider`；**修改认证页面** → `pages/auth/*`。

---

## 2. 设置模块（Settings）✅

| 子功能         | 后端                                                                                                 | 前端                                                                                                                                      | 路由                                       |
| -------------- | ---------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------ |
| 资料           | `Settings\ProfileController`（edit/update/destroy）+ `ProfileUpdateRequest` / `ProfileDeleteRequest` | `pages/settings/Profile.vue`、`components/{DeleteUser,UserInfo,TextLink}.vue`                                                             | `GET/PATCH/DELETE settings/profile`        |
| 密码           | `Settings\SecurityController@update` + `PasswordUpdateRequest`                                       | `pages/settings/Security.vue`、`PasswordInput.vue`                                                                                        | `PUT settings/password`（throttle:6,1）    |
| 2FA / Passkeys | `Settings\SecurityController@edit` + `TwoFactorAuthenticationRequest`                                | `ManageTwoFactor.vue`、`TwoFactorSetupModal.vue`、`TwoFactorRecoveryCodes.vue`、`ManagePasskeys.vue`、`Passkey{Register,Verify,Item}.vue` | `GET settings/security`（RequirePassword） |
| 外观           | 纯前端 + `HandleAppearance` 中间件                                                                   | `pages/settings/Appearance.vue`、`AppearanceTabs.vue`、`useAppearance.ts`                                                                 | `GET settings/appearance`                  |

布局：`layouts/settings/Layout.vue`（与 AppLayout 嵌套）。测试：`tests/Feature/Settings/{ProfileUpdateTest,SecurityTest}.php`。

---

## 3. 应用壳与 UI 体系（App Shell / UI）✅

| 项       | 内容                                                                                                                                                  |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| 布局装配 | `resources/js/app.ts`（页面名→布局映射，见 [ARCHITECTURE.md](ARCHITECTURE.md) §4.2）                                                                  |
| App 壳   | `AppShell` / `AppHeader` / `AppSidebar` / `AppSidebarHeader` / `AppContent` / `NavMain` / `NavFooter` / `NavUser` / `UserMenuContent` / `Breadcrumbs` |
| 基础件   | `components/ui/**`（shadcn-vue new-york-v4，24 组，[生成]勿手改；新增用 shadcn CLI）                                                                  |
| 主题     | `resources/css/app.css`（Tailwind 4 CSS-first + 主题 CSS 变量）、`useAppearance`（明/暗/system，cookie `appearance`）                                 |
| 反馈     | `lib/flashToast.ts`（Inertia flash → vue-sonner）、`AlertError` / `InputError`                                                                        |
| 工具     | `lib/utils.ts` 的 `cn()`                                                                                                                              |
| 依赖     | reka-ui、@lucide/vue、class-variance-authority、clsx、tailwind-merge、tw-animate-css、vue-sonner、vue-input-otp、@vueuse/core                         |

**新增页面**：只建 `pages/<模块>/X.vue` + 注册路由，布局自动生效。

---

## 4. AI 能力模块（AI）🚧（项目核心目标，基础链路已通）

| 项    | 内容                                                                                                                                                                                                                                                                                                                         |
| ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| SDK   | `laravel/ai` v0.11.2（已安装）。命名空间 `Laravel\Ai\`；Agent 模式：实现 `Agent` 接口 + `Promptable` trait；可选 `HasTools` / `HasMiddleware` / `HasStructuredOutput` / `Conversational`                                                                                                                                     |
| 配置  | `config/ai.php`（已发布，`default` 由 `AI_DEFAULT_PROVIDER` env 切换）；**智谱 `zhipu` provider 已接入**（`openai-compatible` 驱动，`ZHIPU_BASE_URL`/`ZHIPU_API_KEY`/`ZHIPU_MODEL`，默认模型 glm-4.6）；`.env` 当前 `AI_DEFAULT_PROVIDER=zhipu` + `glm-4-flash`（实测真实对话通过）                                          |
| Agent | **注册表 `app/Ai/ChatAgents.php`（enum：General/Teacher/PrintTemplate → name/description/suggestions/agentClass/catalog）**；**目录式结构** `Agents/<Name>/<Name>.php`（命名空间 `App\Ai\Agents\<Name>`）：`ChatAgent/`（通用助手，内联指令）+ `TeacherAgent/`（教师人设：出试卷/练习题册）+ `PrintTemplateAgent/`（打印模板助手：JSON schema 版提示词，输出模板编辑器可直接还原的画布 JSON）；Teacher/PrintTemplate 的 `instructions.md`+`data.json` 与类同目录，经 `__DIR__` 加载；均 Agent + Conversational + Promptable；`ChatAgent::configured()` 探测默认 provider |
| 端点  | `GET /chat`（页面，公开，渲染 `ChatAgent`）+ `POST /chat`（`routes/web.php`，throttle:10,1，公开）→ `app/Http/Controllers/Ai/ChatController@stream` 返回 SSE 流；验证 `app/Http/Requests/Ai/ChatRequest`（message ≤4000（`required_without:images`）+ images ≤3 张 data URL（单条 ≤200 万字符，regex 白名单 png/jpeg/gif/webp）+ history ≤20 条且单条 ≤10000，role 白名单 user/assistant）；图片经 `images()` 转 SDK `Base64Image` 附件 |
| 前端  | 聊天独立页 `resources/js/pages/ChatAgent.vue`（无布局：气泡流式渲染、建议提示、停止/新对话、未配置警告 Alert、Enter 发送、header 返回首页、**图片选择/粘贴/预览/移除 + 代码块复制事件委托**）；首页 `Welcome.vue`（原营销页，导航 "AI Chat" 按钮 + 列表链接为入口）；`resources/js/lib/streamChat.ts`（fetch + X-XSRF-TOKEN + SSE 解析 `text_delta` + history 规范化 + images）；`lib/markdown.ts`（marked + KaTeX + hljs + DOMPurify + 代码块复制按钮包裹）；类型 `types/ai.ts` |
| 测试  | `tests/Feature/Ai/ChatTest.php`（11 个：流式响应/验证失败/history 白名单/长历史/超限/页面渲染/provider 解析/agent 路由，用 `ChatAgent::fake()`） |
| 必读  | `.github/skills/ai-sdk-development/SKILL.md`（含文档检索方法）；SSE 事件格式：`data: {"type":"text_delta","delta":"…"}\n\n`，终止符 `data: [DONE]`                                                                                                                                                                           |
| 待办  | `glm-4.6` 对当前 Key 429 限流（`glm-4-flash` 可用）；换模型只改 `ZHIPU_MODEL`；后续可扩展 tools/结构化输出/会话持久化                                                                                                                                                                                                        |

---

## 5. 工具链模块（Tooling）✅

| 工具             | 配置位置                                    | 要点                                                                    |
| ---------------- | ------------------------------------------- | ----------------------------------------------------------------------- |
| Vite (vite-plus) | `vite.config.ts`                            | 命令 `vp`；字体 bunny Instrument Sans；生成目录已从 watch/lint/fmt 排除 |
| Wayfinder        | vite 插件 `wayfinder({formVariants: true})` | 改路由后 dev/build 自动再生成 `resources/js/{actions,routes}/**`        |
| Pint             | `pint.json`                                 | preset laravel；提交前 `vendor/bin/pint --dirty`                        |
| Larastan         | `phpstan.neon`                              | level 7；范围 `app/ bootstrap/app.php config/ database/ routes/`        |
| Pest             | `phpunit.xml`、`tests/Pest.php`             | sqlite :memory:；Feature 全局 RefreshDatabase                           |
| Laravel Boost    | `.ai/mcp/mcp.json`、`AGENTS.md`             | MCP 工具（search-docs / database-query / database-schema 等）           |
| Chisel           | composer 依赖                               | 移除脚手架用（未执行过清理）                                            |
| CI               | `.github/workflows/tests.yml`               | 对应 `composer run ci:check`                                            |

---

## 6. 模块依赖关系速览

```
认证(Fortify) ──► User 模型 ──► 设置模块（复用 auth.user）
设置模块 ──► App壳（布局/组件）
AI模块(未来) ──► laravel/ai（独立于上述模块，可并行开发）
工具链 ──► 全部模块（Wayfinder 随路由变动、Pint/Pest 随提交）
```
