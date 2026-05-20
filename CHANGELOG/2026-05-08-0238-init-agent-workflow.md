# 初始化 Agent 工作流（规则 + memory + changelog 协议）

- **Date:** 2026-05-08 02:38 (UTC+8)
- **Agent:** Claude Opus 4.7（Cursor）
- **User request:** 给本工作目录做一套规则，严格遵守 `.cursor/skills/karpathy-guidelines`；每次修改要更新 CLAUDE.md 之类的 memory 文件，并按日期写一份 CHANGELOG，让下一个 Agent 能读取上一个 Agent 改了什么，避免越改越烂。

## What changed
- `.cursor/rules/project-workflow.mdc` — 新建。`alwaysApply: true` 的工作流规则，强制：动手前读 memory、严格 Karpathy、改后更新 `AGENTS.md`、每次改动写 CHANGELOG、append-only。
- `AGENTS.md` — 新建。仓库根目录的项目 memory 文件，Cursor 会自动加载，包含 Project at a glance / Current state / Last change / Known pitfalls / Conventions。
- `CHANGELOG/README.md` — 新建。说明 changelog 目录的命名规则和 append-only 契约。
- `CHANGELOG/2026-05-08-0238-init-agent-workflow.md` — 即本文件。首条记录。

## Why
用户要求每个 Agent 在干活前能读到"上一个 Agent 改了什么"的 memory，干完后留下可追溯的改动记录，避免迭代式 AI 编辑互相覆盖、回滚、降级。同时把 Karpathy 行为准则提升为本目录的硬约束。

## Verification
1. `Get-ChildItem .cursor\rules` 应能看到 `project-workflow.mdc`。
2. `Get-ChildItem CHANGELOG` 应能看到 `README.md` 和本文件。
3. `AGENTS.md` 在仓库根目录，Cursor 新开会话时会自动加载。
4. 开新一轮 Cursor 对话，让 Agent 做任意改动；正确的 Agent 应该会先读 `AGENTS.md` 和 `CHANGELOG/` 最新条目，干完再补一份 changelog 并更新 `AGENTS.md`。

## Notes for next agent
- **命名决定：** memory 文件取名 `AGENTS.md`（不是 `CLAUDE.md`），因为 Cursor 会原生自动加载 `AGENTS.md`。如果改名为 `CLAUDE.md`，"下一个 AI 自动具备 memory" 这件事会失效，必须靠规则才能强制读。如果用户想要不同的命名，请保留功能等价的自动加载行为。
- 规则文件是 `alwaysApply: true`。如果某次会话用户明确说"这次跳过 changelog"，可以豁免，但**不要**默认豁免。
- `CHANGELOG/` 是 **append-only**。不要合并、不要改写、不要删旧条目。
- 不要回头去"美化"或重排 `AGENTS.md` 的旧内容；只改 `Current state` / `Last change` / `Known pitfalls` 三段就够了。
- 这次改动**没有**碰任何 PHP / JS / 配置文件，只新增了 4 个 markdown / mdc 文件。如果你看到这之后的提交里有不相关的代码改动被归到这条 changelog，那是别的 Agent 越界了。
