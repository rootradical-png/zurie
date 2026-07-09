<?php
/**
 * Konfigurasi sync gambar Zurie -> MIS melalui SFTP.
 * Folder /zurie/config dilindungi oleh .htaccess.
 *
 * Tukar enabled kepada true selepas semua nilai disahkan.
 */
return [
    'enabled' => true,

    // Pilihan: 'winscp' untuk Windows/XAMPP, atau 'ssh2' jika extension PHP ssh2 aktif.
    'driver' => 'winscp',

    'host' => 'mis.kmp.matrik.edu.my',
    'port' => 22,
    'username' => 'root',
	
	'host_key' => 'ssh-rsa 2048 45:61:af:26:ca:79:7f:46:32:42:4f:5c:75:f1:75:a6',

    // Pilih salah satu kaedah login.
    'password' => 'GbmqtsL2',
    'private_key' => '',
    'private_key_passphrase' => '',

    // Path sebenar pada server MIS. Sahkan dengan pentadbir MIS dahulu.
    'remote_dir' => '/usr/system/matrik/pictures/student',

    // WAJIB: fingerprint host SFTP untuk elak sambungan ke server palsu.
    // Contoh format WinSCP: ssh-ed25519 255 SHA256:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx


    // Digunakan jika driver = winscp.
    'winscp_path' => 'C:\\Program Files (x86)\\WinSCP\\WinSCP.com',

    'timeout' => 30,
];
