CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('helper','admin') NOT NULL DEFAULT 'helper'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS organizers (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    owner_id BIGINT UNSIGNED UNIQUE,
    active TINYINT NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    platform_slot TINYINT GENERATED ALWAYS AS (IF(owner_id IS NULL,1,NULL)) STORED UNIQUE,
    FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    discipline VARCHAR(10) NOT NULL,
    location VARCHAR(200) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    description TEXT NOT NULL,
    visible TINYINT NOT NULL,
    organizer_id BIGINT UNSIGNED NOT NULL,
    KEY events_organizer (organizer_id,starts_on),
    FOREIGN KEY (organizer_id) REFERENCES organizers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS `groups` (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    visible TINYINT NOT NULL,
    FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS images (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    group_id BIGINT UNSIGNED NOT NULL,
    position TINYINT NOT NULL CHECK (position IN (1,2)),
    mime VARCHAR(32) NOT NULL,
    data MEDIUMBLOB NOT NULL,
    UNIQUE (group_id,position),
    FOREIGN KEY (group_id) REFERENCES `groups`(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS shifts (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    group_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    starts_at BIGINT NOT NULL,
    ends_at BIGINT NOT NULL,
    capacity INT NOT NULL CHECK (capacity > 0),
    visible TINYINT NOT NULL,
    bookable TINYINT NOT NULL,
    KEY shifts_starts_at (starts_at),
    FOREIGN KEY (group_id) REFERENCES `groups`(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS signups (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    shift_id BIGINT UNSIGNED NOT NULL,
    created_at BIGINT NOT NULL,
    UNIQUE (user_id,shift_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (shift_id) REFERENCES shifts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS contacts (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    contact VARCHAR(254) NOT NULL,
    task VARCHAR(500) NOT NULL,
    FOREIGN KEY (event_id) REFERENCES events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS notices (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    created_at BIGINT NOT NULL,
    read_at BIGINT,
    mail_sent_at BIGINT,
    reminder_key VARCHAR(64) UNIQUE,
    KEY notices_user (user_id,created_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS login_attempts (
    `key` VARCHAR(64) PRIMARY KEY,
    `window` BIGINT NOT NULL,
    `count` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS staevne_schema (
    version INT PRIMARY KEY
) ENGINE=InnoDB;
