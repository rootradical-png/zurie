PATCH V4 SIJIL KOKURIKULUM

1. Betulkan URL logo/cop/tandatangan supaya tidak menjadi /image/image/.
2. Guna URL penuh dari server i-SIMS.
3. Tambah fallback untuk config lama yang masih menggunakan image/namafail.
4. Tukar ruangan Sesi kepada dropdown.
5. Pilihan sesi dijana dari 2013/2014 hingga tahun semasa + 1.

Selepas git pull di server, buang cache:
del /Q data\kokurikulum_assets\*.*
Kemudian restart Apache dan Ctrl+F5.
