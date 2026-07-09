ZURIE NETWORK LIVE MAP v2 - NOC SYNC
====================================

LOKASI PEMASANGAN
-----------------
Ekstrak folder map ke:
C:\xampp_baru\htdocs\zurie\map\

Buka:
http://localhost/zurie/map/

DIAGNOSTIK SYNC
---------------
http://localhost/zurie/map/sync_diagnostic.php

REKA BENTUK SYNC
----------------
1. Device Manager/NOC menjadi SUMBER UTAMA nama, IP, kategori, lokasi,
   model, serial dan status aktif.
2. data/devices.json menyimpan susun atur visual sahaja:
   map ID, x/y, kawasan dan hubungan line.
3. Worker ping membaca topology yang telah digabungkan dengan NOC.
4. Apabila IP diubah dalam NOC, worker berikutnya terus ping IP baharu.
5. Kedudukan peranti dan semua line tidak berubah kerana ia menggunakan
   map ID yang stabil, bukan IP sebagai ID paparan.

BINDING AUTOMATIK
-----------------
Sistem cuba memadankan peranti dengan susunan berikut:
- Binding yang pernah disimpan
- noc_id
- noc_ref / map_key
- map ID kepada map_key
- IP asal (padanan kali pertama)
- Nama + kategori
- Nama

Selepas berjaya, padanan stabil disimpan dalam:
data\noc_bindings.json

Jadi, perubahan IP selepas itu tidak memutuskan padanan map.

SAMBUNG KEPADA NOC SEBENAR
--------------------------
Map mencari provider berikut secara automatik:
1. C:\xampp_baru\htdocs\zurie\noc_map_provider.php
2. C:\xampp_baru\htdocs\zurie\includes\noc_map_provider.php
3. C:\xampp_baru\htdocs\zurie\device_manager\noc_map_provider.php

Contoh provider disediakan dalam:
integration\noc_map_provider.example.php

Provider perlu return sekurang-kurangnya:
- id / device_id
- name / device_name
- ip / ip_address

Field tambahan yang disokong:
- map_key
- type / category
- location
- model
- serial / serial_number
- enabled / is_active

Tanpa provider, map masih berjalan menggunakan devices.json dan memaparkan:
NOC: LOCAL FALLBACK

WORKER PING
-----------
Jalankan sekali untuk ujian:
C:\xampp_baru\php\php.exe C:\xampp_baru\htdocs\zurie\map\worker\ping_worker.php

Windows Task Scheduler:
Program:
C:\xampp_baru\php\php.exe

Arguments:
C:\xampp_baru\htdocs\zurie\map\worker\ping_worker.php

Start in:
C:\xampp_baru\htdocs\zurie\map

NOTA PENTING
------------
Provider contoh masih perlu disesuaikan dengan nama table dan column
Device Manager/NOC sebenar. Selepas fail penuh ZURIE diperiksa, provider
boleh diikat terus kepada connection dan table sedia ada tanpa salinan IP.


PATCH v2.1 - GREY ANIMATION
---------------------------
- Link berstatus unknown kini bergerak perlahan.
- Warna kelabu lembut dan tanpa glow terang.
- Status offline kekal merah supaya alert mudah dikenal pasti.


PATCH v2.2 - COMPACT UI + PING DAEMON
------------------------------------
Paparan:
- Header, toolbar, kad sisi dan ruang map dikecilkan sedikit.
- Keseluruhan topology dikecilkan kira-kira 9% melalui viewBox.
- Line lebih nipis dan kurang striking.

PING BERJALAN SENTIASA (CADANGAN)
---------------------------------
Cara paling stabil ialah Windows Task Scheduler menjalankan:

C:\xampp_baru\php\php.exe
C:\xampp_baru\htdocs\zurie\map\worker\ping_daemon.php

Pemasangan mudah:
1. Pastikan folder map telah disalin ke /zurie/map.
2. Klik kanan Windows PowerShell dan pilih Run as Administrator.
3. Jalankan:
   powershell -ExecutionPolicy Bypass -File "C:\xampp_baru\htdocs\zurie\map\install_ping_daemon_task.ps1"
4. Task bermula ketika Windows/server boot dan restart jika gagal.
5. Tetapan Task Scheduler menggunakan "IgnoreNew" supaya tidak wujud salinan berganda.

Ujian tanpa Task Scheduler:
- Double-click run_ping_daemon.bat
- Biarkan tetingkap terbuka.
- Untuk berhenti, tutup tetingkap atau jalankan stop_ping_daemon.bat.

INTERVAL
--------
Ubah dalam config.php:
'worker_interval_seconds' => 10,

Nilai minimum daemon ialah 5 saat. Cadangan production ialah 10 hingga 30 saat.
Worker dan daemon mempunyai lock file bagi mengelakkan proses bertindih.


PATCH v2.3 - NAVIGASI ZURIE
---------------------------
- Tambah butang "Menu Utama" pada header map.
- Tambah butang "Dashboard" untuk kembali ke dashboard ZURIE.
- URL boleh diubah dalam config.php pada bahagian navigation.
- Snippet item menu disediakan di integration/main_menu_link.php.
- Arahan ringkas disediakan dalam MENU_INTEGRATION.txt.
