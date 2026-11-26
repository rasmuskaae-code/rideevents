CREATE TABLE IF NOT EXISTS helper_applications (
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL,
    requested_at BIGINT NOT NULL,
    decided_at BIGINT,
    PRIMARY KEY (event_id,user_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS organizer_event_usage (
    organizer_id BIGINT UNSIGNED PRIMARY KEY,
    created_count BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (organizer_id) REFERENCES organizers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS billing_orders (
    id CHAR(32) PRIMARY KEY,
    organizer_id BIGINT UNSIGNED NOT NULL,
    plan ENUM('event','annual') NOT NULL,
    live_mode TINYINT NOT NULL,
    price_id VARCHAR(255) NOT NULL,
    session_id VARCHAR(255) UNIQUE,
    payment_intent VARCHAR(255) UNIQUE,
    checkout_url TEXT,
    expires_at BIGINT NOT NULL,
    created_at BIGINT NOT NULL,
    closed_at BIGINT,
    paid_at BIGINT,
    access_until BIGINT,
    consumed_at BIGINT,
    refunded_at BIGINT,
    KEY billing_orders_organizer (organizer_id,live_mode,plan),
    FOREIGN KEY (organizer_id) REFERENCES organizers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
