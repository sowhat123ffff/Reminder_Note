# 备份导入跨账号 + sync push 拒绝处理 两个静默数据丢失 bug

- **Date:** 2026-05-10 18:20 (UTC+8)
- **Agent:** Claude Opus 4.7（Cursor）
- **User request:** 收摊前补完 plan 之外的两项验收：跨用户备份 import 行为、平板视口（≥sm <md, 640–767px）布局。

## What changed

- `public/js/sync.js` — `pushDirty()` 修复 **silent-rejection ghost data** bug：之前对所有 push 上去的记录都调 `clearDirty`，不区分服务端 `applied` 还是 `rejected`，导致任何被服务端拒绝的记录（`id_conflict` / `missing_title` / `invalid_id` / `missing_id`）都会被错误标记为 clean，UI 显示「已同步」但服务端没数据。修法：从待清 dirty 列表里减去 rejected ids，让被拒绝的记录保持 dirty=1，下次 sync 重试。
- `public/js/app.js` — `importData()` 改为给每条记录重新分配 id（`crypto`-based UUID 来自 `db-local.uuid`）。原 id 是 backup 来源账号的 server-side 主键；导入到不同账号时复用原 id 必然撞 server `id_conflict`，留下「客户端看见但服务端拒收」的孤儿数据。重新分配 id 把 import 当成「这些数据是新加进当前账号」，语义干净，跨账号也跨设备。
- `public/js/app.js` — import 顶部 `import { Tasks, Notes, uuid as newId } from './db-local.js';` 加了 `uuid` 引入。
- `public/dist/app.js` — `npm run build:js` 更新构建产物。

## Why

跨用户 import 验证里复现了一条静默数据丢失链：alice 在 A 账号下导出备份 → bob 登入 → import 同一文件 → UI flash「导入完成 1 任务 + 1 笔记」+ 列表里也显示 → 但 `/api/sync/push` 返回 `rejected: [{type:'task', reason:'id_conflict'}, {type:'note', reason:'id_conflict'}]`，服务端 bob 账号下其实是 0/0。客户端把 dirty 清掉后这条更难修复（再触发 sync 也不会重推），bob 一旦在另一台设备登入就完全看不到这两条「自己的」记录。

调查中发现 `pushDirty` 的 dirty 清除逻辑本来就有更普遍的 bug —— 不只 import 场景，单用户单纯写一条 title 空的 task 也会被服务端拒（schema 拒绝），但客户端 dirty=0、UI 没提示，下次刷新拉数据就丢了。这条独立修。

## Verification

跑过两条独立 e2e 用例确认两个 bug 各自被修：

1. **import 跨账号** — alice 注册、建 1 task + 1 note、自动 sync 到 server；登出、bob 注册、`evaluate_script` 模拟 file input 触发 `importData(file)`；UI 弹新文案「将导入到当前账号 …每条记录会以新 id 加入」，点确认；之后查：
   - `GET /api/tasks` (bob token) → 1 条，**id 是新生成的 UUID**（不是 alice 的 8d66b681…）。
   - `GET /api/notes` (bob token) → 1 条，新 id。
   - `GET /api/tasks` (alice token) → 还是 1 条，**id 完全没变**（alice 数据没被污染）。
   - bob 的 IndexedDB 内两条记录 `dirty=0`，跟 server id 一致 — 没有 ghost。
2. **`pushDirty` 不再误清 reject 的 dirty** — `evaluate_script` 直接往 IndexedDB 塞一条 `title=''` 的 task `dirty=1`，触发 `rn:sync-now`，等同步完成；重读：那条仍然 `found=true`、`dirty=1`、`title=''`。修复前 dirty 会变 0。

平板视口（plan 之外补的另一项）：

- **700×900（mobile,touch UA: iPad）** —`emulate` + 拍图：sidebar 隐藏（< md=768）、底部 tab bar 显示、按钮显示长文「注销所有其它设备」（≥ sm=640）。账号设置三卡 + 三条 session + 登录历史 全部不 wrap、不 overflow。
- **640×900（sm 边界）** — `evaluate_script` 取 `getComputedStyle`：`hidden sm:inline` → `display: none`（短文「全部注销」隐藏），`sm:hidden` → `display: block`（长文「注销所有其它设备」显示）。
- **639×900（差 1px 跌出 sm）** — 同样 `getComputedStyle` 验证：long span `display: none`、short span `display: block`，按钮文本切到「全部注销」。
- **笔记 view 700×900** — 拍图：filter 标签 + 「新建笔记」按钮一行容纳，note 卡片整齐。

后端回归：`php deploy/api-smoke.php http://127.0.0.1:9877` → **30/30 PASS**。

## Notes for next agent

- `pushDirty` 的 reject 处理改了语义：之前**所有** push 过的都清 dirty，现在只清 server **applied** 的。这意味着任何被 server 拒的记录会**永远** dirty=1 直到本地修改或删除 —— 比起之前的「假同步丢数据」要好得多，但 UI 上目前没把 rejected 数量回显给用户，只能开 DevTools network 看响应。如果以后想做更友好的提示，往 `appShell.runSync()` 的回调里加。
- `importData` 现在**不再尊重备份文件里的 id**。后果是：同一账号反复 import 同一份备份会出现重复条目（每次新分配 id）。这是显式 trade-off — 备份的语义被定为「归档当前数据，可以贴回任何账号」，不是「跨设备真同步」（真同步靠 server 端）。如果用户真要在「同账号恢复」场景用 backup，应该先在「回收站 / 清单」里删干净，再 import。
- 如果将来要支持「同账号智能合并」，需要在 backup JSON 里记录 owner uid（client 端 IndexedDB 没存 user_id），然后 import 时拿 `api.me()` 的 uid 比对。这条没做。
- 这次没改任何 PHP / API 代码，所以 30/30 smoke 是「无回归」的间接确认；前端逻辑修复靠 MCP DevTools 直接查 IndexedDB + 服务端 token 多账号 cross-check。
- backup 测试用的 `data/_backup_alice.json` 已经删除；这文件不该 commit 进 git。
