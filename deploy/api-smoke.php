<?php
declare(strict_types=1);

/**
 * End-to-end smoke test of the API surface, hitting the local dev server.
 * Usage:
 *   php deploy/api-smoke.php [base_url]
 * Defaults to http://127.0.0.1:8765
 */

$base = $argv[1] ?? 'http://127.0.0.1:8765';

function req(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $opts['headers'] ?? [],
        CURLOPT_POSTFIELDS => $opts['body'] ?? null,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        throw new RuntimeException('curl error: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr((string) $resp, $headerSize);
    curl_close($ch);
    $json = json_decode($body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
}

function assertStatus(string $label, array $r, int $want): void {
    $got = $r['status'];
    $ok = $got === $want;
    echo ($ok ? 'PASS' : 'FAIL') . " {$label}: status={$got} want={$want}\n";
    if (!$ok) {
        echo "  body: " . substr($r['body'], 0, 240) . "\n";
        exit(1);
    }
}

echo "Smoke testing {$base}\n";

$r = req('GET', "{$base}/api/health");
assertStatus('health', $r, 200);

$r = req('POST', "{$base}/api/auth/login", [
    'headers' => ['Content-Type: application/json'],
    'body'    => json_encode(['username' => 'jian', 'password' => '123456']),
]);
assertStatus('login', $r, 200);
$access = $r['json']['accessToken'] ?? null;
assert($access);
$auth = "Authorization: Bearer {$access}";

$r = req('GET', "{$base}/api/auth/me", ['headers' => [$auth]]);
assertStatus('me', $r, 200);

$r = req('POST', "{$base}/api/tasks", [
    'headers' => ['Content-Type: application/json', $auth],
    'body'    => json_encode([
        'title' => 'Smoke task',
        'priority' => 2,
        'tags' => ['smoke', 'test'],
        'subtasks' => [['text' => 'Step 1', 'done' => false], ['text' => 'Step 2', 'done' => true]],
    ]),
]);
assertStatus('create-task', $r, 200);
$taskId = $r['json']['task']['id'];
echo "  task id: {$taskId}\n";

$r = req('GET', "{$base}/api/tasks?limit=10", ['headers' => [$auth]]);
assertStatus('list-tasks', $r, 200);
assert(count($r['json']['tasks']) >= 1);

$r = req('PATCH', "{$base}/api/tasks/{$taskId}", [
    'headers' => ['Content-Type: application/json', $auth],
    'body'    => json_encode(['status' => 'done']),
]);
assertStatus('update-task', $r, 200);
assert($r['json']['task']['completed_at'] !== null);

$r = req('GET', "{$base}/api/tasks/{$taskId}", ['headers' => [$auth]]);
assertStatus('show-task', $r, 200);

$r = req('DELETE', "{$base}/api/tasks/{$taskId}", ['headers' => [$auth]]);
assertStatus('delete-task', $r, 200);

$r = req('POST', "{$base}/api/tasks/{$taskId}/restore", ['headers' => [$auth]]);
assertStatus('restore-task', $r, 200);

$r = req('POST', "{$base}/api/notes", [
    'headers' => ['Content-Type: application/json', $auth],
    'body'    => json_encode(['title' => 'Smoke note', 'content' => '# Hello', 'pinned' => true]),
]);
assertStatus('create-note', $r, 200);
$noteId = $r['json']['note']['id'];

$r = req('PATCH', "{$base}/api/notes/{$noteId}", [
    'headers' => ['Content-Type: application/json', $auth],
    'body'    => json_encode(['favorite' => true, 'tags' => ['hello']]),
]);
assertStatus('update-note', $r, 200);
assert($r['json']['note']['favorite'] === true);
assert($r['json']['note']['tags'] === ['hello']);

$r = req('PATCH', "{$base}/api/notes/{$noteId}", [
    'headers' => ['Content-Type: application/json', $auth],
    'body'    => json_encode(['title' => null, 'content' => null]),
]);
assertStatus('update-note-null', $r, 200);
assert($r['json']['note']['title'] === '');
assert($r['json']['note']['content'] === '');

$r = req('PATCH', "{$base}/api/notes/{$noteId}", [
    'headers' => ['Content-Type: application/json', $auth],
    'body'    => json_encode(['title' => 'Smoke note', 'content' => '# Hello']),
]);
assertStatus('restore-note-title', $r, 200);

$r = req('GET', "{$base}/api/notes?search=Smoke", ['headers' => [$auth]]);
assertStatus('list-notes', $r, 200);
assert(count($r['json']['notes']) >= 1);

$r = req('DELETE', "{$base}/api/notes/{$noteId}", ['headers' => [$auth]]);
assertStatus('delete-note', $r, 200);

$r = req('GET', "{$base}/api/sync/pull?since=0", ['headers' => [$auth]]);
assertStatus('sync-pull', $r, 200);
echo "  pull tasks=" . count($r['json']['tasks']) . " notes=" . count($r['json']['notes']) . "\n";

$pushBody = [
    'tasks' => [[
        'id' => 'syncedtask123abc',
        'title' => 'Synced task',
        'updated_at' => time() * 1000,
    ]],
    'notes' => [[
        'id' => 'synced0note0001a',
        'title' => 'Synced note',
        'content' => 'pushed',
        'updated_at' => time() * 1000,
    ]],
];
$r = req('POST', "{$base}/api/sync/push", [
    'headers' => ['Content-Type: application/json', $auth],
    'body'    => json_encode($pushBody),
]);
assertStatus('sync-push', $r, 200);
echo "  pushed applied=" . count($r['json']['appliedTasks']) . " rejected=" . count($r['json']['rejected']) . "\n";

$r = req('POST', "{$base}/api/auth/logout", [
    'headers' => ['Content-Type: application/json', $auth],
    'body'    => json_encode([]),
]);
assertStatus('logout', $r, 200);

echo "\nALL OK\n";
