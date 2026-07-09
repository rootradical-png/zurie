ALTER TABLE student_photo_uploads ADD COLUMN sync_status VARCHAR(30) NULL DEFAULT 'belum';
ALTER TABLE student_photo_uploads ADD COLUMN sync_message TEXT NULL;
ALTER TABLE student_photo_uploads ADD COLUMN synced_at DATETIME NULL;
ALTER TABLE student_photo_uploads ADD COLUMN synced_by VARCHAR(100) NULL;
ALTER TABLE student_photo_uploads ADD COLUMN sync_remote_file VARCHAR(255) NULL;
ALTER TABLE student_photo_uploads ADD COLUMN sync_attempts INT NOT NULL DEFAULT 0;
