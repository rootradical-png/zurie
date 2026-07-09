ALTER TABLE student_photo_audit
    ADD COLUMN quality_status VARCHAR(30) NULL AFTER checked_at,
    ADD COLUMN quality_reason VARCHAR(255) NULL AFTER quality_status,
    ADD COLUMN quality_checked_at DATETIME NULL AFTER quality_reason,
    ADD COLUMN quality_checked_by VARCHAR(100) NULL AFTER quality_checked_at;

CREATE INDEX idx_quality_status ON student_photo_audit (quality_status);
