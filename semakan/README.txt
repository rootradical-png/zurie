MODUL SEMAKAN KELAYAKAN BSHP
================================

Lokasi pemasangan:
C:\xampp_baru\htdocs\zurie\semakan\

URL:
http://localhost/zurie/semakan/
atau
http://www.kmp.matrik.edu.my/zurie/semakan/

Kandungan:
- index.php               Halaman semakan pelajar
- data/kelayakan.php      1,156 rekod daripada helaian pertama Excel
- .htaccess               Sekat directory listing dan fail data sensitif
- data/.htaccess          Sekat capaian terus ke data

Fungsi:
1. Pelajar perlu masukkan No. Matrik dan No. Kad Pengenalan.
2. Kedua-dua maklumat mesti sepadan.
3. Paparan keputusan: LAYAK atau TIDAK LAYAK.
4. Untuk TIDAK LAYAK, Maklumat Tambahan dipaparkan di bawah keputusan.
5. No. KP disimpan sebagai bcrypt hash dan tidak disimpan dalam teks biasa.
6. Cubaan salah berulang dikunci sementara.

Pautan menu/card yang boleh ditambah dalam dashboard ZURIE:
<a href="/zurie/semakan/">Semakan Kelayakan BSHP</a>

Nota:
- Folder ini berdiri sendiri dan tidak memerlukan jadual MySQL baharu.
- Sumber data: SEMAKAN KELAYAKAN BSHP(1).xlsx, helaian pertama "14-KMP (57)".
- Jumlah rekod: 1,156 (1,128 LAYAK, 28 TIDAK LAYAK).
