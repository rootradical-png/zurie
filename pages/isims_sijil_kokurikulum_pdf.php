<?php
/** Generate one Kokurikulum certificate PDF directly from a legacy i-SIMS DB. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/portal_auth.php';
require_once dirname(__DIR__) . '/lib/isims_kokurikulum.php';
require_once dirname(__DIR__) . '/lib/kokurikulum_pdf.php';
zurie_portal_require_extract_access();

try {
    $config = ik_config();
    $pdo = ik_connect($config);
    $databases = ik_list_databases($pdo, $config);
    $database = trim((string)($_GET['db'] ?? ''));
    $matrik = strtoupper(trim((string)($_GET['matrik'] ?? '')));
    $session = trim((string)($_GET['session'] ?? ''));

    if ($database === '' || !in_array($database, $databases, true)) {
        throw new RuntimeException('Database tidak sah atau tidak boleh dicapai.');
    }
    if ($matrik === '') {
        throw new RuntimeException('No. Matrik tidak diberikan.');
    }
    if ($session === '') {
        $session = ik_infer_session($database, (string)$config['default_session']);
    }

    $student = ik_get_student($pdo, $database, $matrik);
    $activities = ik_get_activities($pdo, $databases, $database, $matrik);
    $renderer = new KokurikulumCertificateRenderer($config, $student, $activities, $session);
    $pdf = $renderer->render();
    $filename = preg_replace('/[^A-Z0-9_-]/i', '', (string)$student['matrik']) . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $pdf;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>PDF gagal</title><style>body{font-family:Arial;background:#f3f7fc;padding:30px}.box{max-width:800px;margin:auto;background:#fff;padding:20px;border-radius:14px;border:1px solid #ddd}.bad{color:#991b1b}</style><div class="box"><h2 class="bad">Sijil tidak dapat dijana</h2><p>' . ik_h($e->getMessage()) . '</p><p><a href="javascript:history.back()">← Kembali</a></p></div>';
}
