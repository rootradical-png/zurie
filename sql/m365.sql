-- Table kata laluan sementara Microsoft 365 untuk i-SIMS.
-- Halaman /zurie/pages/ms365_export.php akan mencipta table ini secara automatik jika belum wujud.

CREATE TABLE IF NOT EXISTS `m365` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nomatrik` VARCHAR(30) NOT NULL,
  `nama` VARCHAR(255) NOT NULL DEFAULT '',
  `display_name` VARCHAR(255) NOT NULL DEFAULT '',
  `username` VARCHAR(190) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `licenses` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_m365_nomatrik` (`nomatrik`),
  KEY `idx_m365_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
