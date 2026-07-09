-- GUNA HANYA jika setup pertama gagal, tiada credential pernah disimpan,
-- dan anda mahu menetapkan Master Password dari awal.
USE zurie_noc;
DELETE FROM vault_settings
WHERE id = 1
  AND NOT EXISTS (SELECT 1 FROM device_credentials LIMIT 1);
