<?php
/**
 * Generate a bcrypt password hash for config/config.php.
 * Usage: php deploy/hash.php "your-password"
 */
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php deploy/hash.php \"your-password\"\n");
    exit(1);
}
$password = (string) $argv[1];
if (strlen($password) < 6) {
    fwrite(STDERR, "Password must be at least 6 characters.\n");
    exit(2);
}
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
echo $hash . PHP_EOL;
