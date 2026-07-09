-- Zurie Photo Audit Fasa 7: Auto Background Quality Detection
-- Page photo_audit.php turut mencipta column ini secara automatik.

ALTER TABLE student_photo_audit ADD COLUMN background_status VARCHAR(30) NULL AFTER quality_checked_by;
ALTER TABLE student_photo_audit ADD COLUMN background_score DECIMAL(5,1) NULL AFTER background_status;
ALTER TABLE student_photo_audit ADD COLUMN background_white_ratio DECIMAL(5,1) NULL AFTER background_score;
ALTER TABLE student_photo_audit ADD COLUMN background_uniformity DECIMAL(5,1) NULL AFTER background_white_ratio;
ALTER TABLE student_photo_audit ADD COLUMN background_brightness DECIMAL(6,1) NULL AFTER background_uniformity;
ALTER TABLE student_photo_audit ADD COLUMN background_color_ratio DECIMAL(5,1) NULL AFTER background_brightness;
ALTER TABLE student_photo_audit ADD COLUMN background_shadow_ratio DECIMAL(5,1) NULL AFTER background_color_ratio;
ALTER TABLE student_photo_audit ADD COLUMN background_dominant_color VARCHAR(50) NULL AFTER background_shadow_ratio;
ALTER TABLE student_photo_audit ADD COLUMN background_dominant_hex VARCHAR(10) NULL AFTER background_dominant_color;
ALTER TABLE student_photo_audit ADD COLUMN background_reason VARCHAR(255) NULL AFTER background_dominant_hex;
ALTER TABLE student_photo_audit ADD COLUMN background_checked_at DATETIME NULL AFTER background_reason;
ALTER TABLE student_photo_audit ADD COLUMN background_checked_by VARCHAR(100) NULL AFTER background_checked_at;
ALTER TABLE student_photo_audit ADD INDEX idx_background_status (background_status);
