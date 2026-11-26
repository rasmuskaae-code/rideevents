CREATE TABLE auth_links (
    token_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    purpose TEXT NOT NULL CHECK(purpose IN ('quick','reset')),
    event_id INTEGER NOT NULL DEFAULT 0,
    expires_at INTEGER NOT NULL
);
CREATE INDEX auth_links_expiry ON auth_links(expires_at);
CREATE INDEX auth_links_user ON auth_links(user_id);
