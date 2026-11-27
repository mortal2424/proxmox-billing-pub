CREATE TABLE IF NOT EXISTS web_google_identities (
    google_subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    CONSTRAINT fk_web_google_identity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
