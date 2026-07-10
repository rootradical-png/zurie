PATCH SIJIL AKUAN KOKURIKULUM — SEMUA DB + GAMBAR PELAJAR

Fail diubah:
1. lib/isims_kokurikulum.php
2. pages/isims_sijil_kokurikulum.php
3. lib/kokurikulum_pdf.php
4. config/isims_kokurikulum_config.php.example

Perubahan:
- Dropdown memaparkan SEMUA database bukan sistem yang boleh dicapai oleh akaun MySQL aplikasi.
- Gambar pelajar dicari dari:
  http://i-sims.kmp.matrik.edu.my/esasi/image/
- Sistem cuba nama fail berdasarkan No. Matrik dan No. KP dalam JPG/JPEG/PNG.
- Gambar dipaparkan pada preview dan PDF.
- Gambar yang berjaya dimuat turun dicache dalam data/kokurikulum_student_photos.

PENTING:
Jika database masih tidak muncul, database itu memang belum boleh dilihat oleh akaun yang digunakan dalam:
C:\xampp_baru\secure\isims_mysql_config.php

Semak akaun sebenar melalui label "Akaun DB" pada halaman dan beri GRANT SELECT kepada akaun/host yang tepat.
