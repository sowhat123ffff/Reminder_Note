-- Reminder Note - SQLite schema (multi-user)
-- Apply with: sqlite3 data/app.db < database/schema.sql
-- Or via Db::init() at runtime (idempotent).

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;
PRAGMA synchronous = NORMAL;
PRAGMA temp_store = MEMORY;
PRAGMA mmap_size = 30000000000;

CREATE TABLE IF NOT EXISTS users (
    id            TEXT PRIMARY KEY,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    INTEGER NOT NULL,
    updated_at    INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);

CREATE TABLE IF NOT EXISTS tasks (
    id            TEXT PRIMARY KEY,
    user_id       TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title         TEXT NOT NULL,
    notes         TEXT NOT NULL DEFAULT '',
    status        TEXT NOT NULL DEFAULT 'todo',
    priority      INTEGER NOT NULL DEFAULT 1,
    due_at        INTEGER,
    remind_at     INTEGER,
    repeat_rule   TEXT,
    tags          TEXT NOT NULL DEFAULT '[]',
    subtasks      TEXT NOT NULL DEFAULT '[]',
    created_at    INTEGER NOT NULL,
    updated_at    INTEGER NOT NULL,
    completed_at  INTEGER,
    deleted_at    INTEGER,
    CHECK (status IN ('todo', 'doing', 'done', 'archived')),
    CHECK (priority BETWEEN 0 AND 3)
);
CREATE INDEX IF NOT EXISTS idx_tasks_user_updated ON tasks(user_id, updated_at);
CREATE INDEX IF NOT EXISTS idx_tasks_user_due     ON tasks(user_id, due_at);
CREATE INDEX IF NOT EXISTS idx_tasks_user_status  ON tasks(user_id, status);
CREATE INDEX IF NOT EXISTS idx_tasks_user_deleted ON tasks(user_id, deleted_at);

CREATE TABLE IF NOT EXISTS notes (
    id          TEXT PRIMARY KEY,
    user_id     TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title       TEXT NOT NULL,
    content     TEXT NOT NULL DEFAULT '',
    tags        TEXT NOT NULL DEFAULT '[]',
    pinned      INTEGER NOT NULL DEFAULT 0,
    favorite    INTEGER NOT NULL DEFAULT 0,
    created_at  INTEGER NOT NULL,
    updated_at  INTEGER NOT NULL,
    deleted_at  INTEGER
);
CREATE INDEX IF NOT EXISTS idx_notes_user_updated ON notes(user_id, updated_at);
CREATE INDEX IF NOT EXISTS idx_notes_user_deleted ON notes(user_id, deleted_at);
CREATE INDEX IF NOT EXISTS idx_notes_user_pinned  ON notes(user_id, pinned);

CREATE TABLE IF NOT EXISTS attachments (
    id          TEXT PRIMARY KEY,
    user_id     TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ref_type    TEXT NOT NULL,
    ref_id      TEXT,
    name        TEXT NOT NULL,
    mime        TEXT NOT NULL,
    size        INTEGER NOT NULL,
    path        TEXT NOT NULL,
    created_at  INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_attachments_user_ref ON attachments(user_id, ref_type, ref_id);

CREATE TABLE IF NOT EXISTS refresh_tokens (
    jti          TEXT PRIMARY KEY,
    user_id      TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at   INTEGER NOT NULL,
    revoked      INTEGER NOT NULL DEFAULT 0,
    created_at   INTEGER NOT NULL,
    user_agent   TEXT NOT NULL DEFAULT '',
    ip           TEXT NOT NULL DEFAULT '',
    last_used_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_refresh_user    ON refresh_tokens(user_id, expires_at);
CREATE INDEX IF NOT EXISTS idx_refresh_expires ON refresh_tokens(expires_at);

-- Auth attempts: kind='login' or 'register'.
-- For login attempts, user_id is set when the username matches an existing
-- user (even on failure) so the user can later see suspicious attempts on
-- their own account; the API never returns this data to anyone but that user.
CREATE TABLE IF NOT EXISTS auth_attempts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ip          TEXT NOT NULL,
    kind        TEXT NOT NULL CHECK (kind IN ('login','register')),
    success     INTEGER NOT NULL,
    user_id     TEXT,
    user_agent  TEXT NOT NULL DEFAULT '',
    created_at  INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_auth_attempts_lookup ON auth_attempts(kind, ip, created_at);
CREATE INDEX IF NOT EXISTS idx_auth_attempts_user ON auth_attempts(user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS settings (
    key         TEXT PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_at  INTEGER NOT NULL
);
