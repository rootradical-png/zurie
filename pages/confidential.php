<?php
require_once dirname(__DIR__) . '/lib/security.php';
zurie_security_protect_sensitive_page();
$sheetUrl = 'https://docs.google.com/spreadsheets/d/18PLgcEys6P2nXfihq2hL8r-XCaeHuQwxD3hXOxIZIS8/edit#gid=886446334';
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confidential Documents | Personal NOC Dashboard</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="page-shell">
<header class="page-header">
  <div>
    <a href="../index.php" class="back-link">← Dashboard</a>
    <div class="breadcrumb-mini">🛠️ Pentadbiran / 🔒 Confidential Documents</div>
    <h1>🔒 Confidential Documents</h1>
    <p>Ruang sementara untuk dokumen penting. Aktifkan login <code>/zurie</code> sebelum digunakan secara meluas.</p>
  </div>
</header>
<section class="section-block compact-panel">
  <div class="link-grid compact-link-grid">
    <a class="quick-card" href="<?= htmlspecialchars($sheetUrl) ?>" target="_blank" rel="noopener noreferrer">
      <div class="quick-icon">📄</div><div><h3>Network Asset Master</h3><p>Google Sheet inventori network / credential terhad.</p></div>
    </a>
    <a class="quick-card" href="credential_vault.php">
      <div class="quick-icon">🔐</div><div><h3>Credential Vault</h3><p>Buka username dan password device menggunakan Master Password.</p></div>
    </a>
    <div class="quick-card muted-card"><div class="quick-icon">📦</div><div><h3>Backup Inventory</h3><p>Reserved untuk fail backup.</p></div></div>
    <div class="quick-card muted-card"><div class="quick-icon">📑</div><div><h3>SOP & Configuration</h3><p>Reserved untuk dokumen konfigurasi.</p></div></div>
  </div>
</section>
</div>
</body>
</html>
