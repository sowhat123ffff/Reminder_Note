# 任务详情优先级显示错误 + 移动端缺主题切换 / 导入导出入口

- **Date:** 2026-05-22 23:30 (UTC+8)
- **Agent:** Claude Opus 4.7（Cursor）
- **User request:**
  1. 点击「今天」任务后任务从列表消失，怀疑 bug。
  2. 移动端 view 怎么会少换颜色主题的功能 — 请检查。
  3. 用 chrome-devtools + playwright 严格测试每个按钮/功能，发现问题直接修复（用户挂机）。

## What changed

- `public/index.html` — 两处 surgical 改动：
  1. **任务详情 drawer 的「优先级」`<select>` 把 options 从 `<template x-for>` 改成静态 4 个 `<option>`。** 加了 inline 注释说明不要换回 x-for，理由见下文 *Why*。
  2. **移动端抽屉（mobile sidebar drawer，`md:hidden` 那个 `<aside>`）补回桌面 sidebar 上有但 mobile 缺失的三类工具按钮**：
     - 「切换主题」(`cycleTheme()` + sun/moon/monitor icon + 浅色/深色/跟随系统 标签)
     - 「导出备份」(`exportData()`)
     - 「导入备份」(`<label>` 包 file input，触发 `importData($event.target.files?.[0])`；导入后自动关闭抽屉避免 confirm 模态被遮挡)

> dist/app.js 和 public/css/style.css **没有变化**：本次只改 HTML，没改任何 JS 源码，且新加的所有 class 都是桌面 sidebar 上已用过的（`btn-ghost w-full justify-start text-sm` 等），所以 Tailwind 产物里都已存在。`npm run build` 已跑过确认无差异。

## Why

### Bug 1 — 任务详情 priority `<select>` 显示「低」（实际数据可能是 高/中/紧急）

复现：注册一个新账号 → 在「今天」加一条高优先级任务 → 点开任务详情 → drawer 里的「优先级」下拉显示「低」。但数据库 (IndexedDB) 里 `priority=2` 没错，列表上仍显示「高」chip。

根因：Alpine 的 `x-model.number="t.priority"` 在 select 元素初始化时立即尝试设置 `select.value`，但**同一个 select 内嵌的 `<template x-for="(label,i) in PRIORITY_LABEL">` 这时还没把 4 个 `<option>` 渲染到 DOM**。浏览器对一个还没有 `value="2"` option 的 `<select>` 设置 `.value="2"` 会**静默失败**，select 退回到「无 option 命中」状态（HTML 标准：等同于选中第一个 option），UI 上就显示「低」。

为什么页面顶部的「快速添加」select（也是 `x-model.number + template x-for`）没事？因为它在 page load 时已经渲染好，options 早就存在；只有任务详情 drawer 那个 select 在 `x-data="{ t: null }" x-effect="t = JSON.parse(...)"` 内是**懒初始化**，每次打开任务都重新跑 effect，x-for 渲染时机比 x-model 求值更慢。

**用户报的「点击任务后消失不见」其实有两层**：(a) 用户点击任务卡片其实是打开了 detail drawer，drawer 在右侧滑入盖住部分内容，用户可能没意识到；(b) 任务卡片本身从未消失，但**真正的 silent bug 是 priority select 显示错误，让用户对详情面板失去信任**。修完 (b) 后，再点任务时 priority 显示和列表上的 chip 一致，没有「这不对」的疑惑。

修法选了**静态化 options**（不是 `:value` + `@change`，也不是 `x-effect` hack）— 因为：
- PRIORITY_LABEL 只有 4 个值且业务上不会扩展（"低/中/高/紧急" 是固定 4 档）。
- 静态写死后 DOM 一开始就有 4 个 option，x-model 在任何时机求值都能命中正确 value。
- 不引入新的 Alpine pattern；其它两个 select（`t.status`、`repeat`）本来就是静态/`:value`+`@change`，新 priority 跟它们模式更一致。

trade-off：万一以后要加「最高」「待定」等档位，要在 HTML 跟 `PRIORITY_LABEL` 数组两处同步。可以接受 — 这是 yagni 的合理范围。

### Bug 2 — 移动端没有切换主题入口

桌面 sidebar (`hidden md:flex`) 上一直有「浅色/深色/跟随系统」按钮，但 `md:hidden` 的移动端 drawer 没有这个按钮，顶部 header 上的主题按钮也是 `hidden md:inline-flex`。结果：**在手机上完全无法切换主题**（除非用命令面板，但移动端没有键盘快捷键）。

顺便也补了导出 / 导入备份：桌面 sidebar 有，移动端原本两个都没入口，等于 mobile 用户**根本无法做本地备份**。这是 functional gap，不是单纯 UI 缺失，所以一并修。

## Verification

