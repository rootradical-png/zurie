<?php
/**
 * JSON ringkas untuk notifikasi foto pelajar baharu di dashboard Zurie.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth_guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

date_default_timezone_set('Asia/Kuala_Lumpur');

$result = [
    'ok' => true,
    'count' => 0,
    'latest' => null,
];

try {
    $configFile = dirname(__DIR__) . '/config/vault_config.php';
    $config = is_file($configFile) ? require $configFile : [];

    $pdo = new PDO(
        (string)($config['dsn'] ?? 'mysql:host=localhost;dbname=zurie_noc;charset=utf8mb4'),
        (string)($config['username'] ?? 'root'),
        (string)($config['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 3,
        ]
    );

    $exists = $pdo->query("SHOW TABLES LIKE 'student_photo_uploads'")->fetchColumn();
    if ($exists) {
        $result['count'] = (int)$pdo
            ->query("SELECT COUNT(*) FROM student_photo_uploads WHERE LOWER(COALESCE(status,'')) IN ('baru','pending_registration')")
            ->fetchColumn();

        if ($result['count'] > 0) {
            $stmt = $pdo->query("SELECT matrik, nama, uploaded_at
                FROM student_photo_uploads
                WHERE LOWER(COALESCE(status,'')) IN ('baru','pending_registration')
                ORDER BY uploaded_at DESC, id DESC
                LIMIT 1");
            $latest = $stmt->fetch();
            if ($latest) {
                $result['latest'] = [
                    'matrik' => (string)($latest['matrik'] ?? ''),
                    'nama' => (string)($latest['nama'] ?? ''),
                    'uploaded_at' => (string)($latest['uploaded_at'] ?? ''),
                ];
            }
        }
    }
} catch (Throwable $e) {
    // Dashboard tidak patut rosak jika DB sementara gagal.
    $result = [
        'ok' => false,
        'count' => 0,
        'latest' => null,
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
