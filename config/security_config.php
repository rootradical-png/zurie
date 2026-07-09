<?php
declare(strict_types=1);

return [
    // Perlindungan umum modul sensitif.
    'enforce_private_network' => false,

    // Digunakan oleh helper lama jika masih dipanggil oleh page lain.
    'allowed_cidrs' => [
        '127.0.0.1/32',
        '::1/128',
        '10.14.0.0/16',
    ],

    // FASA 1: Hanya IP lokal KMP atau VPN KPM boleh menggunakan page ekstrak.
    // Tambah subnet VPN KPM sebenar di bawah, contohnya '10.20.0.0/16'.
'extract_allowed_cidrs' => [
    '127.0.0.1/32',
    '::1/128',
    '10.0.0.0/8',
    '1.9.210.5/32',
],

    // Jangan aktifkan kecuali portal berada di belakang reverse proxy sendiri.
    'trust_proxy_headers' => true,
    'trusted_proxy_cidrs' => [
        '10.14.48.101/32', // Reverse proxy KMP - satu-satunya proxy dipercayai
    ],

    'session_name' => 'ZURIEPORTALSESSID',
    'csrf_ttl_seconds' => 7200,
];
