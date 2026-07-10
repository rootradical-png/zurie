-- AKSES BACA SAHAJA UNTUK MODUL SIJIL AKUAN KOKURIKULUM
-- Jalankan dalam phpMyAdmin menggunakan akaun MySQL admin/root.
-- Server aplikasi Zurie: 10.14.48.80
-- Akaun aplikasi: zurie_sync

-- 1. Semak akaun wujud
SELECT User, Host
FROM mysql.user
WHERE User = 'zurie_sync'
  AND Host = '10.14.48.80';

-- 2. Database pelajar semasa
GRANT SELECT ON `db_pelajarkmp`.*
TO 'zurie_sync'@'10.14.48.80';

-- 3. Semua database arkib pelajar mengikut tahun
GRANT SELECT ON `\_pelajarkmp%`.*
TO 'zurie_sync'@'10.14.48.80';

-- 4. Database aktiviti kokurikulum lama
GRANT SELECT ON `db`.*
TO 'zurie_sync'@'10.14.48.80';

-- 5. Database eSASI
GRANT SELECT ON `esasi`.*
TO 'zurie_sync'@'10.14.48.80';

-- 6. Aktifkan perubahan
FLUSH PRIVILEGES;

-- 7. Semak semua permission
SHOW GRANTS FOR 'zurie_sync'@'10.14.48.80';