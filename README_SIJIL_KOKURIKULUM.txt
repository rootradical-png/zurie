PATCH ZURIE - SIJIL AKUAN KOKURIKULUM

Fungsi
1. Menu baharu di Sistem Akademik > Data & Export > Sijil Akuan Kokurikulum.
2. Dropdown semua database bukan sistem yang boleh dicapai oleh user MySQL i-SIMS.
3. Search pelajar menggunakan No. Matrik atau No. KP.
4. Preview sijil sebelum jana.
5. Jana PDF individu menggunakan format viewStudentpdf.php.
6. Data dibaca terus daripada database lama. Tiada salinan data lama dibuat.
7. Sistem cuba mencari table aktiviti/sennamaakt dalam database dipilih. Jika rekod aktiviti berada dalam database lain yang boleh dicapai, sistem cuba mengesannya secara automatik.
8. Logo, cop dan tandatangan ditarik daripada server i-SIMS dan disimpan sebagai cache tempatan.

Keperluan config
- Menggunakan config sedia ada:
  C:\xampp_baru\secure\isims_mysql_config.php
- User database mesti boleh menjalankan SHOW DATABASES dan SELECT pada table:
  senarai, aktiviti, sennamaakt

Tetapan imej / maklumat sijil
- Default cuba URL:
  http://i-sims.kmp.matrik.edu.my/
  http://www.kmp.matrik.edu.my/isims/
- Jika lokasi berbeza, salin:
  config/isims_kokurikulum_config.php.example
  kepada:
  config/isims_kokurikulum_config.php
  kemudian ubah asset_base_urls atau nama fail imej.

Cara guna
1. Extract patch ke root /zurie.
2. Push Git dari PC dev.
3. Server git pull.
4. Buka:
   /zurie/pages/isims_sijil_kokurikulum.php
5. Pilih database > masukkan No. Matrik/No. KP > Cari Pelajar > Preview > Jana PDF.

Nota permission
Jika dropdown tidak memaparkan database lama, permission user MySQL belum mencukupi.
Berikan SELECT hanya kepada database lama yang diperlukan, bukan semua database sistem.
