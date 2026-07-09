PATCH: Semakan Versi Gambar SFTP MIS

Fungsi:
1. Scan semua gambar dalam folder SFTP MIS.
2. Kumpulkan fail mengikut No. Matrik walaupun nama/format berbeza.
3. Papar semua versi gambar dan pilih satu gambar terbaik.
4. Gambar pilihan ditukar kepada JPG 413x531 dan disimpan sebagai NOMATRIK.jpg.
5. Fail lain tidak dipadam secara automatik; ia dimasukkan ke laporan CSV calon padam SFTP.
6. Ada status Menunggu Padam dan tindakan tandakan pembersihan selesai.

Selepas extract ke folder projek:
- Pastikan PHP GD aktif.
- Pastikan konfigurasi SFTP sedia.
- Buka: /zurie/pages/photo_versions.php
- Klik Scan SFTP.

Jika akaun MySQL tidak boleh CREATE TABLE, jalankan:
sql/photo_versions_sftp.sql

Git PC dev:
git add .
git commit -m "Add SFTP photo version review"
git push

Server:
git pull
