-- Struktur table sedia ada pada i-SIMS:
-- db_pelajarkmp.delima (nomatrik PRIMARY KEY, Dacc, Dpass)
-- Modul tidak mencipta atau memadam table.

-- Semak struktur:
SELECT nomatrik, Dacc, Dpass
FROM db_pelajarkmp.delima
LIMIT 0;

-- Beri akses kepada akaun sync jika diperlukan. Jalankan sebagai root:
GRANT SELECT, INSERT, UPDATE
ON db_pelajarkmp.delima
TO 'zurie_sync'@'10.14.48.100';

-- Semak akaun tanpa memaparkan kata laluan:
SELECT nomatrik, Dacc
FROM db_pelajarkmp.delima
ORDER BY nomatrik
LIMIT 30;
