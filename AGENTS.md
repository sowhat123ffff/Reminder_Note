# AGENTS.md — Reminder_Note 项目记忆

> **动任何文件之前先读这份**。这是上一个 Agent 留下的交接笔记。
> 然后再扫一眼 `CHANGELOG/` 里最新的 3 个文件。
>
> 工作流规则：`.cursor/rules/project-workflow.mdc`（always-apply，强制）。
> 行为准则：`.cursor/skills/karpathy-guidelines/SKILL.md`（强制）。

## Project at a glance
- 后端：PHP 8 + SQLite（PDO + WAL）。**多用户、开放注册**。
- 前端：Tailwind v3 + Alpine.js + Dexie（IndexedDB）。
- PWA（原生 Service Worker）、JWT（access 15 min + refresh 30 day）、offline-first。
- 安装/启动看 `README.md`。本地开发地址：`http://localhost/Reminder_Note/public/login.html`。
- 入口页：未登录 → `/login.html`（底部有「注册」链接）→ `/register.html`。

## Current state
- 多用户架构落地完成；所有 notes / tasks / attachments / refresh_tokens / auth_attempts 都按 `user_id` 隔离；账号设置页（改密码 / 活跃会话 / 最近 30 天登录历史）可用。
- `data/app.db` + `data/.jwt_secret` 都会自动按需创建，不需要手动 hash 密码或填 secret。
- Today view 列表用 `<div x-show> + <template x-for>` 模式（之前 nested `<template x-if>` 包 `<template x-for>` 有 reactivity race，会让"点击 today 后任务全消失，F5 才回来"）。任务卡片的 swipe 手势 handler 也改了：只有判定真要 toggle/delete 才 preventDefault，普通 tap 不再阻止 click（修复触屏 tap 任务卡片不弹 detail）。
- 任务详情 drawer 的「优先级」select 已修复（之前因 Alpine x-for + x-model race 总显示「低」）；移动端抽屉补回了「切换主题 / 导出 / 导入备份」三个原本仅桌面有的按钮。
- 没有 Agent 残留的 WIP。

## Last change
- 2026-05-23 13:40 (UTC+8) — 修两个真实 bug：(1) **「今天」list 点击后整片消失，F5 才回来**：root cause 是 `<template x-if="today.X.length"> > <ul> > <template x-for>` 嵌套加外层 `<section x-show="view==='today'">` 在 view 切换时触发 Alpine reactive race，`<template x-if>` 误判 falsy 导致 ul 不渲染。把 today view 内 4 处（overdue/todoNow/later/done）从 nested `<template x-if>` 改成 `<div|ul|details x-show=…>` + 内嵌 `<template x-for>`，DOM 始终在，仅切 display:none。(2) **触屏 tap 任务卡片不弹 detail**：去掉 `@touchend.prevent`，改成 `swipeTask()` 内部判断到真要 swipe action 时才 `ev.preventDefault()`。`public/index.html` (239 lines diff) + `public/js/app.js` (13 lines diff)，dist 重建。后端 30/30 仍 PASS。详情：[`CHANGELOG/2026-05-23-1340-today-list-disappear-and-touch-tap.md`](CHANGELOG/2026-05-23-1340-today-list-disappear-and-touch-tap.md)。
- 2026-05-22 23:30 (UTC+8) — 两处 surgical 改 `public/index.html`：(1) 任务详情 drawer 的优先级 `<select>` 把 options 从 `<template x-for>` 静态化为 4 个写死 `<option>`，根除 Alpine x-for 渲染时机晚于 x-model 求值导致 select 首次打开恒显示「低」的 race；(2) 移动端抽屉 (`md:hidden <aside>`) 补回桌面 sidebar 一直有但 mobile 缺失的「切换主题 / 导出备份 / 导入备份」三类按钮（导入按钮在选完文件后自动关抽屉避免 confirm 模态被遮）。无 JS / PHP / Tailwind 改动，所以 dist/css 产物没变。Chrome-DevTools MCP + Playwright MCP 都跑过完整 E2E，desktop+mobile 各种视图 / 主题 / PIN / 番茄钟 / 搜索 / 命令面板都通；`php deploy/api-smoke.php` 仍然 30/30 PASS。详情：[`CHANGELOG/2026-05-22-2330-task-detail-priority-and-mobile-tools.md`](CHANGELOG/2026-05-22-2330-task-detail-priority-and-mobile-tools.md)。
- 2026-05-10 18:20 (UTC+8) — 修两个静默数据丢失 bug：(1) `sync.js` 的 `pushDirty` 对所有 push 上去的记录都清 dirty，包括服务端 reject 的，导致「UI 显示同步成功但服务端没数据」。改成只对 `applied` 清。(2) `app.js` 的 `importData` 复用备份文件里的 id 导入到不同账号，必然撞 server `id_conflict`，留下 ghost data；改成每条记录重分配 id（=「迁移到本账号」语义）。重建 `dist/app.js`。详情：[`CHANGELOG/2026-05-10-1820-import-cross-user-and-sync-reject-bugs.md`](CHANGELOG/2026-05-10-1820-import-cross-user-and-sync-reject-bugs.md)。
- 2026-05-09 04:45 (UTC+8) — 账号设置视图的移动端 wrap 问题：「活跃会话」/「登录历史」头部按钮在 < sm 时被挤成多行；改 `whitespace-nowrap` + `flex-wrap` + 短文案「全部注销」（仅 < sm）。重建 Tailwind 让 `sm:inline` / `sm:hidden` 进入 css 产物。详情：[`CHANGELOG/2026-05-09-0445-account-view-mobile-layout-fix.md`](CHANGELOG/2026-05-09-0445-account-view-mobile-layout-fix.md)。

