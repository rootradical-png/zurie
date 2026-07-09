PATCH ZURIE - PHOTO AUDIT AMBANG LONGGAR 50%

Perubahan:
1. Skor background 50% dan ke atas terus DITERIMA.
2. Skor bawah 50% masuk SEMAK MANUAL.
3. DITOLAK hanya jika background sangat jelas bukan putih:
   - biru pekat,
   - warna dominan sangat kuat,
   - terlalu gelap,
   - atau terlalu bercorak/berobjek.
4. Tambah butang "Nilai Semula Semua" untuk reset keputusan Auto BG lama
   dan terus menilai semula semua gambar menggunakan ambang baharu.
5. Keputusan manual admin tidak dipadam.
6. Queue WhatsApp auto yang belum dihantar sahaja akan dibersihkan semasa nilai semula.

Fail berubah:
- lib/photo_background_quality.php
- pages/photo_audit.php

Selepas extract ke folder projek dev:
  git add lib/photo_background_quality.php pages/photo_audit.php
  git commit -m "Relax photo background threshold to 50 percent"
  git push

Di server:
  git pull

Kemudian Ctrl+F5.
