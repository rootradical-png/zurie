<?php
declare(strict_types=1);

/**
 * CONTOH SAHAJA.
 * Salin fail ini sebagai:
 * C:\xampp_baru\htdocs\zurie\noc_map_provider.php
 *
 * Kemudian ubah bahagian connection/query mengikut fail DB dan table NOC sebenar.
 * Map tidak menyimpan IP kedua; ia membaca IP semasa daripada provider ini.
 */
return static function (array $context = []): array {
    // Contoh menggunakan PDO yang dibina di sini.
    // Lebih baik reuse fail connection ZURIE sedia ada.
    $pdo = new PDO(
        'mysql:host=127.0.0.1;dbname=zurie;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Tukar nama table/column mengikut Device Manager/NOC sebenar.
    $sql = <<<SQL
        SELECT
            id,
            device_name AS name,
            ip_address AS ip,
            category AS type,
            location,
            model,
            serial_number AS serial,
            is_active AS enabled,
            map_key
        FROM network_devices
        WHERE is_active = 1
        ORDER BY device_name
    SQL;

    return [
        'source' => 'ZURIE NOC database',
        'devices' => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC),
    ];
};
