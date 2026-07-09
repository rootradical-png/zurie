<?php
// Konfigurasi khas untuk server MBS/MRBS.
// Tukar host kepada IP server MBS sebenar, contohnya 10.x.x.x.
// Fail ini berasingan daripada isims_mysql_config.php.
return [
    'enabled' => true,
    'host' => '',              // CONTOH: 10.10.20.30
    'port' => 3306,
    'dbname' => 'TimetableMBS',
    'user' => '',              // Pengguna MySQL pada server MBS
    'password' => '',          // Kata laluan MySQL server MBS
    'charset' => 'utf8mb4',
    'entry_table' => 'mrbs_entry',
    'room_table' => 'mrbs_room',
    'timezone' => 'Asia/Kuala_Lumpur',
];
