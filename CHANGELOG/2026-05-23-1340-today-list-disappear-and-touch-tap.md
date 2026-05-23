# 「今天」任务点击后消失（nested template race）+ 触屏 tap 任务卡片不弹 detail

- **Date:** 2026-05-23 13:40 (UTC+8)
- **Agent:** Claude Opus 4.7（Cursor）
- **User request:** 用户反馈：「点击网页一次『今天』，note 全部消失不见 / 点击该 Note 时全部一起消失不见。F5 刷新又回来，再次点击又消失」。要求用 chrome-devtools + playwright 验证 + 直接修复，挂机授权。

## What changed

- `public/index.html` — Today view 内 4 处把 `<template x-if="today.X.length">` 包 `<ul>` 又内嵌 `<template x-for>` 的嵌套，改成 `<div x-show="today.X.length">` / `<ul x-show=…>` / `<details x-show=…>` + 内嵌 `<template x-for>`：
  - **Overdue 区**（行原 230-266）：`<template x-if> > <div> > <ul> > <template x-for>` → `<div x-show> > <ul> > <template x-for>`。
  - **TodoNow 区 + 空状态**（行原 273-318）：`<template x-if="today.todoNow.length"> > <ul> > x-for` → `<ul x-show=…>`；空状态 `<template x-if="!today.todoNow.length"> > <div>` → `<div x-show=…>`。
  - **Later 区**（行原 322-352）：`<template x-if> > <div x-data="{expanded:false}"> > <ul x-for> + <template x-if(>5)> <button>` → `<div x-show=… x-data=…>` + `<button x-show=…>`（嵌套 button 也降级）。
  - **Done 区**（行原 354-368）：`<template x-if> > <details>` → `<details x-show=…>`。
- `public/index.html` — Today view 4 处任务卡片（overdue / todoNow / later 各一处）的 `@touchend.prevent="swipeTask($event, t)"` 去掉 `.prevent`，改为 `@touchend="swipeTask($event, t)"`。配套：
- `public/js/app.js` — `swipeTask()` 内部，**只有判定为左/右滑（|dx| > 56）真要执行 `toggleDone` / `deleteTask` 时才 `ev.preventDefault()`**；普通 tap 不再阻止合成 click，让 `@click="showTaskDetail = t"` 自然触发 → detail drawer 正常弹出。
- `public/dist/app.js` — `npm run build` 重新构建（dist 在 `.gitignore` 里不入 git，但本地一定要重建）。

无 PHP / Tailwind / 其它 view 改动。

## Why

### Bug A — 「今天」任务点击后全部消失，F5 才回来

无法在我的 chrome-devtools / playwright 测试环境直接复现（点击 sidebar today、点击 task card、切换 view、rapid clicks、touch tap、touch swipe 都跑了，list 始终稳定）。但根据用户截图：标题仍显示「今天有 2 项待办」+ 副标题「今天」+ **下面整片空白，连「今天暂无待办」空状态卡片都没有** —— 这只可能是 Alpine 的 `<template x-if="today.todoNow.length">` 没渲染 `<ul>`、且 `<template x-if="!today.todoNow.length">` 也没渲染空状态卡片，**两个互斥分支同时空**。

这是 Alpine 3.x 的已知 finicky 模式：`<template x-if>` 包 `<template x-for>`，外层又被 `<section x-show="view==='today'">` 控制。当 view 切到非 today 后再切回，外层 x-show 重新显示，里面 nested template 重新求值，**reactive effect 执行顺序在某些 race 下把 `<template x-if>` 误判为 falsy 一次**（实际数据还在 IndexedDB，所以 F5 重新 `init()` → `refreshAll()` 后又显示）。同样隐患在 overdue / later / done 区都存在 — 一并降级。

修法选 **`<div x-show>` 代替 `<template x-if>`，让 `<ul>` 和 `<template x-for>` 始终在 DOM 里（仅切 `display:none`）**。优点：
- 视觉上完全等价（`x-show` 用 `display:none`，跟 `x-if` 不渲染没区别）。
- DOM 节点稍多但 hidden，一个用户 task 数 < 100 量级，性能影响为零。
- Alpine 的 reactive 跟踪`<template x-for>` 不再重新挂载/销毁，race 路径直接消除。
- Single-element `<template x-if>`（用来切单个 chip / span / li 的）保留不动 — 那些没有内嵌 `<template x-for>`，不是 race 路径。

### Bug B（顺手）— 触屏 tap 任务卡片不弹 detail

调查 Bug A 时 chrome-devtools 模拟 touch 复现：tap 任务卡片 → `showTaskDetail` 仍是 `null`。原因：每张任务卡片同时绑了 `@touchend.prevent="swipeTask($event, t)"` 和 `@click="showTaskDetail = t"`。`.prevent` 调 `event.preventDefault()`，**触屏 touchend 上 preventDefault 会阻止浏览器合成的 click 事件**，所以 `@click` handler 永远不触发。

