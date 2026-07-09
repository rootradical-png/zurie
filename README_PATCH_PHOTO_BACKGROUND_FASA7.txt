PATCH PHOTO AUDIT FASA 7 - AUTO BACKGROUND DETECTION

Fail berubah:
1. pages/photo_audit.php
2. lib/photo_background_quality.php (baharu)
3. sql/photo_background_fasa7.sql (rujukan sahaja)

Fungsi:
- Butang Semak BG untuk satu gambar.
- Semak BG Terpilih maksimum 20 gambar.
- Kesan background putih, hampir putih, biru/berwarna, gelap, bayang dan tidak seragam.
- Simpan skor putih, keseragaman, warna dominan dan sebab cadangan.
- Jika rekod belum dinilai:
  * Putih/Hampir Putih -> auto Gambar Baik.
  * Background jelas tidak sesuai -> auto Upload Baru.
  * Kes meragukan -> kekal untuk semakan manual.
- Penilaian manual sedia ada tidak ditimpa.

Keperluan server:
- PHP GD mesti aktif. Semak melalui phpinfo() atau php -m | findstr /I gd
- Page akan menambah column database secara automatik. SQL disediakan sebagai rujukan/manual fallback.

Nota:
- Analisis dibuat pada kawasan tepi/atas gambar pasport dan tidak menghantar gambar ke servis luar.
- Ini ialah bantuan automatik. Admin masih boleh override menggunakan butang Baik, Repair atau Upload Baru.
