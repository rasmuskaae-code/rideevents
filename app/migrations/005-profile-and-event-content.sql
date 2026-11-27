CREATE TABLE user_preferences (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    phone TEXT NOT NULL DEFAULT '',
    email_notifications INTEGER NOT NULL DEFAULT 1 CHECK(email_notifications IN (0,1)),
    email_notice_after_id INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE event_banners (
    event_id INTEGER PRIMARY KEY REFERENCES events(id) ON DELETE CASCADE,
    mime TEXT NOT NULL,
    data BLOB NOT NULL
);
CREATE TABLE event_links (
    id INTEGER PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    url TEXT NOT NULL
);
CREATE INDEX event_links_event ON event_links(event_id);
CREATE TABLE push_subscriptions (
    endpoint_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL,
    last_notice_id INTEGER NOT NULL,
    next_attempt_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX push_subscriptions_user ON push_subscriptions(user_id);
