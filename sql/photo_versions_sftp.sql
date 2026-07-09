CREATE TABLE IF NOT EXISTS student_photo_version_reviews (
    matrik VARCHAR(30) NOT NULL PRIMARY KEY,
    nama VARCHAR(255) NULL,
    selected_source VARCHAR(255) NOT NULL,
    standard_file VARCHAR(255) NOT NULL,
    delete_candidates_json LONGTEXT NULL,
    selection_status VARCHAR(30) NOT NULL DEFAULT 'success',
    selection_message TEXT NULL,
    selected_by VARCHAR(100) NULL,
    selected_at DATETIME NULL,
    cleanup_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    cleanup_done_at DATETIME NULL,
    cleanup_done_by VARCHAR(100) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cleanup_status (cleanup_status),
    INDEX idx_selected_at (selected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
