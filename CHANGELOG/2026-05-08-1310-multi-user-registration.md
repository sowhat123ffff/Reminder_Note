# 多用户：开放注册 + 数据隔离 + 账号设置页

- **Date:** 2026-05-08 13:10 (UTC+8)
- **Agent:** Claude Opus 4.7（Cursor）
- **User request:** 严格按 `.cursor/plans/multi-user_open_registration_3dafca92.plan.md` 实现多用户开放注册、按用户隔离所有 notes/tasks/uploads、新增账号设置页（改密码 / 活跃会话 / 登录历史）；data/app.db wipe fresh；用 MCP Chrome DevTools 全程测试。

## What changed

### 后端

- `database/schema.sql` — `auth_attempts` 加 `user_id` / `user_agent` / `kind` 的 CHECK 约束；`refresh_tokens` 加 `user_agent` / `ip` / `last_used_at`；新增 `idx_auth_attempts_user`。schema 整体已是多用户结构，本次只补完字段。
- `app/Db.php` — 加 `findByIdForUser($table, $id, $userId)` 帮助方法，避免在每个 controller 重复 `WHERE id=? AND user_id=?` 的判断。
- `app/Config.php` / `app/bootstrap.php` — 新增 `Config::resolveJwtSecret()`，当 `jwt_secret` 是 `'auto'` / 占位符 / 空时，自动到 `data/.jwt_secret` 读取或生成 64 字节随机串；bootstrap 启动时调用一次。
- `app/Auth.php` — 几乎重写：
  - 新增 `register()`：用户名 `^[a-zA-Z0-9_]{3,32}$`、密码 ≥ 8、IP 维度速率限制（每 60s ≤ 3 次）、bcrypt cost 12、自动签 token。
  - `login()` 改查 `users` 表；登录失败时若用户名存在仍写 `user_id` 到 `auth_attempts`（仅 owner 自己能在「登录历史」里看到），保持响应内容统一以避免用户名枚举。
  - `issueTokens()` 接收 `(uid, ip, ua, ?reuseJti)`，写 `refresh_tokens.user_id/user_agent/ip/last_used_at`；access token 加 `pjti` 字段携带「父 refresh token 的 jti」，让账号页能识别「当前会话」、改密码能精确保留当前会话。
  - 新增 `changePassword()`、`listSessions()`、`revokeSession()`、`revokeAllSessionsExceptCurrent()`、`loginHistory()`、`touchCurrentSession()`、`userId()`、`currentJti()`。
  - `requireAccess()` 在请求生命周期内缓存 payload，避免重复 HMAC 验签。
- `app/controllers/AuthController.php` — 加 `register/changePassword/sessions/revokeSession/revokeAllSessions/loginHistory`；`me()` 返回 `user.{id,username,created_at}` + `session.{jti,exp}`。
- `public/api/index.php` — 注册新路由：`POST /auth/register`（公开）、`PATCH /auth/password`、`GET /auth/sessions`、`DELETE /auth/sessions/{jti}`、`DELETE /auth/sessions`、`GET /auth/login-history`（后五个鉴权）。
- `app/Router.php` — 鉴权路由分发后顺手 `Auth::touchCurrentSession()`，写入 `last_used_at`，让账号页的「最后活动」准确。
- `app/Request.php` — `Request` 增加 `userAgent` 字段，从 `HTTP_USER_AGENT` 读。
- `app/controllers/NoteController.php` / `TaskController.php` / `SyncController.php` — 所有 SQL 加 `user_id = :uid` 过滤，INSERT 带 `user_id`。`SyncController::push` 始终用当前 token 的 uid，客户端发什么 `user_id` 全覆盖；遇到「同 id 但属于他人」直接返回 `id_conflict` 拒绝。
- `app/controllers/UploadController.php` — uploads 路径从 `/uploads/yyyy/mm/<rand>.ext` 改成 `/uploads/<uid>/yyyy/mm/<rand>.ext`；`attachments` 行写 `user_id`。

### 前端

- `public/register.html` — 新建：3–32 位用户名 + ≥ 8 位密码 + 二次确认；成功后 `wipeIndexedDB()` → 存 token → 跳 `index.html`。
- `public/login.html` — 删「默认账号 jian/123456」提示；底部加「注册」链接；登录成功也 `wipeIndexedDB()` 防止跨账号串数据。
- `public/js/db-local.js` — 暴露 `wipeAll()`：清空 `tasks` / `notes` / `meta` 三张表（包含 `meta:lastSyncAt`）。
- `public/js/api.js` — 新增 `api.register / changePassword / listSessions / revokeSession / revokeAllSessions / loginHistory`；`api.login()` 成功后、`api.logout()` 之前都调用 `wipeLocalCache()`。
- `public/index.html` — 侧边栏 / 移动 drawer 加「账号 · `<username>`」入口；新增 `view==='account'` 区块：改密码表单 + 活跃会话列表（带「当前会话」徽标 / 单注销 / 一键全部注销）+ 最近 30 天登录历史。
- `public/js/app.js` — 加 `me` 状态（启动时调 `api.me()` 拿用户名）；新增 `accountView` Alpine 数据 + `formatDateTime` / `formatRelative` / `parseUA` 帮助函数；`VIEWS` 加 `'account'`。

### 配置 / 部署 / 文档

