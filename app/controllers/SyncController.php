<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\HttpException;
use App\Request;
use PDO;

/**
 * Incremental sync between client (IndexedDB) and server (SQLite).
 *
 * Pull: client sends `since` timestamp; server returns every record whose
 *       updated_at > since (including soft-deleted ones, marked by deleted_at).
 *
 * Push: client sends a batch of locally-modified records (tasks, notes).
 *       Server applies last-write-wins per record, comparing updated_at;
 *       returns final authoritative versions and the new server timestamp.
 */
final class SyncController
{
    public function pull(Request $req): array
    {
        $since = (int) ($req->query['since'] ?? 0);
        $limit = min(2000, max(1, (int) ($req->query['limit'] ?? 1000)));

        $tasks = $this->fetchSince('tasks', $since, $limit);
        $notes = $this->fetchSince('notes', $since, $limit);

        return [
            'tasks'      => array_map([TaskController::class, 'serialize'], $tasks),
            'notes'      => array_map([NoteController::class, 'serialize'], $notes),
            'serverTime' => Db::now(),
            'hasMore'    => count($tasks) === $limit || count($notes) === $limit,
        ];
    }

    public function push(Request $req): array
    {
        $tasks = is_array($req->body['tasks'] ?? null) ? $req->body['tasks'] : [];
        $notes = is_array($req->body['notes'] ?? null) ? $req->body['notes'] : [];

        $appliedTasks = [];
        $appliedNotes = [];
        $rejected     = [];

        Db::transaction(function () use ($tasks, $notes, &$appliedTasks, &$appliedNotes, &$rejected) {
            foreach ($tasks as $t) {
                $res = $this->upsertTask($t);
                if ($res['ok']) {
                    $appliedTasks[] = $res['row'];
                } else {
                    $rejected[] = ['type' => 'task', 'id' => $t['id'] ?? null, 'reason' => $res['reason']];
                }
            }
            foreach ($notes as $n) {
                $res = $this->upsertNote($n);
                if ($res['ok']) {
                    $appliedNotes[] = $res['row'];
                } else {
                    $rejected[] = ['type' => 'note', 'id' => $n['id'] ?? null, 'reason' => $res['reason']];
                }
            }
        });

        return [
            'appliedTasks' => $appliedTasks,
            'appliedNotes' => $appliedNotes,
            'rejected'     => $rejected,
            'serverTime'   => Db::now(),
        ];
    }

    private function fetchSince(string $table, int $since, int $limit): array
    {
        $sql  = "SELECT * FROM {$table} WHERE updated_at > :since ORDER BY updated_at ASC LIMIT :limit";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->bindValue(':since', $since, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function upsertTask(array $t): array
    {
        if (empty($t['id']) || !is_string($t['id'])) {
            return ['ok' => false, 'reason' => 'missing_id'];
        }
        $id = (string) $t['id'];
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id)) {
            return ['ok' => false, 'reason' => 'invalid_id'];
        }
        $title = (string) ($t['title'] ?? '');
        if ($title === '' && empty($t['deleted_at'])) {
            return ['ok' => false, 'reason' => 'missing_title'];
        }

        $now = Db::now();
        $clientUpdated = (int) ($t['updated_at'] ?? $now);

        $existing = Db::findById('tasks', $id);
        if ($existing && (int) $existing['updated_at'] >= $clientUpdated) {
            return ['ok' => true, 'row' => TaskController::serialize($existing)];
        }

        $clientStatus = $t['status'] ?? 'todo';
        $repeatRuleJson = self::normalizeRepeatRule($t['repeat_rule'] ?? null);
        $row = [
            'id'           => $id,
            'title'        => mb_substr($title, 0, 500),
            'notes'        => mb_substr((string) ($t['notes'] ?? ''), 0, 50000),
            'status'       => in_array($clientStatus, ['todo','doing','done','archived'], true) ? $clientStatus : 'todo',
            'priority'     => max(0, min(3, (int) ($t['priority'] ?? 1))),
            'due_at'       => isset($t['due_at']) ? (int) $t['due_at'] : null,
            'remind_at'    => isset($t['remind_at']) ? (int) $t['remind_at'] : null,
            'repeat_rule'  => $repeatRuleJson,
            'tags'         => json_encode(self::cleanStringList($t['tags'] ?? []), JSON_UNESCAPED_UNICODE),
            'subtasks'     => json_encode(self::cleanSubtasks($t['subtasks'] ?? []), JSON_UNESCAPED_UNICODE),
            'created_at'   => isset($t['created_at']) ? (int) $t['created_at'] : ($existing ? (int) $existing['created_at'] : $now),
            'updated_at'   => $clientUpdated,
            'completed_at' => isset($t['completed_at']) ? (int) $t['completed_at'] : null,
            'deleted_at'   => isset($t['deleted_at']) ? (int) $t['deleted_at'] : null,
        ];

        if ($existing) {
            $sql = 'UPDATE tasks SET title=:title, notes=:notes, status=:status, priority=:priority, due_at=:due_at, remind_at=:remind_at, repeat_rule=:repeat_rule, tags=:tags, subtasks=:subtasks, updated_at=:updated_at, completed_at=:completed_at, deleted_at=:deleted_at WHERE id=:id';
        } else {
            $sql = 'INSERT INTO tasks (id, title, notes, status, priority, due_at, remind_at, repeat_rule, tags, subtasks, created_at, updated_at, completed_at, deleted_at) VALUES (:id, :title, :notes, :status, :priority, :due_at, :remind_at, :repeat_rule, :tags, :subtasks, :created_at, :updated_at, :completed_at, :deleted_at)';
        }

        $params = [
            ':id'          => $row['id'],
            ':title'       => $row['title'],
            ':notes'       => $row['notes'],
            ':status'      => $row['status'],
            ':priority'    => $row['priority'],
            ':due_at'      => $row['due_at'],
            ':remind_at'   => $row['remind_at'],
            ':repeat_rule' => $row['repeat_rule'],
            ':tags'        => $row['tags'],
            ':subtasks'    => $row['subtasks'],
            ':updated_at'  => $row['updated_at'],
            ':completed_at'=> $row['completed_at'],
            ':deleted_at'  => $row['deleted_at'],
        ];
        if (!$existing) {
            $params[':created_at'] = $row['created_at'];
        }

        Db::pdo()->prepare($sql)->execute($params);

        return ['ok' => true, 'row' => TaskController::serialize(Db::findById('tasks', $id))];
    }

