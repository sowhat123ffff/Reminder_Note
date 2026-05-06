# Reminder Note

A personal reminder + notes PWA. Single user. Offline first. Mobile friendly.

- Backend: PHP 8 + SQLite (PDO + WAL)
- Frontend: HTML + Tailwind v3 + Alpine.js + Dexie (IndexedDB)
- PWA: native Service Worker + Web App Manifest
- Auth: hardcoded single user + JWT
- Deploy: XAMPP (dev) / Nginx + PHP-FPM + Let's Encrypt (prod)

## Local development (XAMPP)

1. Place this repo at `C:\xampp3\htdocs\Reminder_Note`.
2. Start Apache + (no MySQL needed; SQLite is file-based).
3. Install PHP deps: `composer install`
4. Install JS deps and build CSS: `npm install && npm run build`
5. Open `http://localhost/Reminder_Note/public/login.html`
6. Default credentials: `jian` / `123456` — change immediately:

```
php deploy/hash.php "your-new-password"
```

…then paste the resulting hash into `config/config.php` (`password_hash`) and rotate `jwt_secret`.

### Standalone PHP dev server (no Apache)

```
php -S 127.0.0.1:8765 dev-router.php
```

Then visit `http://127.0.0.1:8765/login.html`. Run the API smoke test with
`php deploy/api-smoke.php http://127.0.0.1:8765`.

## Production (Linux + Nginx)

```
sudo apt install nginx php8.3-fpm php8.3-sqlite3 php8.3-mbstring composer certbot python3-certbot-nginx
git clone <repo> /var/www/Reminder_Note
cd /var/www/Reminder_Note
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp config/config.example.php config/config.php
php deploy/hash.php "your-password"   # paste into config.php
php -r "echo bin2hex(random_bytes(64));"  # paste into jwt_secret
sudo cp deploy/nginx.conf.example /etc/nginx/sites-available/reminder-note
sudo ln -s /etc/nginx/sites-available/reminder-note /etc/nginx/sites-enabled/
sudo certbot --nginx -d note.nothingaming.com
sudo systemctl reload nginx
```

Backups: `crontab -e` →

```
0 3 * * * /var/www/Reminder_Note/deploy/backup.sh
```

## Default URLs

- `GET  /` → SPA (`public/index.html`)
- `GET  /login.html` → login page
- `POST /api/auth/login` → JWT
- `GET  /api/sync/pull?since=<ms>` → incremental pull
- `POST /api/sync/push` → batch upload local changes

## Folder layout

See [reminder_note_webapp_7ec146ba.plan.md](.cursor/plans/reminder_note_webapp_7ec146ba.plan.md).
