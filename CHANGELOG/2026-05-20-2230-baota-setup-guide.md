# 新增宝塔面板部署手册

- **Date:** 2026-05-20 22:30 (UTC+8)
- **Agent:** Claude Sonnet 4.6（Cursor）
- **User request:** 制作新的一份文档，专门给自己看的，如何在宝塔面板上 setup Linux 服务器。

## What changed

- `deploy/BAOTA_SETUP.md` — 新建。面向宝塔面板的完整中文部署手册，涵盖：PHP 扩展确认、建站步骤、代码部署、依赖安装 & 构建、config.php 配置、目录权限、Nginx 配置（含 PHP-FPM sock 路径）、SSL 申请、首次注册、自动备份、日常更新、常见排错、目录速查。

## Why

README.md 里的生产部署节是 Nginx 命令行流程，没有覆盖宝塔面板的 GUI 操作路径（建站、SSL、计划任务、PHP 扩展管理等）。

## Verification

纯文档，无需运行验证。

## Notes for next agent

- 这份文档是私人参考，不面向公开用户，可以自由修改措辞。
- Nginx 配置里的 PHP sock 路径 `/tmp/php-cgi-83.sock` 是宝塔 PHP 8.3 的默认值；如果服务器用的是 8.2 则改为 `/tmp/php-cgi-82.sock`。实际路径用 `ls /tmp/php-cgi-*.sock` 确认。
- `deploy/deploy.sh` 和 `deploy/backup.sh` 在文档里已被引用，不要改这两个脚本的路径。