    private function upsertNote(array $n): array
    {
        if (empty($n['id']) || !is_string($n['id'])) {
            return ['ok' => false, 'reason' => 'missing_id'];
        }
        $id = (string) $n['id'];
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id)) {
            return ['ok' => false, 'reason' => 'invalid_id'];
        }
        $title = (string) ($n['title'] ?? '');
        if ($title === '' && empty($n['deleted_at'])) {
            return ['ok' => false, 'reason' => 'missing_title'];
        }

        $now = Db::now();
        $clientUpdated = (int) ($n['updated_at'] ?? $now);
        $existing = Db::findById('notes', $id);
        if ($existing && (int) $existing['updated_at'] >= $clientUpdated) {
            return ['ok' => true, 'row' => NoteController::serialize($existing)];
        }

        $row = [
            'id'         => $id,
            'title'      => mb_substr($title, 0, 500),
            'content'    => mb_substr((string) ($n['content'] ?? ''), 0, 200000),
            'tags'       => json_encode(self::cleanStringList($n['tags'] ?? []), JSON_UNESCAPED_UNICODE),
            'pinned'     => !empty($n['pinned']) ? 1 : 0,
            'favorite'   => !empty($n['favorite']) ? 1 : 0,
            'created_at' => isset($n['created_at']) ? (int) $n['created_at'] : ($existing ? (int) $existing['created_at'] : $now),
            'updated_at' => $clientUpdated,
            'deleted_at' => isset($n['deleted_at']) ? (int) $n['deleted_at'] : null,
        ];

        if ($existing) {
            $sql = 'UPDATE notes SET title=:title, content=:content, tags=:tags, pinned=:pinned, favorite=:favorite, updated_at=:updated_at, deleted_at=:deleted_at WHERE id=:id';
        } else {
            $sql = 'INSERT INTO notes (id, title, content, tags, pinned, favorite, created_at, updated_at, deleted_at) VALUES (:id, :title, :content, :tags, :pinned, :favorite, :created_at, :updated_at, :deleted_at)';
        }
        $params = [
            ':id' => $row['id'], ':title' => $row['title'], ':content' => $row['content'],
            ':tags' => $row['tags'], ':pinned' => $row['pinned'], ':favorite' => $row['favorite'],
            ':updated_at' => $row['updated_at'], ':deleted_at' => $row['deleted_at'],
        ];
        if (!$existing) {
            $params[':created_at'] = $row['created_at'];
        }
        Db::pdo()->prepare($sql)->execute($params);
        return ['ok' => true, 'row' => NoteController::serialize(Db::findById('notes', $id))];
    }

    private static function cleanStringList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        return array_values(array_filter($v, 'is_string'));
    }

    private static function cleanSubtasks(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
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
                'id'   => isset($item['id']) && is_string($item['id']) ? $item['id'] : bin2hex(random_bytes(8)),
            ];
        }
        return $out;
    }

    /**
     * @return string|null JSON string or simple text, max 1000 chars for sync payloads
     */
    private static function normalizeRepeatRule(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $trim = trim($value);
            return $trim === '' ? null : mb_substr($trim, 0, 1000);
        }
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return null;
            }
            return mb_substr($json, 0, 1000);
        }
        return null;
    }
}
