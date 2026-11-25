CREATE TABLE IF NOT EXISTS web_login_attempts (
    source CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    attempts TINYINT UNSIGNED NOT NULL,
    expires_at BIGINT NOT NULL,
    INDEX (expires_at)
) ENGINE=InnoDB;
