<?php
/**
 * Reminder Note - Configuration template.
 * Copy this file to config/config.php. No manual hashing required;
 * accounts are created via the /register page (first user has no special
 * privileges — there is no admin role).
 *
 * jwt_secret = 'auto' (default): a 64-byte random secret is generated on
 * first boot and persisted to data/.jwt_secret. Delete that file to
 * invalidate every existing token.
 *
 * Override with a fixed value (e.g. across multiple servers) if you want.
 *   php -r "echo bin2hex(random_bytes(64));"
 */

return [
    'app_env'    => 'production',
    'app_debug'  => false,
    'app_tz'     => 'Asia/Shanghai',

    'jwt_secret'      => 'auto',
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

    // Per-IP throttle for /auth/login and /auth/register; the same window
    // applies to both, but they're counted independently by `kind`.
    'login_rate_limit'    => 5,
    'login_rate_window'   => 60,
    'register_rate_limit' => 3,

    'cors_origins' => [],
];
