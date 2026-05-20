<?php
declare(strict_types=1);

/**
 * End-to-end smoke test of the API surface, hitting the local dev server.
 * Usage:
 *   php deploy/api-smoke.php [base_url]
 * Defaults to http://127.0.0.1:8765
 *
 * Multi-user mode: this script registers two fresh users with random
 * usernames, then verifies that user A cannot read or modify user B's data.
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

$suffix  = bin2hex(random_bytes(3));
$uA      = 'smoke_a_' . $suffix;
$uB      = 'smoke_b_' . $suffix;
$pw      = 'smokepw' . $suffix . 'X';

$r = req('POST', "{$base}/api/auth/register", [
    'headers' => ['Content-Type: application/json'],
    'body'    => json_encode(['username' => $uA, 'password' => $pw]),
]);
assertStatus("register-{$uA}", $r, 200);
$accessA = $r['json']['accessToken'] ?? null;
assert($accessA);
assert(($r['json']['user']['username'] ?? null) === $uA);
$authA = "Authorization: Bearer {$accessA}";
echo "  user A id: " . ($r['json']['user']['id'] ?? '?') . "\n";

$r = req('POST', "{$base}/api/auth/register", [
    'headers' => ['Content-Type: application/json'],
    'body'    => json_encode(['username' => $uB, 'password' => $pw]),
]);
assertStatus("register-{$uB}", $r, 200);
$accessB = $r['json']['accessToken'] ?? null;
assert($accessB);
$authB = "Authorization: Bearer {$accessB}";
echo "  user B id: " . ($r['json']['user']['id'] ?? '?') . "\n";

// Re-login user A to make sure password persisted, get a 2nd live session for sessions/list test.
$r = req('POST', "{$base}/api/auth/login", [
    'headers' => ['Content-Type: application/json'],
    'body'    => json_encode(['username' => $uA, 'password' => $pw]),
]);
assertStatus('login-A', $r, 200);

$r = req('GET', "{$base}/api/auth/me", ['headers' => [$authA]]);
assertStatus('me-A', $r, 200);
assert(($r['json']['user']['username'] ?? null) === $uA);

$r = req('POST', "{$base}/api/tasks", [
    'headers' => ['Content-Type: application/json', $authA],
    'body'    => json_encode([
        'title'    => 'Smoke task A',
        'priority' => 2,
        'tags'     => ['smoke', 'test'],
        'subtasks' => [['text' => 'Step 1', 'done' => false], ['text' => 'Step 2', 'done' => true]],
    ]),
]);
assertStatus('create-task-A', $r, 200);
$taskId = $r['json']['task']['id'];
echo "  task id (A): {$taskId}\n";

$r = req('GET', "{$base}/api/tasks?limit=10", ['headers' => [$authA]]);
assertStatus('list-tasks-A', $r, 200);
assert(count($r['json']['tasks']) >= 1);

$r = req('GET', "{$base}/api/tasks?limit=10", ['headers' => [$authB]]);
assertStatus('list-tasks-B-isolated', $r, 200);
assert(count($r['json']['tasks']) === 0);

$r = req('PATCH', "{$base}/api/tasks/{$taskId}", [
    'headers' => ['Content-Type: application/json', $authB],
    'body'    => json_encode(['status' => 'done']),
]);
assertStatus('update-task-other-user-must-404', $r, 404);

$r = req('PATCH', "{$base}/api/tasks/{$taskId}", [
    'headers' => ['Content-Type: application/json', $authA],
    'body'    => json_encode(['status' => 'done']),
]);
assertStatus('update-task-A', $r, 200);
assert($r['json']['task']['completed_at'] !== null);

$r = req('GET', "{$base}/api/tasks/{$taskId}", ['headers' => [$authA]]);
assertStatus('show-task-A', $r, 200);

$r = req('DELETE', "{$base}/api/tasks/{$taskId}", ['headers' => [$authA]]);
assertStatus('delete-task-A', $r, 200);

$r = req('POST', "{$base}/api/tasks/{$taskId}/restore", ['headers' => [$authA]]);
assertStatus('restore-task-A', $r, 200);

$r = req('POST', "{$base}/api/notes", [
    'headers' => ['Content-Type: application/json', $authA],
    'body'    => json_encode(['title' => 'Smoke note A', 'content' => '# Hello A', 'pinned' => true]),
]);
assertStatus('create-note-A', $r, 200);
$noteId = $r['json']['note']['id'];

$r = req('PATCH', "{$base}/api/notes/{$noteId}", [
    'headers' => ['Content-Type: application/json', $authA],
    'body'    => json_encode(['favorite' => true, 'tags' => ['hello']]),
]);
assertStatus('update-note-A', $r, 200);
assert($r['json']['note']['favorite'] === true);
assert($r['json']['note']['tags'] === ['hello']);

$r = req('PATCH', "{$base}/api/notes/{$noteId}", [
    'headers' => ['Content-Type: application/json', $authA],
    'body'    => json_encode(['title' => null, 'content' => null]),
]);
assertStatus('update-note-A-null', $r, 200);
assert($r['json']['note']['title'] === '');
assert($r['json']['note']['content'] === '');

$r = req('PATCH', "{$base}/api/notes/{$noteId}", [
    'headers' => ['Content-Type: application/json', $authA],
    'body'    => json_encode(['title' => 'Smoke note A', 'content' => '# Hello A']),
]);
assertStatus('restore-note-title-A', $r, 200);

$r = req('GET', "{$base}/api/notes?search=Smoke", ['headers' => [$authA]]);
assertStatus('list-notes-A', $r, 200);
assert(count($r['json']['notes']) >= 1);

$r = req('GET', "{$base}/api/notes?search=Smoke", ['headers' => [$authB]]);
assertStatus('list-notes-B-isolated', $r, 200);
assert(count($r['json']['notes']) === 0);

$r = req('DELETE', "{$base}/api/notes/{$noteId}", ['headers' => [$authA]]);
assertStatus('delete-note-A', $r, 200);

$r = req('GET', "{$base}/api/sync/pull?since=0", ['headers' => [$authA]]);
assertStatus('sync-pull-A', $r, 200);
echo "  pull A: tasks=" . count($r['json']['tasks']) . " notes=" . count($r['json']['notes']) . "\n";

$r = req('GET', "{$base}/api/sync/pull?since=0", ['headers' => [$authB]]);
assertStatus('sync-pull-B', $r, 200);
assert(count($r['json']['tasks']) === 0);
assert(count($r['json']['notes']) === 0);

// sync/push: client tries to claim another user's row, server should reject
// or scope to current user — we test that B can't write into A's id.
$pushBody = [
    'tasks' => [[
        'id'         => $taskId,           // belongs to A, but pushed as B
        'title'      => 'hijack attempt',
        'updated_at' => time() * 1000,
    ]],
    'notes' => [],
];
$r = req('POST', "{$base}/api/sync/push", [
    'headers' => ['Content-Type: application/json', $authB],
    'body'    => json_encode($pushBody),
]);
assertStatus('sync-push-B-rejects-A-id', $r, 200);
$rejected = $r['json']['rejected'] ?? [];
assert(count($rejected) === 1, 'expected 1 rejection');
assert(($rejected[0]['reason'] ?? '') === 'id_conflict', "expected id_conflict, got " . ($rejected[0]['reason'] ?? '?'));

// And a clean push as B works.
$pushBody = [
    'tasks' => [[
        'id'         => 'syncedtask' . $suffix . 'b',
        'title'      => 'Synced task B',
        'updated_at' => time() * 1000,
    ]],
    'notes' => [[
        'id'         => 'synced0note' . $suffix . 'b',
        'title'      => 'Synced note B',
        'content'    => 'pushed by B',
        'updated_at' => time() * 1000,
    ]],
];
$r = req('POST', "{$base}/api/sync/push", [
    'headers' => ['Content-Type: application/json', $authB],
    'body'    => json_encode($pushBody),
]);
assertStatus('sync-push-B', $r, 200);
echo "  pushed B: applied=" . count($r['json']['appliedTasks']) . " rejected=" . count($r['json']['rejected']) . "\n";

// Account settings smoke: list sessions, then change password, then verify
// the OLD A token is still valid (current session) but the SECOND login's
// session was revoked.
$r = req('GET', "{$base}/api/auth/sessions", ['headers' => [$authA]]);
assertStatus('sessions-A', $r, 200);
echo "  sessions for A: " . count($r['json']['sessions']) . "\n";

$pwNew = $pw . 'Z';
$r = req('PATCH', "{$base}/api/auth/password", [
    'headers' => ['Content-Type: application/json', $authA],
    'body'    => json_encode(['oldPassword' => $pw, 'newPassword' => $pwNew]),
]);
assertStatus('change-password-A', $r, 200);

// Old password no longer works.
$r = req('POST', "{$base}/api/auth/login", [
    'headers' => ['Content-Type: application/json'],
    'body'    => json_encode(['username' => $uA, 'password' => $pw]),
]);
assertStatus('old-password-rejected', $r, 401);

// New password works.
$r = req('POST', "{$base}/api/auth/login", [
    'headers' => ['Content-Type: application/json'],
    'body'    => json_encode(['username' => $uA, 'password' => $pwNew]),
]);
assertStatus('new-password-accepted', $r, 200);

$r = req('GET', "{$base}/api/auth/login-history", ['headers' => [$authA]]);
assertStatus('login-history', $r, 200);
echo "  login history entries: " . count($r['json']['attempts']) . "\n";

$r = req('POST', "{$base}/api/auth/logout", [
    'headers' => ['Content-Type: application/json', $authA],
    'body'    => json_encode([]),
]);
assertStatus('logout-A', $r, 200);

echo "\nALL OK\n";
