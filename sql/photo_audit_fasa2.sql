CREATE TABLE IF NOT EXISTS student_photo_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    matrik VARCHAR(30) NOT NULL UNIQUE,
    nama VARCHAR(255) NULL,
    photo_exists TINYINT(1) NOT NULL DEFAULT 0,
    photo_url VARCHAR(500) NULL,
    http_code INT NULL,
    error_message TEXT NULL,
    checked_at DATETIME NULL,
    INDEX idx_photo_exists (photo_exists),
    INDEX idx_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
