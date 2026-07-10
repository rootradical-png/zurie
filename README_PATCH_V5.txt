PATCH V5 — SIJIL AKUAN KOKURIKULUM

Perubahan:
1. Logo/cop/tandatangan cuba dimuat turun melalui URL domain i-SIMS.
2. Jika DNS server Zurie gagal, sistem cuba terus IP 10.14.48.80 dengan Host header.
3. Format imej disahkan menggunakan getimagesize dan disimpan sebagai PNG/JPG sebenar.
4. Preview browser menggunakan URL asal sebagai fallback walaupun cache PHP gagal.
5. PDF kekal menggunakan fail cache tempatan supaya FPDF stabil.
6. Sesi menggunakan dropdown dan dipilih automatik berdasarkan tahun database.
7. Nama database dipaparkan bersama label tahun.

Selepas extract/commit/pull:
- Padam cache lama:
  del /Q data\kokurikulum_assets\*.*
- Restart Apache.
- Tekan Ctrl+F5.

URL aset:
http://i-sims.kmp.matrik.edu.my/esasi/image/logokpm.png
http://i-sims.kmp.matrik.edu.my/esasi/image/logokmp.jpg
http://i-sims.kmp.matrik.edu.my/esasi/image/coplogokmp.png
http://i-sims.kmp.matrik.edu.my/esasi/image/signpeng.png
