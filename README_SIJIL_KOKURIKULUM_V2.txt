PATCH V2 - SIJIL AKUAN KOKURIKULUM (DROPDOWN DINAMIK + AKSES)

Perubahan
1. Dropdown database ditarik terus daripada server i-SIMS pada setiap page load.
2. Hanya database pelajar dipaparkan:
   - db_pelajarkmp
   - _pelajarkmp
   - _pelajarkmpYYYY
3. Tahun baharu akan muncul automatik apabila akaun MySQL menerima SELECT.
4. Susunan: data semasa dahulu, kemudian tahun terbaru ke tahun lama.
5. Database aktiviti seperti "db" tidak dipaparkan dalam dropdown tetapi masih
   diperiksa secara automatik untuk table aktiviti dan sennamaakt.
6. Page memaparkan akaun MySQL sebenar (CURRENT_USER) untuk memudahkan grant.
7. SQL baca-sahaja disediakan di sql/isims_kokurikulum_grant.sql.

Cara pasang
- Extract ke root /zurie dan replace fail.
- PC dev: git add . && git commit -m "Dynamic legacy database dropdown for kokurikulum" && git push
- Server: git pull
- Jalankan SQL grant sebagai root/admin phpMyAdmin.

Keselamatan
- Tidak memberi global SELECT.
- Tidak memberi hak ubah/padam database.
- Tidak perlu GRANT SHOW DATABASES global.
