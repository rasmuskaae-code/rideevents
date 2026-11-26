CREATE TABLE helper_applications (
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id),
    status TEXT NOT NULL CHECK(status IN ('pending','approved','rejected')),
    requested_at INTEGER NOT NULL,
    decided_at INTEGER,
    PRIMARY KEY (event_id,user_id)
);
CREATE TABLE organizer_event_usage (
    organizer_id INTEGER PRIMARY KEY REFERENCES organizers(id),
    created_count INTEGER NOT NULL
);
CREATE TABLE billing_orders (
    id TEXT PRIMARY KEY,
    organizer_id INTEGER NOT NULL REFERENCES organizers(id),
    plan TEXT NOT NULL CHECK(plan IN ('event','annual')),
    live_mode INTEGER NOT NULL,
    price_id TEXT NOT NULL,
    session_id TEXT UNIQUE,
    payment_intent TEXT UNIQUE,
    checkout_url TEXT,
    expires_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    closed_at INTEGER,
    paid_at INTEGER,
    access_until INTEGER,
    consumed_at INTEGER,
    refunded_at INTEGER
);
CREATE INDEX billing_orders_organizer ON billing_orders(organizer_id, live_mode, plan);
