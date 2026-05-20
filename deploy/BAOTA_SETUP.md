# Reminder Note — 宝塔面板部署手册

> **这是给自己看的私人参考文档。**
> 假设你已有一台干净的 Linux 服务器（Ubuntu 22.04 / Debian 12 推荐）并且宝塔面板已经装好。

---

## 一、前置条件检查

登进宝塔面板，确认以下东西都装了：

| 软件 | 版本要求 | 在哪装 |
|------|---------|--------|
| Nginx | 1.22+ | 面板首页 → 推荐安装 |
| PHP | **8.2 或 8.3** | 面板首页 → 推荐安装 |
| Node.js | **≥ 18** | 软件商店 → Node.js版本管理器 |

> SQLite 不需要额外安装数据库软件，PHP 自带 PDO SQLite。

---

## 二、PHP 扩展确认

宝塔面板 → **软件商店** → PHP 管理 → 选 PHP 8.3 → **安装扩展**，确认以下四个全部已安装（打勾）：

- `pdo_sqlite`（或 `sqlite3`）
- `mbstring`
- `fileinfo`
- `openssl`

大多数宝塔环境这些默认已装，检查一下就行。

---

## 三、在宝塔建站

1. 面板左侧 → **网站** → **添加站点**
2. 填写：
   - 域名：`note.你的域名.com`
   - PHP 版本：`PHP-83`（或 8.2）
   - 根目录：留默认 `/www/wwwroot/note.你的域名.com`（之后会改，先建上）
   - **不勾选 MySQL**（用 SQLite，不需要）
3. 点「提交」

---

## 四、部署代码

SSH 进服务器：

```bash
# 进入宝塔网站目录
cd /www/wwwroot

# 删掉宝塔自动创建的空站点目录（里面只有默认 html）
rm -rf note.你的域名.com

# 克隆仓库（目录名要和上面删掉的一致）
git clone https://github.com/你的用户名/Reminder_Note.git note.你的域名.com

cd note.你的域名.com
```

---

## 五、安装依赖 & 构建前端

```bash
# 安装 PHP 依赖（宝塔的 composer 路径）
composer install --no-dev --optimize-autoloader

# 安装 Node 依赖并构建前端资源
npm ci
npm run build
```

> 如果 `composer` 命令找不到，先在宝塔软件商店安装 Composer，或者：
> ```bash
> curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
> ```

构建完成后确认这两个文件存在：
- `public/css/style.css`
- `public/dist/app.js`

---

## 六、创建配置文件

```bash
# 复制模板
cp config/config.example.php config/config.php
```

`config.php` 里 `jwt_secret` 默认是 `'auto'`，**不用改**，第一次请求时会自动生成密钥写到 `data/.jwt_secret`。

如果你想改时区（默认已是 `Asia/Shanghai`），或者调整 JWT 有效期，用编辑器打开 `config/config.php` 改就行。

---

## 七、创建数据目录 & 权限

```bash
# 确保 data 目录存在
mkdir -p data
mkdir -p public/uploads

# 宝塔 Nginx 的运行用户是 www，给它写入权限
chown -R www:www /www/wwwroot/note.你的域名.com
chmod -R 755 /www/wwwroot/note.你的域名.com

# data/ 和 uploads/ 需要 PHP 写入，给 775
chmod 775 data
chmod 775 public/uploads
```

---

## 八、配置 Nginx

### 8.1 修改站点根目录指向 `public/`

宝塔面板 → 网站 → 找到这个站点 → **设置** → **网站目录**

把「运行目录」改为 `/public`（宝塔会自动拼成 `/www/wwwroot/note.你的域名.com/public`）。

### 8.2 替换 Nginx 配置

宝塔面板 → 网站 → 设置 → **配置文件**，把整个内容替换成下面这段（记得把域名和 PHP 版本改对）：

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name note.你的域名.com;
    root /www/wwwroot/note.你的域名.com/public;
    index index.html;

    # 安全头
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header Referrer-Policy same-origin;

    # 上传大小限制（和 config.php 里的 upload_max 保持一致）
    client_max_body_size 25m;

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;

    # API 路由 → PHP-FPM
    location ^~ /api/ {
        try_files $uri /api/index.php?$query_string;
    }

    location = /api/index.php {
        include fastcgi_params;
        # 宝塔 PHP 8.3 的 sock 路径（如果是 8.2 改成 php-cgi-82.sock）
        fastcgi_pass unix:/tmp/php-cgi-83.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/api/index.php;
        fastcgi_param DOCUMENT_ROOT   $document_root;
    }

    # 用户上传文件（禁止执行 PHP）
    location ^~ /uploads/ {
        location ~ \.php$ { deny all; }
    }

    # 静态资源缓存
    location ~* \.(?:css|js|mjs|woff2|png|jpg|webp|svg|ico|webmanifest)$ {
        expires 7d;
        access_log off;
    }

    # SPA fallback
    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

> **PHP sock 路径找法**：SSH 里跑 `ls /tmp/php-cgi-*.sock`，看实际文件名。
> 宝塔常见格式：`/tmp/php-cgi-83.sock`（PHP 8.3）或 `/tmp/php-cgi-82.sock`（PHP 8.2）。

