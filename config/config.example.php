<?php
/**
 * Reminder Note - Configuration template.
 * Copy this file to config/config.php and fill the secrets.
 *
 * Generate password hash:
 *   php deploy/hash.php "your-password"
 *
 * Generate JWT secret (Linux):
 *   openssl rand -hex 64
 * Or PHP:
 *   php -r "echo bin2hex(random_bytes(64));"
 */

return [
    'app_env'    => 'production',
    'app_debug'  => false,
    'app_tz'     => 'Asia/Shanghai',

    'username'      => 'jian',
    'password_hash' => '$2y$12$REPLACE_ME_WITH_REAL_HASH',

    'jwt_secret'      => 'REPLACE_ME_WITH_64_BYTE_HEX',
    'jwt_access_ttl'  => 900,
    'jwt_refresh_ttl' => 2592000,
    'jwt_issuer'      => 'reminder-note',

    'db_path'      => __DIR__ . '/../data/app.db',
    'uploads_dir'  => __DIR__ . '/../public/uploads',
    'uploads_url'  => '/uploads',
    'upload_max'   => 20 * 1024 * 1024,
    'upload_mime_whitelist' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'application/pdf',
        'text/plain', 'text/markdown',
        'application/zip',
    ],

    'login_rate_limit'  => 5,
    'login_rate_window' => 60,

    'cors_origins' => [],
];