## Known pitfalls
- Apache 500 几乎都是 `mod_rewrite` / `AllowOverride All` 的问题 — 见 README "看到 500 Internal Server Error" 那节。
- `public/css/style.css` 和 `public/dist/app.js` 是 **构建产物**。首次启动或 pull 之后必须先跑 `npm run build`，否则前端是空的。
- 不要直接改 `vendor/`、`node_modules/`、`data/`、`public/dist/`。
- 不要把 `data/app.db`、`data/.jwt_secret`、`config/config.php` 提交到 git（已在 `.gitignore`）。
- **JWT secret 自动生成在 `data/.jwt_secret`**。删了它 = 让所有现有 token 失效（所有客户端会被弹回登录页）。要轮换 secret 时这是唯一手段。
- **`config/config.php` 里不再有 `username` / `password_hash`**。如果你看到旧 schema 残留，从 `config.example.php` 重抄一份。
- **删 `data/app.db` 即完整 wipe**（所有账号、笔记、任务、会话历史一并清空），下次请求会按当前 schema 自动重建。
- **access token 里有 `pjti` 字段**（父 refresh token 的 jti）。账号页判定「当前会话」、改密码保留当前会话都依赖这个字段。新加 token claims 时不要覆盖。
- **Sync push 强制覆盖客户端的 `user_id`**，并对「同 id 但属于他人」直接 reject (`id_conflict`)。客户端的 UUID v4 极少撞，但合约要记得。
- **uploads 路径加了 `<uid>/` 前缀**（`/uploads/<uid>/yyyy/mm/<rand>.ext`），仍靠不可猜测的随机文件名 + `.htaccess` 禁 PHP 执行；没有引入鉴权代理。
- **登录失败也会写 `user_id`** 到 `auth_attempts`（仅 owner 自己能在「登录历史」里看到），用来发现「有人在猜我密码」。响应仍统一返回 `invalid_credentials` 不暴露用户名是否存在。
- **测试移动端布局**用 chrome-devtools `emulate` + `viewport: "<W>x<H>x<DPR>,mobile,touch"`，**不要**只靠 `resize_page`（后者不会触发 `mobile`/touch 模拟，也常常不让 Tailwind 响应式断点重新计算）。
- **改了 HTML 引入新 Tailwind utility class（如 `sm:inline`、`break-all`）后必须 `npm run build:css`**，否则 dist 里没有那些类、效果不会生效。
- **`pushDirty` 现在只对 server `applied` 的记录清 dirty**，被 reject 的（id_conflict / missing_title / invalid_id ...）保持 dirty=1 下次 sync 重试。如果未来你看到「这条 task 永远 dirty 但 push 不上去」，去 DevTools network 看 `/api/sync/push` 响应里 `rejected[]`—别再去把 dirty 强清掉。
- **`importData` 永远给每条记录重分配 id**（不复用 backup 里的 id），所以同账号反复 import 同一份备份会产生重复条目。语义上备份 = 「贴回任何账号都安全的归档」，不是「同账号无重复合并」。
- **不要把 `data/_backup_*.json` 这类临时测试 backup 文件 commit 进 git**（不在 .gitignore 里，但临时文件就别留了）。
- **任务详情 drawer 的优先级 `<select>` options 是写死的 4 个 `<option>`，不要换回 `<template x-for>`**。Alpine 的 x-for 在懒初始化的容器（drawer 里 `x-data + x-effect` 模式）渲染比 x-model 求值晚，导致 select 首次打开恒显示「低」(value=0)，但实际 `t.priority` 数据是对的 — 是 UI race，不是数据 bug。若以后要加新优先级档，要同步改两处：`PRIORITY_LABEL` 数组 + HTML `<option>`。同一文件里的 `t.status` / repeat select 一直是静态写法所以无此问题；快速添加 select 是 page-load 时已渲染所以也无此问题。详情见 `2026-05-22-2330` changelog。
- **移动端抽屉（mobile `md:hidden <aside>`）现在跟桌面 sidebar 一样有「切换主题 / 导出 / 导入备份」三个按钮**。`<label>` 包 file input 的「导入备份」按钮在选完文件后会自动 `sidebarOpen=false` 关掉抽屉 — 这是为了避免 import 的 confirm 模态被抽屉遮挡。如果以后要给 mobile 抽屉加新按钮，记得：桌面/移动两份现在是「同一组工具区」语义，加东西时考虑两边对齐。
- **Today view 的 overdue / todoNow / later / done 列表区改用 `<div x-show=…>` 或 `<ul x-show=…>` / `<details x-show=…>` + 内嵌 `<template x-for>` 模式，不要换回 `<template x-if> > <ul> > <template x-for>` 嵌套**。原因：嵌套模式在 view 切换（外层 `<section x-show>` toggle）时偶发 Alpine reactivity race —— `<template x-if>` 被误判 falsy，列表整片不渲染，但标题 `today.todoNow.length` 还是数字 — UI 上看就是「点击今天后任务消失，F5 才回来」。叶子级 single-element `<template x-if>`（用来切 chip / span 的）保留不动，那些没 race。详情见 `2026-05-23-1340` changelog。
- **Task / note 卡片上 `swipeTask()` 现在自己负责 `preventDefault`**：`@touchend` 上**不再用 `.prevent` 修饰符**，否则会吃掉合成 click → 触屏 tap 任务卡片永远不弹 detail。`swipeTask()` 内只有 `dx < -56`（左滑 toggleDone）或 `dx > 56`（右滑 deleteTask）才 `ev.preventDefault()`；普通 tap (|dx| ≤ 56) 让 click 自然冒泡。改 swipe 阈值或加新手势时保持这个语义。

## Conventions
- 改 PHP / JS 时**贴**现有风格，不要顺手 reformat。
- 任何"顺手优化"都属于 surgical changes 违规，一律禁止。
- 改完 → 更新本文件的 `Current state` / `Last change` / `Known pitfalls`，再写 `CHANGELOG/` 条目。