- `config/config.example.php` — 删 `username` / `password_hash`；`jwt_secret` 默认 `'auto'`；加 `register_rate_limit`。
- `config/config.php` — 与 example 对齐（仍是 gitignore，不会上传）。
- `.gitignore` — 加 `/data/.jwt_secret`。
- `deploy/api-smoke.php` — 改写：自动 register 两个临时用户，做完整隔离 + sync push 防越权 + 改密码 + 登录历史校验，27 项断言。
- `README.md` — 重写「Local development」/「多用户/安全笔记」/「重置数据库」段；删 `deploy/hash.php` 步骤；改 URL 列表。

## Why

要把单用户 + 硬编码 jian/123456 升级为多用户开放注册，并让数据严格按用户 id 隔离；同时给用户一个能自助审计的「账号设置页」（改密码 / 看活跃会话 / 看登录历史），减少「被偷窥」之后的盲区。

## Verification

服务器：`php -S 127.0.0.1:8765 dev-router.php`。

1. **API smoke (27 个断言)**：`php deploy/api-smoke.php` 全部 PASS。覆盖：
   - 注册 A / B、login A、me A、tasks/notes CRUD（A）；
   - B 列出 tasks/notes 看到 0 条（隔离）；
   - B 用 A 的 task id PATCH 必须 404（不能枚举他人 id）；
   - B 用 A 的 id sync push → 拒绝 `id_conflict`；
   - 改密码后旧密码 401、新密码 200；
   - 登录历史 ≥ 3 条记录。

2. **MCP Chrome DevTools 端到端浏览器测试**（同一会话里跑完）：
   - 进入 `/login.html` → 没有默认账户提示；点「注册」→ 填 `alice / pwAlice123` → 自动跳 index → 侧边栏写「alice」；console / network 全绿。
   - alice 建一条 note `alice's secret`；查看「账号设置」→ 1 个会话「Chrome · Windows · 当前会话」、登录历史 0 条（注册不算 login）。
   - 退出登录 → IndexedDB 三张表均 0 条（DevTools 验证）→ 注册 `bob / pwBob123x` → 笔记列表为空（看不到 alice 的）→ 创建 `bob's secret`。
   - 再退出 → 用 alice 登入 → 看到 `alice's secret`、bob 的看不到；账号页登录历史多了一条「成功」。
   - 用 curl 模拟「Safari · iOS」+「Firefox · Linux」第二、第三个会话 → 账号页显示 3 个 session、UA 解析正确；点 Safari 的「注销」→ 弹确认 → 确认后剩 2 个 → 服务器对该 jti 的 refresh 返回 401（DevTools 直调验证）。
   - 改密码（旧 → 新）→ 提示「密码已修改，其它设备已强制下线」→ 活跃会话立刻只剩当前 Chrome；旧密码 401，新密码 200，登录历史出现「失败 / 成功」两条。
   - 刷新页面 → 仍以 alice 登入，notes 仍可见。
   - 切到 375×667 移动视口 → 顶部 hamburger 菜单 → 进入「账号：alice」→ 卡片整齐排列、按钮可点、登录历史 7 条全部显示。

console 唯一常驻提示是「`<meta name="apple-mobile-web-app-capable">` 已废弃」+「PIN 模态框的 password input 缺 username 兄弟」（前者是 PWA 兼容标志、后者是浏览器 a11y 友好提示，**两者都是改造前就存在的**，与本次改动无关）。

## Notes for next agent

- **JWT secret 现在自动生成在 `data/.jwt_secret`**（128 hex char = 64 byte）；删了它会让所有现有 token 失效（前端会自动跳 `/login.html`）。
- **删 `data/app.db` = 完全 wipe**：所有用户 / notes / tasks / 会话 / 登录历史一并消失。
- **`Auth::requireAccess()` 现在缓存当前请求的 payload**；每条受保护路由分发后 Router 顺手 `touchCurrentSession()` 写 `last_used_at`。如果新加路由时不想触发这个 UPDATE，可以在 Router 自定义。
- **access token 携带 `pjti`**（父 refresh token 的 jti）。这是判定「当前会话」和「改密码后保留当前 session」的关键，新加 token 字段时不要破坏。
- **改密码不踢当前会话**：靠 `pjti` 比较，所以**不要**在客户端把 refresh token 也传给 `/auth/password`，多余且增加被截获面。
- **login 失败时仍写 `user_id`**：表上不暴露给外人，仅 owner 在登录历史里能看到。`/auth/login` 响应内容对「用户名不存在」和「密码错」一致（都 401 + `invalid_credentials`），不会泄露用户名是否存在。
- **Sync push id 冲突**：客户端不能用别人占用的 id 创建新行，会被拒为 `id_conflict`。客户端的 UUID v4 几乎不会撞，但要记得这个 contract。
- **Uploads 路径**：从 `/uploads/yyyy/mm/<rand>.ext` 改成 `/uploads/<uid>/yyyy/mm/<rand>.ext`。`config['uploads_url']` 还是 `/uploads`，URL 是 `<uploads_url>/<uid>/<yyyy>/<mm>/<rand>.ext`。新加附件相关功能时注意路径前缀。
- **`account` 视图初始化在 Alpine x-data 时立刻 `loadSessions()` + `loadHistory()`**——即便用户从没切到该视图，也会发两个 GET。如果将来想节省一点流量，可以改成 `init` 里只在 `view==='account'` 时拉。
- **「mobile 底部 tab」只有 5 项（today/calendar/kanban/notes/stats）**。account / trash / 退出 都在汉堡 drawer。如果要把 account 加到底栏，得先想好删哪一项。
- **rate limit table 已经迁到 `auth_attempts`**，`Auth.php` 不再读 / 写 `login_attempts`。schema.sql 里也没有 `login_attempts` 这张表了。