保存后点「保存」，宝塔会自动 reload Nginx。

---

## 九、申请 SSL 证书

宝塔 → 网站 → 设置 → **SSL** → Let's Encrypt → 勾选域名 → **申请**

申请成功后宝塔自动在 Nginx 配置里加上 443 和证书路径，并且开启强制 HTTPS。

> 申请前确保域名已解析到服务器 IP（DNS 生效需要几分钟到几小时）。

---

## 十、首次访问 & 验证

1. 打开 `https://note.你的域名.com/api/health`，应该返回：
   ```json
   {"ok":true,"time":1234567890123}
   ```
   如果是 200 说明 PHP-FPM 通了。

2. 打开 `https://note.你的域名.com/`，应该跳到登录页。

3. 点底部「注册」，创建你的第一个账号。

4. **部署完立刻注册**，否则任何人都能抢先注册用户名。

---

## 十一、设置自动备份

### 方法 A：宝塔计划任务（推荐）

宝塔面板 → **计划任务** → 添加任务：

- 任务类型：Shell 脚本
- 任务名称：Reminder Note 备份
- 执行周期：每天 03:00
- 脚本内容：
  ```bash
  bash /www/wwwroot/note.你的域名.com/deploy/backup.sh
  ```

备份文件存到服务器的 `../reminder-note-backups/`（相对于项目根目录），保留 14 天。

### 方法 B：crontab

```bash
crontab -e
```
加入：
```
0 3 * * * bash /www/wwwroot/note.你的域名.com/deploy/backup.sh >> /var/log/reminder-note-backup.log 2>&1
```

---

## 十二、日常更新代码

```bash
cd /www/wwwroot/note.你的域名.com
bash deploy/deploy.sh
```

`deploy.sh` 会自动：`git pull` → `composer install` → `npm ci && npm run build` → reload PHP-FPM + Nginx。

---

## 十三、常见排错

### `502 Bad Gateway`
- PHP-FPM 没启动，或 sock 路径写错。
- 检查：`ps aux | grep php-fpm`
- 宝塔面板 → PHP 管理 → 重启 PHP 8.3

### `500 Internal Server Error`
- 权限问题，跑一遍第七步的 `chown/chmod`。
- 或者查 Nginx 错误日志：`tail -100 /www/wwwlogs/note.你的域名.com.error.log`

### 前端空白 / JS 404
- `public/dist/app.js` 或 `public/css/style.css` 没构建，重跑 `npm run build`。
- 或者 Nginx 根目录没有指向 `public/`，重新检查第 8.1 步。

### API 全部返回 404（`/api/health` 也是）
- Nginx 里 `/api/` 的路由没配好，重新检查第 8.2 步的配置。
- 确认 `fastcgi_pass` 里的 sock 路径和实际文件匹配。

### `data/app.db` 权限拒绝
```bash
chown www:www /www/wwwroot/note.你的域名.com/data
chmod 775 /www/wwwroot/note.你的域名.com/data
```

### 忘记密码 / 想清空所有账号
```bash
rm /www/wwwroot/note.你的域名.com/data/app.db
rm /www/wwwroot/note.你的域名.com/data/app.db-wal  2>/dev/null
rm /www/wwwroot/note.你的域名.com/data/app.db-shm  2>/dev/null
```
下次请求自动重建空库，所有账号和数据清空。

### 让所有设备强制重新登录
```bash
rm /www/wwwroot/note.你的域名.com/data/.jwt_secret
```
下次请求自动生成新 secret，所有现有 JWT token 失效。

---

## 十四、目录结构速查

```
/www/wwwroot/note.你的域名.com/
├── app/                  PHP 后端源码
├── config/
│   ├── config.example.php
│   └── config.php        ← 你的配置（gitignore，不提交）
├── data/
│   ├── app.db            ← SQLite 数据库（gitignore）
│   └── .jwt_secret       ← JWT 密钥（gitignore，自动生成）
├── database/schema.sql
├── deploy/
│   ├── backup.sh
│   ├── deploy.sh
│   └── BAOTA_SETUP.md    ← 本文件
├── public/               ← Nginx root 指向这里
│   ├── api/index.php     ← API 入口
│   ├── css/style.css     ← 构建产物
│   ├── dist/app.js       ← 构建产物
│   ├── index.html
│   ├── login.html
│   ├── register.html
│   └── uploads/          ← 用户上传文件（gitignore）
└── vendor/               ← Composer 依赖（gitignore）
```

---

## 十五、重要文件 & 操作速查

| 想做什么 | 命令 / 位置 |
|---------|-----------|
| 更新代码 | `bash deploy/deploy.sh` |
| 手动备份 | `bash deploy/backup.sh` |
| 查 Nginx 错误日志 | `/www/wwwlogs/note.域名.error.log` |
| 查 PHP 错误日志 | `/www/server/php/83/var/log/php-fpm.log` |
| 重启 PHP-FPM | 宝塔面板 PHP 管理 → 重启，或 `systemctl restart php83-fpm` |
| 重载 Nginx | `nginx -s reload` 或宝塔面板操作 |
| 清空数据库（危险）| `rm data/app.db data/app.db-wal data/app.db-shm` |
| 轮换 JWT secret | `rm data/.jwt_secret` |
