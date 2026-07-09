PERSONAL NOC DASHBOARD - SECURITY HARDENING PHASE 1
===================================================

Tujuan patch:
1. Sekat direct web access ke config/, data/, sql/, lib/ dan agents/.
2. Sekat fail backup, ZIP, SQL dump, log, .env, .ini dan diagnostic scripts.
3. Hadkan page sensitif dan API kepada rangkaian private/LAN.
4. Tambah security headers, no-cache dan CSRF pada modul eksport.
5. Neutralisasi CSV formula injection (=, +, -, @, tab, CR).
6. Sembunyikan SQLSTATE/struktur database daripada browser; ralat penuh masuk PHP error log.

PENTING:
- Patch ini TIDAK overwrite index.php atau data/noc_devices.*.
- IP device yang telah dikemas kini tidak disentuh.
- Jika portal perlu dicapai dari IP awam tertentu, tambah IP/CIDR itu dalam:
  config/security_config.php
  pages/.htaccess
  api/.htaccess
- Jika selepas extract keluar HTTP 500, semak Apache error.log. Punca paling biasa ialah AllowOverride tidak membenarkan .htaccess.
- Untuk rollback cepat, rename .htaccess kepada .htaccess.off.

UJIAN SELEPAS PASANG:
A. Dari PC rangkaian 10.x, buka /zurie/ dan page export. Sepatutnya berfungsi.
B. Cuba buka /zurie/config/ilmu_pg_config.php. Sepatutnya 403 Forbidden.
C. Cuba buka /zurie/data/noc_devices.json. Sepatutnya 403 Forbidden.
D. Cuba buka /zurie/api/server_metrics_current.php dari rangkaian dalaman. Sepatutnya JSON biasa.
E. Cuba submit export selepas refresh. Sepatutnya berjaya tanpa ralat CSRF.

LANGKAH SERVER TAMBAHAN (BUKAN DALAM PATCH):
- Gunakan akaun PostgreSQL read-only khusus untuk export.
- Hadkan Adminer kepada IP pentadbir atau buang apabila tidak digunakan.
- Padam phpinfo.php, ping_test.php, vault_check.php dan fail diagnostic.
- Aktifkan HTTPS sebelum menggunakan Credential Vault melalui Internet.
