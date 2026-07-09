<?php
/**
 * Jumlah foto pelajar yang masih menunggu tindakan admin.
 * Digunakan oleh dashboard Zurie untuk badge notifikasi langsung.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

date_default_timezone_set('Asia/Kuala_Lumpur');

$result = [
    'ok' => false,
    'pending_count' => 0,
    'latest_uploaded_at' => null,
    'latest_uploads' => [],
];

try {
    $configFile = dirname(__DIR__) . '/config/vault_config.php';
    $config = is_file($configFile) ? require $configFile : [];

    $dsn = $config['dsn'] ?? 'mysql:host=localhost;dbname=zurie_noc;charset=utf8mb4';
    $username = $config['username'] ?? 'root';
    $password = $config['password'] ?? '';

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    $pendingWhere = "LOWER(TRIM(COALESCE(status, 'baru'))) IN
                     ('baru', 'pending_registration', 'pending', 'menunggu')";

    $summarySql = "SELECT COUNT(*) AS pending_count, MAX(uploaded_at) AS latest_uploaded_at
                   FROM student_photo_uploads
                   WHERE {$pendingWhere}";
    $row = $pdo->query($summarySql)->fetch() ?: [];

    $latestSql = "SELECT id, matrik, nama, status, uploaded_at
                  FROM student_photo_uploads
                  WHERE {$pendingWhere}
                  ORDER BY uploaded_at DESC, id DESC
                  LIMIT 5";
    $latestRows = $pdo->query($latestSql)->fetchAll() ?: [];

    $latestUploads = [];
    foreach ($latestRows as $upload) {
        $matrik = trim((string)($upload['matrik'] ?? ''));
        $nama = trim((string)($upload['nama'] ?? ''));
        if ($matrik === '' && $nama === '') {
            continue;
        }

        $uploadedAtRaw = (string)($upload['uploaded_at'] ?? '');
        $latestUploads[] = [
            'id' => max(0, (int)($upload['id'] ?? 0)),
            'matrik' => $matrik,
            'nama' => $nama !== '' ? $nama : $matrik,
            'status' => trim((string)($upload['status'] ?? 'baru')),
            'uploaded_at' => $uploadedAtRaw !== ''
                ? date('d/m/Y H:i', strtotime($uploadedAtRaw))
                : null,
            'review_url' => '/zurie/pages/upload_review.php' . ($matrik !== ''
                ? '?matrik=' . rawurlencode($matrik)
                : ''),
        ];
    }

    $result['ok'] = true;
    $result['pending_count'] = max(0, (int)($row['pending_count'] ?? 0));
    $result['latest_uploaded_at'] = !empty($row['latest_uploaded_at'])
        ? date('d/m/Y H:i', strtotime((string)$row['latest_uploaded_at']))
        : null;
    $result['latest_uploads'] = $latestUploads;
} catch (Throwable $e) {
    http_response_code(200);
    $result['error'] = 'notification_unavailable';
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