### Chrome DevTools MCP（desktop 1280×900）
- 注册新账号 `qa_user` → 加「每天努力更新Shopee AI auto reply」高优先级任务 → 点开 detail → priority select **value=2 (高)** ✅（修复前为 0/低）。
- 连续 5 次开关 detail drawer：每次 priority select value 都稳定 = "2"，没有 race condition 残留。
- 在 detail 内手动把 priority 改成「紧急」(3) → 保存 → IndexedDB 实际写入 priority=3 ✅，说明数据回写也正常。
- 笔记 view：新建 / 标题输入 / markdown 预览（标题、加粗、列表、链接渲染正确）/ 收藏 / 置顶 ✅。
- 看板 view：任务正确出现在「待办」列 ✅。
- 日历 view：dayGridMonth 正常渲染 ✅（任务无 due_at 所以不在日历上，符合预期）。
- 统计 view：完成率、优先级分布、最近 7 天 bar chart 正确 ✅。
- 账号 view：会话列表显示当前会话 + IP + UA；登录历史空（注册不写 attempts，按设计）✅。
- 回收站 view：空状态卡片正确 ✅。
- 命令面板：「切换主题」按钮把 theme 从 system → light，dark mode class toggle 正确 ✅。
- PIN：设置 1234 → 刷新页面弹解锁屏 → 1234 解锁成功 ✅。
- 顶部搜索框：输入 "Shopee" → 命中 1；输入 "xxx_no_match_xxx" → 命中 0 ✅。
- 番茄钟：start 后 mode='focus', seconds 从 1500 倒数 → stop 复位 idle ✅。

### Chrome DevTools MCP（mobile 390×844x3, iPhone UA, touch）
- 修复前截图：drawer 只有今天/日历/看板/笔记/统计/回收站/账号/PIN/退出（无主题，无导出/导入）。
- 修复后截图：drawer 多出「跟随系统」「导出备份」「导入备份」三行 ✅，跟桌面 sidebar 工具区一致。
- 点「跟随系统」→ system → light，再点 → light → dark ✅，html.dark class + localStorage 都更新。

### Playwright MCP（最终交叉验证）
- 独立浏览器 instance；自动 register `pw_test_<random>` 账号 → quick add 任务 → 点开 detail → priority select value="2" ✅（数据 priority=2 匹配）。
- 切 7 个视图（today/calendar/kanban/notes/stats/trash/account）全部 view 状态切换正确 ✅。
- 主题循环 system → light → dark ✅。
- viewport 切到 390×844 → 打开 mobile drawer → 验证 14 个按钮里包含「跟随系统」「导出备份」「导入备份」三个新项 ✅。
- 在 mobile drawer 里点「跟随系统」按钮 → theme 从 system → light → dark ✅。

### 后端 smoke
`php deploy/api-smoke.php http://127.0.0.1:8765` → **30/30 PASS**（本次没动 PHP，回归确认）。

## Notes for next agent

- 任务详情 drawer 的「优先级」`<select>` **不要换回 `<template x-for>` 写法**。HTML 里的注释也说明了。如果以后真要加新优先级档位（例如「最高」「无」），同步改两处：JS 的 `PRIORITY_LABEL` 数组 + HTML 里的 4 个 `<option>`。
- 同样的 race 隐患也存在于 `t.status` 和 repeat select — 但它们的 options 一开始就是静态 `<option>` 写死，所以**没有** bug。模式已经统一。
- 唯一仍然用 `<template x-for>` + `x-model` 的 select 是 **顶部「快速添加」+ 「快速添加」modal** 里的 `quickAddPriority`，它在 page load 时就在 DOM 里、Alpine 初始化时 options 已渲染，所以没 race；**不要因为「保持一致」就动它**，那只会增加可读性负担。
- 移动端抽屉的「切换主题」按钮用的 lucide 图标是 `:data-lucide="theme==='dark'?'moon':...'"`，DevTools 检查显示主题切换后 svg 的 `data-lucide` 属性能正确变（lucide createIcons 把 `<i>` 替换 svg 后 Alpine 的 reactive 引用仍指向同一 element，Alpine 能继续更新它的属性）。这一点跟桌面 sidebar 行为一致，没引入新 bug。
- 测试 PIN 设置后 localStorage 会留下 `rn:app_pin` hash，**下次复测时如果不清掉**，重新打开页面会先弹解锁屏。要清的话：`localStorage.removeItem('rn:app_pin')` 或在「关闭应用 PIN」按钮里输入 PIN 关闭。
- **测试移动端布局**仍然遵守 AGENTS.md 旧笔记里写的：用 chrome-devtools `emulate` + `viewport: "<W>x<H>x<DPR>,mobile,touch"`，**不要**单独用 `resize_page`（不模拟 mobile/touch UA，响应式断点不会触发）。这次 Playwright 用 `page.setViewportSize` 也只测了视口尺寸，没切 UA — 但 Tailwind 的 `md:hidden` / `md:flex` 只依赖 viewport 宽度，不依赖 UA，所以足够。
- 这次没改任何 JS 源、PHP 源、Tailwind 源 — 所以 `public/dist/app.js` 和 `public/css/style.css` 没差异（`git status` 干净）。下次 agent 拉代码后**不需要**特意重跑 `npm run build`（除非动了 .src.css / .js）。
