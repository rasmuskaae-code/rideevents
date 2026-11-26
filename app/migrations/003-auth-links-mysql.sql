CREATE TABLE IF NOT EXISTS auth_links (
    token_hash CHAR(64) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('quick','reset') NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at BIGINT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY auth_links_expiry (expires_at),
    KEY auth_links_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
