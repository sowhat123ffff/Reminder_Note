# Reminder Note

A personal reminder + notes PWA. **Multi-user, open registration.** Offline first. Mobile friendly.

- Backend: PHP 8 + SQLite (PDO + WAL)
- Frontend: HTML + Tailwind v3 + Alpine.js + Dexie (IndexedDB)
- PWA: native Service Worker + Web App Manifest
- Auth: open `/register.html`, JWT (15 min access + 30 day refresh), per-user data isolation
- Deploy: XAMPP (dev) / Nginx + PHP-FPM + Let's Encrypt (prod)

---

## Prerequisites

Before you do anything, make sure these are installed and on your `PATH`:

| Tool        | Version | Check command            |
|-------------|---------|--------------------------|
| PHP         | ≥ 8.1   | `php -v`                 |
| Composer    | any     | `composer --version`     |
| Node.js     | ≥ 18    | `node -v`                |
| npm         | any     | `npm -v`                 |

PHP must have these extensions enabled (uncomment in `php.ini` if needed):
`pdo_sqlite`, `mbstring`, `fileinfo`, `openssl`.

> XAMPP 自带 PHP，但 `composer` / `node` 通常需要单独安装。
> 在 PowerShell 里跑一遍上面四条命令，全部能输出版本号才能继续。

---

## Local development — choose ONE of two ways

### Option A — XAMPP (Apache)

适合你已经在用 XAMPP 的情况。最终访问地址是
`http://localhost/Reminder_Note/public/login.html`。

1. **放到 htdocs**
   仓库目录必须是 `C:\xampp3\htdocs\Reminder_Note`（或你 XAMPP 的 htdocs 路径）。

2. **确认 Apache 启用了 `mod_rewrite`**
   打开 `C:\xampp3\apache\conf\httpd.conf`，确保下面这一行没有 `#`：
   ```
   LoadModule rewrite_module modules/mod_rewrite.so
   ```
   并且 htdocs 那段是 `AllowOverride All`（XAMPP 默认就是）。改完**重启 Apache**。

3. **安装依赖 + 构建前端**
   在项目根目录（PowerShell）：
   ```
   composer install
   npm install
   npm run build
   ```
   `npm run build` 会生成 `public/css/style.css` 和 `public/dist/app.js`，缺一不可。

4. **创建配置文件**（如果 `config/config.php` 还不存在）
   ```
   copy config\config.example.php config\config.php
   ```
   不需要再手动生成密码哈希 / JWT secret。`jwt_secret` 默认是 `'auto'`，
   首次启动时会自动写入 `data/.jwt_secret`（64 字节随机串）。

5. **创建数据库目录**（首次访问 `/api/health` 会自动建 `data/app.db`）
   ```
   mkdir data 2>$null
   ```

6. **启动 Apache**（XAMPP Control Panel → Start Apache），然后访问：
   ```
   http://localhost/Reminder_Note/public/login.html
   ```
   点页面底部的「注册」创建你的第一个账号；之后随时回到 `/login.html`。

#### 看到 500 Internal Server Error？

99% 是 Apache 配置问题，按顺序排查：

- `mod_rewrite` 没开 → 见上面第 2 步。
- `AllowOverride` 不是 `All` → `.htaccess` 被忽略时是 404，不是 All 时是 500。
- 看真正的报错：`C:\xampp3\apache\logs\error.log` 最后几行。
- `.htaccess` 里写了不允许的指令（比如 `<Directory>`）→ 这个仓库已经修好了，如果你之前自己改过请回退。

### Option B — PHP 内置开发服务器（不需要 Apache）

完全不依赖 XAMPP，最快验证后端是否正常。

1. 在项目根目录跑：
   ```
   composer install
   npm install
   npm run build
   copy config\config.example.php config\config.php   # 如果还没有
   ```

2. 启动开发服务器（**这一步必须先跑，否则浏览器会 ERR_CONNECTION_REFUSED**）：
   ```
   php -S 127.0.0.1:8765 dev-router.php
   ```
   保持这个终端**一直开着**。

3. 浏览器访问：
   ```
   http://127.0.0.1:8765/login.html
   ```
   点底部「注册」创建账号。

4. （可选）跑 API 冒烟测试（会自动注册两个临时账号验证多用户隔离）：
   ```
   php deploy\api-smoke.php http://127.0.0.1:8765
   ```

---

## 多用户 / 安全笔记

- **没有「管理员」概念**：注册顺序无关，每个账号都是平级的普通用户，互相完全隔离。
- **注册速率限制**：单 IP 每 60 秒最多 3 次注册；登录失败每 60 秒最多 5 次。
- **密码要求**：≥ 8 位；用户名 3–32 位字母 / 数字 / 下划线。
- **会话管理**：登入后在「账号设置」可以看到所有活跃会话，单独注销 / 一键全部注销 / 改密码。
- **改密码**：会保留当前浏览器，强制其它所有设备重新登录。
- **JWT secret**：默认自动生成存到 `data/.jwt_secret`。**删除该文件 = 让所有现有 token 失效**（所有设备需重新登录）。
- **uploads 隔离**：附件存到 `public/uploads/<uid>/yyyy/mm/<rand>.ext`，仍然靠不可猜测的随机文件名 + `.htaccess` 禁止 PHP 执行。

## 重置数据库

```
del data\app.db data\app.db-wal data\app.db-shm
```
下一个请求会自动用新 schema 重建空库。

---

## Frontend dev (热重载 CSS/JS)

改前端代码时，开第二个终端：
```
npm run dev
```
它会同时 watch Tailwind 和 esbuild，保存即重建。

---

## Production (Linux + Nginx)

```
sudo apt install nginx php8.3-fpm php8.3-sqlite3 php8.3-mbstring composer certbot python3-certbot-nginx
git clone <repo> /var/www/Reminder_Note
cd /var/www/Reminder_Note
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp config/config.example.php config/config.php
sudo cp deploy/nginx.conf.example /etc/nginx/sites-available/reminder-note
sudo ln -s /etc/nginx/sites-available/reminder-note /etc/nginx/sites-enabled/
sudo certbot --nginx -d note.nothingaming.com
sudo systemctl reload nginx
```

第一次访问 `/register.html` 创建账号。`data/.jwt_secret` 会在第一次请求时自动生成。
**部署完成后立刻去注册你的账号** — 否则任何能访问页面的人都能抢走你想要的用户名。

Backups: `crontab -e` →
```
0 3 * * * /var/www/Reminder_Note/deploy/backup.sh
```

---

## Default URLs

- `GET  /` → SPA (`public/index.html`)
- `GET  /login.html` → login page
- `GET  /register.html` → register page
- `POST /api/auth/register` → 公开
- `POST /api/auth/login` → JWT
- `PATCH /api/auth/password` → 改密码（鉴权）
- `GET /api/auth/sessions`, `DELETE /api/auth/sessions/{jti}`, `DELETE /api/auth/sessions` → 会话管理
- `GET /api/auth/login-history` → 最近 30 天登录历史
- `GET  /api/sync/pull?since=<ms>` → incremental pull
- `POST /api/sync/push` → batch upload local changes
- `GET  /api/health` → 健康检查（最快验证后端是否通）

## Folder layout

See [reminder_note_webapp_7ec146ba.plan.md](.cursor/plans/reminder_note_webapp_7ec146ba.plan.md).
