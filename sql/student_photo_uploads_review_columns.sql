ALTER TABLE student_photo_uploads
ADD COLUMN reviewed_at DATETIME NULL,
ADD COLUMN reviewed_by VARCHAR(100) NULL,
ADD COLUMN reject_reason TEXT NULL;
