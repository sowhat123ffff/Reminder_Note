# CHANGELOG

每一次 Agent 驱动的改动 = 一份 markdown 文件。

- **文件名：** `YYYY-MM-DD-HHMM-<short-slug>.md`（UTC+8，slug 用 kebab-case）。
- **排序：** 文件名按字母倒排即时间倒排，最新的在最上面。
- **Append-only：** 历史条目不准删、不准改。要撤销旧改动就新写一条说明原因。
- **模板：** 见 `.cursor/rules/project-workflow.mdc` 第 3 节。

这个目录的存在意义是：让下一个 Agent 拿到完整的改动 trail，避免 AI 一次次迭代把代码越改越烂。

跟 `AGENTS.md` 的分工：
- `AGENTS.md` = **当前快照**（现在什么状态、最近改了什么、有什么坑）。
- `CHANGELOG/` = **历史全量**（每一次改动的完整记录）。
