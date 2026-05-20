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

final class TaskController
{
    private const STATUSES = ['todo', 'doing', 'done', 'archived'];

    public function list(Request $req): array
    {
        $uid = Auth::userId();
        $q = $req->query;

        $where = ['user_id = :uid', 'deleted_at IS NULL'];
        $args  = [':uid' => $uid];

        if (!empty($q['status']) && in_array((string) $q['status'], self::STATUSES, true)) {
            $where[] = 'status = :status';
            $args[':status'] = (string) $q['status'];
        }

        if (isset($q['include_deleted']) && $q['include_deleted'] === '1') {
            // Drop the deleted_at filter (keep user_id at index 0).
            $where = array_values(array_filter($where, fn($c) => $c !== 'deleted_at IS NULL'));
        }

        if (!empty($q['search'])) {
            $where[] = '(title LIKE :search OR notes LIKE :search)';
            $args[':search'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $q['search']) . '%';
        }

        if (!empty($q['due_before'])) {
            $where[] = 'due_at IS NOT NULL AND due_at <= :due_before';
            $args[':due_before'] = (int) $q['due_before'];
        }
        if (!empty($q['due_after'])) {
            $where[] = 'due_at IS NOT NULL AND due_at >= :due_after';
            $args[':due_after'] = (int) $q['due_after'];
        }

        if (!empty($q['priority_min'])) {
            $where[] = 'priority >= :pmin';
            $args[':pmin'] = (int) $q['priority_min'];
        }

        $page  = max(1, (int) ($q['page'] ?? 1));
        $limit = min(200, max(1, (int) ($q['limit'] ?? 50)));
        $offset= ($page - 1) * $limit;

        $orderField = 'updated_at';
        if (!empty($q['order_by']) && in_array($q['order_by'], ['due_at', 'created_at', 'priority', 'updated_at'], true)) {
            $orderField = $q['order_by'];
        }
        $orderDir = (!empty($q['order']) && strtolower((string) $q['order']) === 'asc') ? 'ASC' : 'DESC';

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $sql = "SELECT * FROM tasks {$whereSql} ORDER BY {$orderField} {$orderDir} LIMIT :limit OFFSET :offset";
        $stmt = Db::pdo()->prepare($sql);
        foreach ($args as $k => $v) {
            $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $countSql = "SELECT COUNT(*) AS c FROM tasks {$whereSql}";
        $cstmt = Db::pdo()->prepare($countSql);
        foreach ($args as $k => $v) {
            $cstmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $cstmt->execute();
        $total = (int) $cstmt->fetch()['c'];

        return [
            'tasks' => array_map([self::class, 'serialize'], $rows),
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
        ];
    }

    public function show(Request $req, array $params): array
    {
        $row = Db::findByIdForUser('tasks', (string) $params['id'], Auth::userId());
        if (!$row) {
            throw new HttpException(404, 'not_found', 'Task not found');
        }
        return ['task' => self::serialize($row)];
    }

    public function create(Request $req): array
    {
        $uid = Auth::userId();
        $data = Validator::check($req->body, [
            'title'        => 'required|string|max:500',
            'notes'        => 'nullable|string|max:50000',
            'status'       => 'nullable|string|in:todo,doing,done,archived',
            'priority'     => 'nullable|int|min:0|max:3',
            'due_at'       => 'nullable|int',
            'remind_at'    => 'nullable|int',
            'repeat_rule'  => 'nullable|string|max:1000',
            'tags'         => 'nullable|array|max:32',
            'subtasks'     => 'nullable|array|max:200',
        ]);

        $now = Db::now();
        $id  = !empty($req->body['id']) && is_string($req->body['id']) && self::isValidId($req->body['id'])
            ? $req->body['id']
            : Uuid::v4();

        $row = [
            'id'           => $id,
            'user_id'      => $uid,
            'title'        => (string) $data['title'],
            'notes'        => (string) ($data['notes'] ?? ''),
            'status'       => (string) ($data['status'] ?? 'todo'),
            'priority'     => (int) ($data['priority'] ?? 1),
            'due_at'       => $data['due_at'] ?? null,
            'remind_at'    => $data['remind_at'] ?? null,
            'repeat_rule'  => $data['repeat_rule'] ?? null,
            'tags'         => json_encode(array_values(array_filter($data['tags'] ?? [], 'is_string')), JSON_UNESCAPED_UNICODE),
            'subtasks'     => json_encode(self::cleanSubtasks($data['subtasks'] ?? []), JSON_UNESCAPED_UNICODE),
            'created_at'   => $now,
            'updated_at'   => $now,
            'completed_at' => ($data['status'] ?? 'todo') === 'done' ? $now : null,
            'deleted_at'   => null,
        ];

        $sql = 'INSERT INTO tasks (id, user_id, title, notes, status, priority, due_at, remind_at, repeat_rule, tags, subtasks, created_at, updated_at, completed_at, deleted_at)
                VALUES (:id, :uid, :title, :notes, :status, :priority, :due_at, :remind_at, :repeat_rule, :tags, :subtasks, :created_at, :updated_at, :completed_at, :deleted_at)';
        Db::pdo()->prepare($sql)->execute([
            ':id'          => $row['id'],
            ':uid'         => $row['user_id'],
            ':title'       => $row['title'],
            ':notes'       => $row['notes'],
            ':status'      => $row['status'],
            ':priority'    => $row['priority'],
            ':due_at'      => $row['due_at'],
            ':remind_at'   => $row['remind_at'],
            ':repeat_rule' => $row['repeat_rule'],
            ':tags'        => $row['tags'],
            ':subtasks'    => $row['subtasks'],
            ':created_at'  => $row['created_at'],
            ':updated_at'  => $row['updated_at'],
            ':completed_at'=> $row['completed_at'],
            ':deleted_at'  => $row['deleted_at'],
        ]);

        return ['task' => self::serialize($row)];
    }

    public function update(Request $req, array $params): array
    {
        $uid = Auth::userId();
        $id  = (string) $params['id'];
        $row = Db::findByIdForUser('tasks', $id, $uid);
        if (!$row) {
            throw new HttpException(404, 'not_found', 'Task not found');
        }

        $data = Validator::check($req->body, [
            'title'       => 'nullable|string|max:500',
            'notes'       => 'nullable|string|max:50000',
            'status'      => 'nullable|string|in:todo,doing,done,archived',
            'priority'    => 'nullable|int|min:0|max:3',
            'due_at'      => 'nullable|int',
            'remind_at'   => 'nullable|int',
            'repeat_rule' => 'nullable|string|max:1000',
            'tags'        => 'nullable|array|max:32',
            'subtasks'    => 'nullable|array|max:200',
        ]);

        $now = Db::now();
        $set = [];
        $args = [':id' => $id, ':uid' => $uid, ':updated_at' => $now];

        $notNullDefault = [
            'title'  => '',
            'notes'  => '',
            'status' => 'todo',
            'priority' => 1,
        ];
        foreach (['title','notes','status','priority','due_at','remind_at','repeat_rule'] as $f) {
            if (array_key_exists($f, $data)) {
                $set[] = "{$f} = :{$f}";
                $args[":{$f}"] = ($data[$f] === null && array_key_exists($f, $notNullDefault))
                    ? $notNullDefault[$f]
                    : $data[$f];
            }
        }
        if (array_key_exists('tags', $data)) {
            $set[] = 'tags = :tags';
            $args[':tags'] = json_encode(array_values(array_filter($data['tags'] ?? [], 'is_string')), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('subtasks', $data)) {
            $set[] = 'subtasks = :subtasks';
            $args[':subtasks'] = json_encode(self::cleanSubtasks($data['subtasks'] ?? []), JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists('status', $data)) {
            if ($data['status'] === 'done' && !$row['completed_at']) {
                $set[] = 'completed_at = :completed_at';
                $args[':completed_at'] = $now;
            } elseif ($data['status'] !== 'done' && $row['completed_at']) {
                $set[] = 'completed_at = NULL';
            }
        }

        $set[] = 'updated_at = :updated_at';

        if (count($set) === 1) {
            return ['task' => self::serialize($row)];
        }

        $sql = 'UPDATE tasks SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :uid';
        Db::pdo()->prepare($sql)->execute($args);

        return ['task' => self::serialize(Db::findByIdForUser('tasks', $id, $uid))];
    }

    public function destroy(Request $req, array $params): array
    {
        $uid = Auth::userId();
        $id  = (string) $params['id'];
        $row = Db::findByIdForUser('tasks', $id, $uid);
        if (!$row) {
            throw new HttpException(404, 'not_found', 'Task not found');
        }
        $now = Db::now();
        Db::pdo()->prepare('UPDATE tasks SET deleted_at = :now, updated_at = :now WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $id, ':uid' => $uid, ':now' => $now]);
        return ['ok' => true];
    }

    public function restore(Request $req, array $params): array
    {
        $uid = Auth::userId();
        $id  = (string) $params['id'];
        $row = Db::findByIdForUser('tasks', $id, $uid);
        if (!$row) {
            throw new HttpException(404, 'not_found', 'Task not found');
        }
        $now = Db::now();
        Db::pdo()->prepare('UPDATE tasks SET deleted_at = NULL, updated_at = :now WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $id, ':uid' => $uid, ':now' => $now]);
        return ['task' => self::serialize(Db::findByIdForUser('tasks', $id, $uid))];
    }

    public static function serialize(array $row): array
    {
        return [
            'id'           => $row['id'],
            'title'        => $row['title'],
            'notes'        => $row['notes'],
            'status'       => $row['status'],
            'priority'     => (int) $row['priority'],
            'due_at'       => $row['due_at'] !== null ? (int) $row['due_at'] : null,
            'remind_at'    => $row['remind_at'] !== null ? (int) $row['remind_at'] : null,
            'repeat_rule'  => $row['repeat_rule'],
            'tags'         => self::decodeJsonArray($row['tags'] ?? '[]'),
            'subtasks'     => self::decodeJsonArray($row['subtasks'] ?? '[]'),
            'created_at'   => (int) $row['created_at'],
            'updated_at'   => (int) $row['updated_at'],
            'completed_at' => $row['completed_at'] !== null ? (int) $row['completed_at'] : null,
            'deleted_at'   => $row['deleted_at'] !== null ? (int) $row['deleted_at'] : null,
        ];
    }

    private static function decodeJsonArray(string $json): array
    {
        $v = json_decode($json, true);
        return is_array($v) ? $v : [];
    }

    private static function cleanSubtasks(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = (string) ($item['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $out[] = [
                'text' => mb_substr($text, 0, 500),
                'done' => (bool) ($item['done'] ?? false),
                'id'   => isset($item['id']) && is_string($item['id']) ? $item['id'] : Uuid::v4(),
            ];
        }
        return $out;
    }

    private static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id);
    }
}
