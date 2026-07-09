ALTER TABLE student_photo_uploads
    ADD COLUMN original_file VARCHAR(255) NULL,
    ADD COLUMN repaired_file VARCHAR(255) NULL,
    ADD COLUMN repair_status VARCHAR(30) NULL,
    ADD COLUMN repair_message TEXT NULL,
    ADD COLUMN repaired_at DATETIME NULL;

-- Nota: Kod PHP patch turut cuba menambah column ini secara automatik.
-- Jika column sudah wujud, tidak perlu jalankan fail SQL ini.
