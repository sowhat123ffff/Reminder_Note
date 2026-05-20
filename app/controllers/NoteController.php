<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\HttpException;
use App\Request;
use App\Validator;
use App\Helpers\Uuid;
use PDO;

final class NoteController
{
    public function list(Request $req): array
    {
        $uid = Auth::userId();
        $q = $req->query;
        $where = ['user_id = :uid', 'deleted_at IS NULL'];
        $args  = [':uid' => $uid];
        if (!empty($q['search'])) {
            $where[] = '(title LIKE :search OR content LIKE :search)';
            $args[':search'] = '%' . str_replace(['%','_'], ['\\%','\\_'], (string) $q['search']) . '%';
        }
        if (isset($q['pinned']) && $q['pinned'] === '1') {
            $where[] = 'pinned = 1';
        }
        if (isset($q['favorite']) && $q['favorite'] === '1') {
            $where[] = 'favorite = 1';
        }

        $page  = max(1, (int) ($q['page'] ?? 1));
        $limit = min(200, max(1, (int) ($q['limit'] ?? 50)));
        $offset= ($page - 1) * $limit;

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT * FROM notes {$whereSql} ORDER BY pinned DESC, updated_at DESC LIMIT :limit OFFSET :offset";
        $stmt = Db::pdo()->prepare($sql);
        foreach ($args as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $cstmt = Db::pdo()->prepare("SELECT COUNT(*) c FROM notes {$whereSql}");
        foreach ($args as $k => $v) {
            $cstmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $cstmt->execute();
        $total = (int) $cstmt->fetch()['c'];

        return [
            'notes' => array_map([self::class, 'serialize'], $rows),
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
        ];
    }

    public function show(Request $req, array $params): array
    {
        $row = Db::findByIdForUser('notes', (string) $params['id'], Auth::userId());
        if (!$row) {
            throw new HttpException(404, 'not_found', 'Note not found');
        }
        return ['note' => self::serialize($row)];
    }

    public function create(Request $req): array
    {
        $uid = Auth::userId();
        $data = Validator::check($req->body, [
            'title'    => 'required|string|max:500',
            'content'  => 'nullable|string|max:200000',
            'tags'     => 'nullable|array|max:32',
            'pinned'   => 'nullable|bool',
            'favorite' => 'nullable|bool',
        ]);
        $id  = !empty($req->body['id']) && is_string($req->body['id']) && self::isValidId($req->body['id'])
            ? $req->body['id']
            : Uuid::v4();
        $now = Db::now();

        $row = [
            'id'         => $id,
            'user_id'    => $uid,
            'title'      => (string) $data['title'],
            'content'    => (string) ($data['content'] ?? ''),
            'tags'       => json_encode(array_values(array_filter($data['tags'] ?? [], 'is_string')), JSON_UNESCAPED_UNICODE),
            'pinned'     => !empty($data['pinned']) ? 1 : 0,
            'favorite'   => !empty($data['favorite']) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
        Db::pdo()->prepare('INSERT INTO notes (id, user_id, title, content, tags, pinned, favorite, created_at, updated_at, deleted_at)
            VALUES (:id, :uid, :title, :content, :tags, :pinned, :favorite, :created_at, :updated_at, :deleted_at)')
            ->execute([
                ':id' => $row['id'],
                ':uid' => $row['user_id'],
                ':title' => $row['title'],
                ':content' => $row['content'],
                ':tags' => $row['tags'],
                ':pinned' => $row['pinned'],
                ':favorite' => $row['favorite'],
                ':created_at' => $row['created_at'],
                ':updated_at' => $row['updated_at'],
                ':deleted_at' => $row['deleted_at'],
            ]);
        return ['note' => self::serialize($row)];
    }

    public function update(Request $req, array $params): array
    {
        $uid = Auth::userId();
        $id  = (string) $params['id'];
        $row = Db::findByIdForUser('notes', $id, $uid);
        if (!$row) {
            throw new HttpException(404, 'not_found', 'Note not found');
        }
        $data = Validator::check($req->body, [
            'title'    => 'nullable|string|max:500',
            'content'  => 'nullable|string|max:200000',
            'tags'     => 'nullable|array|max:32',
            'pinned'   => 'nullable|bool',
            'favorite' => 'nullable|bool',
        ]);
        $now = Db::now();
        $set = [];
        $args = [':id' => $id, ':uid' => $uid, ':updated_at' => $now];
        foreach (['title','content'] as $f) {
            if (array_key_exists($f, $data)) {
                $set[] = "{$f} = :{$f}";
                $args[":{$f}"] = $data[$f] === null ? '' : $data[$f];
            }
        }
        if (array_key_exists('tags', $data)) {
            $set[] = 'tags = :tags';
            $args[':tags'] = json_encode(array_values(array_filter($data['tags'] ?? [], 'is_string')), JSON_UNESCAPED_UNICODE);
        }
        foreach (['pinned','favorite'] as $f) {
            if (array_key_exists($f, $data)) {
                $set[] = "{$f} = :{$f}";
                $args[":{$f}"] = $data[$f] ? 1 : 0;
            }
        }
        $set[] = 'updated_at = :updated_at';
        if (count($set) === 1) {
            return ['note' => self::serialize($row)];
        }
        Db::pdo()->prepare('UPDATE notes SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :uid')->execute($args);
        return ['note' => self::serialize(Db::findByIdForUser('notes', $id, $uid))];
    }

    public function destroy(Request $req, array $params): array
    {
        $uid = Auth::userId();
        $id  = (string) $params['id'];
        $row = Db::findByIdForUser('notes', $id, $uid);
        if (!$row) {
            throw new HttpException(404, 'not_found', 'Note not found');
        }
        $now = Db::now();
        Db::pdo()->prepare('UPDATE notes SET deleted_at = :now, updated_at = :now WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $id, ':uid' => $uid, ':now' => $now]);
        return ['ok' => true];
    }

    public function restore(Request $req, array $params): array
    {
        $uid = Auth::userId();
        $id  = (string) $params['id'];
        $row = Db::findByIdForUser('notes', $id, $uid);
        if (!$row) {
            throw new HttpException(404, 'not_found', 'Note not found');
        }
        $now = Db::now();
        Db::pdo()->prepare('UPDATE notes SET deleted_at = NULL, updated_at = :now WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $id, ':uid' => $uid, ':now' => $now]);
        return ['note' => self::serialize(Db::findByIdForUser('notes', $id, $uid))];
    }

    public static function serialize(array $row): array
    {
        $tags = json_decode((string) ($row['tags'] ?? '[]'), true);
        return [
            'id'         => $row['id'],
            'title'      => $row['title'],
            'content'    => $row['content'],
            'tags'       => is_array($tags) ? $tags : [],
            'pinned'     => (int) $row['pinned'] === 1,
            'favorite'   => (int) $row['favorite'] === 1,
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
            'deleted_at' => $row['deleted_at'] !== null ? (int) $row['deleted_at'] : null,
        ];
    }

    private static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id);
    }
}
