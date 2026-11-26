CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'helper' CHECK(role IN ('helper','admin'))
);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL, discipline TEXT NOT NULL,
    location TEXT NOT NULL, starts_on TEXT NOT NULL, ends_on TEXT NOT NULL,
    description TEXT NOT NULL, visible INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY, event_id INTEGER NOT NULL REFERENCES events(id),
    name TEXT NOT NULL, description TEXT NOT NULL, visible INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS images (
    id INTEGER PRIMARY KEY, group_id INTEGER NOT NULL REFERENCES groups(id),
    position INTEGER NOT NULL CHECK(position IN (1,2)), mime TEXT NOT NULL, data BLOB NOT NULL,
    UNIQUE(group_id, position)
);
CREATE TABLE IF NOT EXISTS shifts (
    id INTEGER PRIMARY KEY, group_id INTEGER NOT NULL REFERENCES groups(id),
    name TEXT NOT NULL, starts_at INTEGER NOT NULL, ends_at INTEGER NOT NULL,
    capacity INTEGER NOT NULL CHECK(capacity > 0), visible INTEGER NOT NULL, bookable INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS signups (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id),
    shift_id INTEGER NOT NULL REFERENCES shifts(id), created_at INTEGER NOT NULL,
    UNIQUE(user_id, shift_id)
);
CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY, event_id INTEGER NOT NULL REFERENCES events(id),
    name TEXT NOT NULL, contact TEXT NOT NULL, task TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS notices (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id),
    title TEXT NOT NULL, body TEXT NOT NULL, created_at INTEGER NOT NULL,
    read_at INTEGER, mail_sent_at INTEGER, reminder_key TEXT UNIQUE
);
CREATE INDEX IF NOT EXISTS shifts_group ON shifts(group_id);
CREATE INDEX IF NOT EXISTS signups_shift ON signups(shift_id);
CREATE INDEX IF NOT EXISTS notices_user ON notices(user_id, created_at);
CREATE TABLE IF NOT EXISTS login_attempts (
    key TEXT PRIMARY KEY, window INTEGER NOT NULL, count INTEGER NOT NULL
);
