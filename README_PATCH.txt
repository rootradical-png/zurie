PATCH: Arkib & Padam Gambar Lama SFTP

Fail diubah:
- pages/photo_versions.php
- lib/mis_sftp.php
- .gitignore

Fail baharu:
- archive/sftp_photos/.htaccess
- archive/sftp_photos/README.txt

Cara kerja:
1. Pilih gambar utama dan jadikan NOMATRIK.jpg.
2. Semak senarai calon padam.
3. Klik "Arkib & Padam".
4. Setiap fail dimuat turun ke /zurie/archive/sftp_photos/YYYY/MM/DD/NOMATRIK/ dahulu.
5. Hanya selepas arkib berjaya, fail remote dipadam dari SFTP.
6. Jika ada kegagalan, status menjadi "ARKIB/PADAM SEPARA" dan hanya fail gagal kekal untuk dicuba semula.
7. Log JSONL disimpan dalam folder archive/sftp_photos/logs/.

Keperluan:
- Akaun SFTP mesti mempunyai permission read/download dan delete.
- Folder /zurie/archive/sftp_photos mesti boleh ditulis oleh Apache/PHP.
- .htaccess mesti dibenarkan oleh konfigurasi Apache untuk menyekat akses web.
