<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';

use App\Auth;
use App\HttpException;
use App\Request;
use App\Response;
use App\Router;
use App\Controllers\AuthController;
use App\Controllers\TaskController;
use App\Controllers\NoteController;
use App\Controllers\UploadController;
use App\Controllers\SyncController;

set_exception_handler(function (\Throwable $e): void {
    if ($e instanceof HttpException) {
        Response::error($e->status, $e->errorCode, $e->getMessage(), $e->details);
        return;
    }

    if (\App\Config::isDebug()) {
        Response::error(500, 'server_error', $e->getMessage(), [
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
        ]);
    } else {
        error_log('[reminder-note] ' . $e);
        Response::error(500, 'server_error', 'Internal server error');
    }
});

set_error_handler(function (int $errno, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new \ErrorException($msg, 0, $errno, $file, $line);
});

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}

$req    = Request::fromGlobals();
$router = new Router();

$auth = new AuthController();
$router->post('/auth/login',   [$auth, 'login'],   true);
$router->post('/auth/refresh', [$auth, 'refresh'], true);
$router->post('/auth/logout',  [$auth, 'logout'],  true);
$router->get( '/auth/me',      [$auth, 'me']);

if (class_exists(TaskController::class)) {
    $tasks = new TaskController();
    $router->get(   '/tasks',         [$tasks, 'list']);
    $router->post(  '/tasks',         [$tasks, 'create']);
    $router->get(   '/tasks/{id}',    [$tasks, 'show']);
    $router->patch( '/tasks/{id}',    [$tasks, 'update']);
    $router->delete('/tasks/{id}',    [$tasks, 'destroy']);
    $router->post(  '/tasks/{id}/restore', [$tasks, 'restore']);
}

if (class_exists(NoteController::class)) {
    $notes = new NoteController();
    $router->get(   '/notes',         [$notes, 'list']);
    $router->post(  '/notes',         [$notes, 'create']);
    $router->get(   '/notes/{id}',    [$notes, 'show']);
    $router->patch( '/notes/{id}',    [$notes, 'update']);
    $router->delete('/notes/{id}',    [$notes, 'destroy']);
    $router->post(  '/notes/{id}/restore', [$notes, 'restore']);
}

if (class_exists(SyncController::class)) {
    $sync = new SyncController();
    $router->get( '/sync/pull', [$sync, 'pull']);
    $router->post('/sync/push', [$sync, 'push']);
}

if (class_exists(UploadController::class)) {
    $upload = new UploadController();
    $router->post('/upload', [$upload, 'create']);
}

$router->get('/health', fn() => ['ok' => true, 'time' => \App\Db::now()], true);

$router->dispatch($req);