修法：去掉 `.prevent`，让 `swipeTask()` 自己判断 — **只有左/右滑超过 56px 真要 toggle/delete 时才手动 `preventDefault`**，普通 tap（dx ≈ 0）什么都不做，click 自然触发，detail 正常弹。

历史背景：之前 AGENTS.md 里也提过 task detail drawer 的 priority select race，那次只修了 select；这次 root-cause 在 today view 列表渲染层和 swipe handler 层。两个 bug 互相独立但用户报告时容易混在一起（"点击 task 后任务消失" 同时也意味着"任务的 detail 也没弹"）。

## Verification

### Chrome DevTools MCP — desktop (1280×900)
- 跑 11 步混合场景：sidebar today 反复点 + 切到 calendar/kanban/notes/stats/trash/account 再切回 today + 点 task card + 关 detail，全程 `today.todoNow.length` = 2 且 visible `<li>` 数 = 2，不再有"列表消失"。
- 5 次 sidebar today 快速 click（rapid clicks 有可能触发 race）→ list 稳定。
- 6 次 task card click + 关 detail → list 稳定。
- 搜索 `xxx_no_match` → list visible 0 / 标题 2（这是符合预期的 search filter 行为）；清空 search → list visible 立刻回到 2。
- toggleDone(asd) → todoNow 1 + done 1，UI 正确显示 done 区折叠；再 toggle 还原 → 回到 todoNow 2。

### Chrome DevTools MCP — mobile (390×844x3, iPhone UA, touch)
- 模拟真实 touchstart + touchend 序列：
  - **纯 tap**（无位移）：`detail_opened = true`，`todoNow = 2` ✅（修复前 `false`）。
  - **左滑 100px**：`detail_opened = false` + `defaultPrevented = true`，触发 toggleDone（done +1，todoNow -1）✅。
  - **toggle 还原** → todoNow 回到 2 ✅。

### Playwright MCP — 跨浏览器交叉验证
- 5 次 sidebar today click → list 始终 3 条（playwright 容器多了一条 asd）。
- 6 次 task card click → list 始终 3。
- 搜索 `no_match_test` → 标题仍 3 / visible_lis = 0；clear → visible_lis 立刻回 3。

### 后端 smoke
`php deploy/api-smoke.php http://127.0.0.1:8765` → **30/30 PASS**（本次没改 PHP，回归确认）。

## Notes for next agent

- **Today view 现在统一用 `<div x-show=…>` / `<ul x-show=…>` / `<details x-show=…>` + 内嵌 `<template x-for>` pattern**。**不要换回 `<template x-if> > 内嵌 x-for>`** — 这是修出来的 race 根因。其它 view（kanban / notes / stats / trash / account）的 `<template x-for>` 都是直接挂在普通 `<div>` 下，**没有** `<template x-if>` 包它们，本来就 OK，**不要顺手"统一"它们的 empty-state pattern**（那是叶子级 single-element x-if，没 race 隐患）。
- `swipeTask()` 现在**只在判定 swipe action 才 preventDefault**。如果以后想加新 swipe 手势（例如三指、双向），保持这个语义：先判断动作，确认要执行才 `ev.preventDefault()`，否则让浏览器合成 click 通过。
- 触屏 tap 任务卡片现在能正常弹 detail，但**手指有 56px 以上微小左右移动时仍会被判定 swipe**。如果未来收到「我手指明明只是点一下却变成完成/删除」的反馈，可以把阈值从 56px 调到 80–100px，或者引入 `Math.abs(dy)` 检查（垂直滑动占主导时不算 swipe）— 但目前 56px 跟原作者设的一样，先不动。
- **没在我的环境直接复现 Bug A**，是基于 root-cause 推断的防御性修复。如果用户重测后仍然能复现「点击后消失」，下一步要做：(a) 用 `console.log` instrument 给 today view 的 reactive effects 打点，看 reactive 实际触发顺序；(b) 检查用户的浏览器是否带 IndexedDB 的同步异常（pull 拉到的 task 标了 deleted_at）；(c) 检查用户是否在用 `?bust=` query 强制绕过 service worker —— 不绕的话他可能拿的是旧版 dist/app.js（service worker stale-while-revalidate）。
- **dist/app.js 必须重新构建**（`npm run build:js`），因为我改了 `public/js/app.js` 里的 `swipeTask()`。已构建。`/public/dist/` 在 `.gitignore` 里 — 所以 commit 不会带它，部署时要在目标机器跑 `npm run build`。
- **Service worker 缓存**：用户如果之前装过 PWA，service worker 可能 cache 了旧版本。修复发布后用户可能要 `?bust=N` 硬刷一次 + 等 SW 更新一轮才能用上新代码。可以考虑在 `service-worker.js` 把 `VERSION = 'v2-2026-05-06'` 改成 `'v3-2026-05-23'` 强制让所有客户端更新缓存 — 但**这次没改**因为：(a) 用户没说 SW 缓存问题；(b) 改 SW VERSION 会让所有现有用户被强制下线一次（reload 后 SW 重新激活），影响范围大于本次 bug 的影响范围。Trade-off ok。
