<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Db;
use App\HttpException;
use App\Request;
use App\Helpers\Uuid;

final class UploadController
{
    public function create(Request $req): array
    {
        $uid = Auth::userId();

        if (empty($_FILES['file']) || !is_array($_FILES['file']) || !is_uploaded_file((string) $_FILES['file']['tmp_name'])) {
            throw new HttpException(400, 'no_file', 'No file uploaded under field "file"');
        }

        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new HttpException(400, 'upload_error', 'Upload failed with error code ' . (int) $file['error']);
        }

        $max = (int) Config::get('upload_max', 20 * 1024 * 1024);
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $max) {
            throw new HttpException(413, 'file_too_large', "File too large (max {$max} bytes)");
        }

        $tmp  = (string) $file['tmp_name'];
        $name = (string) ($file['name'] ?? 'file');
        $name = preg_replace('/[\\\\\\/\\:\\*\\?"<>\\|\\x00-\\x1f]+/u', '_', $name) ?: 'file';
        $name = mb_substr($name, 0, 200);

        $mime = (string) ($file['type'] ?? '');
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($tmp);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }

        $whitelist = (array) Config::get('upload_mime_whitelist', []);
        if (!empty($whitelist) && !in_array($mime, $whitelist, true)) {
            throw new HttpException(415, 'mime_not_allowed', "Mime type not allowed: {$mime}");
        }

        $dir = (string) Config::get('uploads_dir');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException(500, 'upload_dir_error', 'Cannot create uploads dir');
        }

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $safeBase = bin2hex(random_bytes(8));
        $filename = $safeBase . ($ext ? '.' . preg_replace('/[^a-z0-9]+/', '', $ext) : '');

        $year  = date('Y');
        $month = date('m');
        // user-scoped subdir: /uploads/<uid>/yyyy/mm/<rand>.ext
        $sub = "{$dir}/{$uid}/{$year}/{$month}";
        if (!is_dir($sub) && !mkdir($sub, 0775, true) && !is_dir($sub)) {
            throw new HttpException(500, 'upload_dir_error', 'Cannot create uploads subdir');
        }

        $dest = "{$sub}/{$filename}";
        if (!@move_uploaded_file($tmp, $dest)) {
            throw new HttpException(500, 'move_failed', 'Failed to store uploaded file');
        }

        $id  = Uuid::v4();
        $now = Db::now();
        $relPath = "{$uid}/{$year}/{$month}/{$filename}";

        Db::pdo()->prepare('INSERT INTO attachments (id, user_id, ref_type, ref_id, name, mime, size, path, created_at) VALUES (:id, :uid, :rt, :ri, :n, :m, :s, :p, :c)')
            ->execute([
                ':id' => $id,
                ':uid' => $uid,
                ':rt' => (string) ($req->body['ref_type'] ?? 'task'),
                ':ri' => (string) ($req->body['ref_id'] ?? ''),
                ':n'  => $name,
                ':m'  => $mime,
                ':s'  => $size,
                ':p'  => $relPath,
                ':c'  => $now,
            ]);

        return [
            'attachment' => [
                'id'   => $id,
                'name' => $name,
                'mime' => $mime,
                'size' => $size,
                'url'  => rtrim((string) Config::get('uploads_url', '/uploads'), '/') . '/' . $relPath,
                'created_at' => $now,
            ],
        ];
    }
}
