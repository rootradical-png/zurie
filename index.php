<?php
// Personal NOC Dashboard - KMP
// Letak semua fail terus dalam: /zurie/

$pingPanelUrl = 'http://10.14.49.10:8888/';
$wifiControllerUrl = 'https://10.14.49.10:8443/manage/default/devices';
$legacyControllerUrl = 'https://10.14.49.200:8443/manage/default/devices';
$jamPspmUrl = 'http://www.kmp.matrik.edu.my/jam/';
$moversUrl = 'http://www.kmp.matrik.edu.my/mover/web/%20MOVERs:%20Matrix%20Online%20Vehicle%20Entry%20Reservation%20System';
$esijilUrl = 'http://www.kmp.matrik.edu.my/esijil/';
$networkSheetUrl = 'https://docs.google.com/spreadsheets/d/18PLgcEys6P2nXfihq2hL8r-XCaeHuQwxD3hXOxIZIS8/edit#gid=886446334';

$nocDevices = file_exists(__DIR__ . '/data/noc_devices.php')
    ? include __DIR__ . '/data/noc_devices.php'
    : [];

$deviceTotals = ['Switch' => 0, 'Server' => 0, 'Service' => 0, 'AP' => 0];
foreach ($nocDevices as $device) {
    $type = $device['type'] ?? 'Other';
    if (isset($deviceTotals[$type])) {
        $deviceTotals[$type]++;
    }
}
$totalDevices = array_sum($deviceTotals);

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
$isGuest = function_exists('zurie_is_guest') && zurie_is_guest();
$displayName = (string)($_SESSION['portal_display_name'] ?? ($isGuest ? 'Guest Monitoring' : 'ZURIE'));
$displayRole = $isGuest ? 'Guest | Read-only Monitoring' : 'ICT | KMP';
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#07111f">
    <title>Personal NOC Dashboard</title>
    <link rel="icon" href="/zurie/image/zuriex.jpg">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/noc-dashboard.css?v=20260714-menu1">
    <link rel="stylesheet" href="assets/css/live-ping.css?v=20260622-compactdetail1">
    <link rel="stylesheet" href="assets/css/dashboard-server-detail.css?v=20260623-mispinned1">
    <link rel="stylesheet" href="assets/css/dashboard-polish.css?v=20260624-overviewfull1">
    <link rel="stylesheet" href="assets/css/profile-logout.css?v=20260624-2">
    <link rel="stylesheet" href="assets/css/navigation-fix.css?v=20260624-1">
    <style>
        /*
         * Sidebar kiri ialah SATU-SATUNYA kawasan scroll.
         * Tiada lagi scroll berasingan pada <nav> menu.
         */
        .noc-dashboard-page .noc-sidebar {
            overflow-y: auto !important;
            overflow-x: hidden !important;
            overscroll-behavior-y: contain;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: transparent transparent;
        }

        /* Scrollbar sidebar tidak mengganggu paparan; muncul apabila sidebar disentuh/di-hover. */
        .noc-dashboard-page .noc-sidebar:hover {
            scrollbar-color: rgba(148, 163, 184, .55) transparent;
        }

        .noc-dashboard-page .noc-sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .noc-dashboard-page .noc-sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        .noc-dashboard-page .noc-sidebar::-webkit-scrollbar-thumb {
            background: transparent;
            border-radius: 10px;
        }

        .noc-dashboard-page .noc-sidebar:hover::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, .55);
        }

        .noc-dashboard-page .noc-menu {
            flex: 0 0 auto !important;
            min-height: max-content !important;
            overflow: visible !important;
            overscroll-behavior: auto;
            padding-bottom: 8px;
        }

        .noc-dashboard-page .noc-menu .menu-group {
            margin-bottom: 2px;
        }

        .noc-dashboard-page .noc-menu .menu-title {
            min-height: 34px;
            padding-top: 7px;
            padding-bottom: 7px;
        }

        .noc-dashboard-page .noc-menu .submenu-link {
            padding-top: 6px;
            padding-bottom: 6px;
        }

        .noc-dashboard-page .submenu-section-label {
            display: block;
            padding: 8px 14px 4px 43px;
            font-size: 10px;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: rgba(203, 213, 225, .58);
            pointer-events: none;
        }

        .noc-dashboard-page .sidebar-collapse-btn,
        .noc-dashboard-page .noc-sidebar-footer {
            flex: 0 0 auto !important;
        }

        .noc-dashboard-page .noc-sidebar-footer {
            margin-top: 8px;
        }


        /* Notifikasi khas foto pelajar; berasingan daripada alert peranti NOC. */
        .photo-notification-button {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .photo-notification-button .photo-notification-count,
        .photo-menu-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: #ef4444;
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            line-height: 1;
            box-sizing: border-box;
        }

        .photo-notification-button .photo-notification-count {
            position: absolute;
            top: -5px;
            right: -6px;
            box-shadow: 0 0 0 2px #07111f;
        }

        .photo-menu-badge {
            margin-left: auto;
            flex: 0 0 auto;
        }

        .photo-notification-count[hidden],
        .photo-menu-badge[hidden] {
            display: none !important;
        }


        /* Notifikasi foto ialah bar kecil sendiri di atas alert peranti. */
        .photo-upload-alert-bar {
            display: flex;
            align-items: center;
            gap: 7px;
            min-height: 28px;
            margin: 8px 12px 4px;
            padding: 5px 10px;
            border: 1px solid rgba(34, 211, 238, .22);
            border-radius: 8px;
            background: rgba(8, 47, 73, .58);
            color: #e2e8f0;
            font-size: 11px;
            line-height: 1.25;
            text-decoration: none;
            box-sizing: border-box;
        }

        .photo-upload-alert-bar[hidden] {
            display: none !important;
        }

        .photo-upload-alert-icon {
            flex: 0 0 auto;
            color: #67e8f9;
            font-size: 12px;
        }

        .photo-upload-alert-text {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .photo-upload-alert-text b {
            color: #a5f3fc;
        }

        .photo-upload-alert-action {
            flex: 0 0 auto;
            margin-left: auto;
            color: #67e8f9;
            font-size: 10px;
            font-style: normal;
            font-weight: 800;
            white-space: nowrap;
            animation: photoReviewAttention 1.9s ease-in-out infinite;
        }

        @keyframes photoReviewAttention {
            0%, 100% {
                opacity: .72;
                text-shadow: none;
            }
            50% {
                opacity: 1;
                text-shadow: 0 0 7px rgba(103, 232, 249, .55);
            }
        }

        .photo-upload-alert-bar:hover .photo-upload-alert-action {
            animation-play-state: paused;
            opacity: 1;
        }

        @media (prefers-reduced-motion: reduce) {
            .photo-upload-alert-action {
                animation: none;
                opacity: 1;
            }
        }

        .photo-upload-alert-bar:hover {
            border-color: rgba(34, 211, 238, .42);
            background: rgba(8, 47, 73, .78);
        }

        @media (max-width: 700px) {
            .photo-upload-alert-bar {
                margin-left: 8px;
                margin-right: 8px;
            }

            .photo-upload-alert-action {
                display: none;
            }
        }
    </style>
</head>
<body class="noc-dashboard-page">
<div class="app-shell noc-shell">
    <aside class="sidebar noc-sidebar" id="nocSidebar">
        <div class="brand noc-brand">
            <div class="brand-logo brand-photo">
                <img src="/zurie/image/zuriex.jpg" alt="Zurie">
            </div>
            <div class="brand-copy">
                <h1>Personal NOC Dashboard</h1>
                <p>KMP Operations Center (KOC)</p>
            </div>
            <button class="sidebar-mobile-close" id="sidebarMobileClose" type="button" aria-label="Tutup menu">×</button>
        </div>
        <nav class="side-menu noc-menu" aria-label="Menu utama">
            <a class="active main-nav-link" href="/zurie/index.php"><span class="nav-icon">⌂</span><span class="nav-label">Dashboard</span></a>

            <div class="menu-group" data-menu-group="monitoring">
                <button class="menu-title" type="button"><span><i class="nav-icon">⌁</i><span class="nav-label">Monitoring</span></span></button>
                <div class="submenu-wrap">
                    <a class="submenu-link" href="/zurie/map/">Network Map</a>
                    <a class="submenu-link" href="/zurie/pages/live_ping.php">Live Ping Favorites</a>
                    <a class="submenu-link" href="/zurie/pages/server_metrics.php">Server Health Metrics</a>
                    <?php if (!$isGuest): ?><a class="submenu-link" href="https://analytics.google.com/analytics/web/#/a128495109p451384878/realtime/overview?params=_u..nav%3Dmaui" target="_blank" rel="noopener noreferrer">Google Analytics Realtime</a><?php endif; ?>
                </div>
            </div>

            <?php if (!$isGuest): ?>
            <div class="menu-group" data-menu-group="network-devices">
                <button class="menu-title" type="button"><span><i class="nav-icon">▣</i><span class="nav-label">Rangkaian &amp; Peranti</span></span></button>
                <div class="submenu-wrap">
                    <span class="submenu-section-label">Peranti</span>
                    <a class="submenu-link" href="/zurie/pages/switch.php">Switch</a>
                    <a class="submenu-link" href="/zurie/pages/server.php">Server</a>
                    <a class="submenu-link" href="/zurie/pages/access_point.php">Access Point (AP)</a>
                    <a class="submenu-link" href="/zurie/pages/network_services.php">Network Services</a>
                    <a class="submenu-link" href="/zurie/pages/device_manager.php">Device Manager</a>
                    <span class="submenu-section-label">WiFi &amp; Controller</span>
                    <a class="submenu-link" href="<?= e($wifiControllerUrl) ?>" target="_blank" rel="noopener noreferrer">WiFi Controller Semasa</a>
                    <a class="submenu-link" href="<?= e($legacyControllerUrl) ?>" target="_blank" rel="noopener noreferrer">Legacy Controller 6.5.55</a>
                    <a class="submenu-link" href="/zurie/pages/legacy_ap_access.php">Legacy AP Access</a>
                    <a class="submenu-link" href="/zurie/pages/ubiquiti_ap_access.php">Ubiquiti AP Access</a>
                </div>
            </div>

            <?php endif; ?>

            <?php if (!$isGuest): ?>
            <div class="menu-group" data-menu-group="academic-systems">
                <button class="menu-title" type="button"><span><i class="nav-icon">▧</i><span class="nav-label">Sistem Akademik</span></span></button>
                <div class="submenu-wrap">
                    <span class="submenu-section-label">Portal</span>
                    <a class="submenu-link" href="http://i-sims.kmp.matrik.edu.my" target="_blank" rel="noopener noreferrer">Portal i-SIMS</a>
                    <a class="submenu-link" href="http://i-sims.kmp.matrik.edu.my/pelajar/" target="_blank" rel="noopener noreferrer">i-SIMS Pelajar</a>
                    <a class="submenu-link" href="http://mis.kmp.matrik.edu.my" target="_blank" rel="noopener noreferrer">MIS</a>
                    <a class="submenu-link" href="http://portal.kmp.matrik.edu.my" target="_blank" rel="noopener noreferrer">Portal eLearning</a>
                    <span class="submenu-section-label">Data &amp; Export</span>
                    <a class="submenu-link" data-force-nav href="/zurie/pages/isims_extract.php" target="_self">senarai_mis_lengkap</a>
                    <a class="submenu-link" data-force-nav href="/zurie/pages/isims_senarai.php" target="_self">senarai</a>
                    <a class="submenu-link" data-force-nav href="/zurie/pages/isims_rawatan_review.php" target="_self">Rawatan - Semak/Padam No Siri</a>
                    <a class="submenu-link" data-force-nav href="/zurie/pages/isims_sijil_kokurikulum.php" target="_self">Sijil Akuan Kokurikulum</a>
                    <a class="submenu-link" data-force-nav href="/zurie/pages/ilmu_export.php" target="_self">ILMU GL14 Export</a>
                    <a class="submenu-link" data-force-nav href="/zurie/pages/ms365_export.php" target="_self">MS 365 Student Export</a>
                    <a class="submenu-link" data-force-nav href="/zurie/pages/delima_sync.php" target="_self" title="Upload CSV akaun DELIMa, tukar format dan sync ke table delima">DELIMa</a>
                </div>
            </div>

            <?php endif; ?>

            <?php if (!$isGuest): ?>
            <div class="menu-group" data-menu-group="upload-centre">
                <button class="menu-title" type="button"><span><i class="nav-icon">▤</i><span class="nav-label">Foto Pelajar</span></span><span id="photoMenuBadge" class="photo-menu-badge" hidden>0</span></button>
                <div class="submenu-wrap">
                    <a class="submenu-link" href="/zurie/upload/" target="_blank" rel="noopener noreferrer" title="Borang awam untuk pelajar sahkan identiti dan muat naik foto">Borang Upload Pelajar</a>
                    <a class="submenu-link" href="/zurie/pages/photo_audit.php" title="Semak kewujudan dan kualiti gambar MIS, tandakan repair atau hantar WhatsApp">Audit Gambar MIS</a>
                    <a class="submenu-link" id="photoReviewLink" href="/zurie/pages/upload_review.php" title="Semak gambar asal dan repaired, lulus, tolak atau sync ke MIS">Semakan, Repair &amp; Sync</a>
                    <a class="submenu-link" href="/zurie/pages/mis_sftp_setup.php" title="Uji konfigurasi dan sambungan SFTP dari NOC ke MIS">Tetapan SFTP MIS</a>
                    <a class="submenu-link" href="/zurie/pages/pg_live_lookup_setup.php" title="Uji semakan langsung PostgreSQL untuk pelajar yang belum masuk MySQL senarai">Semakan PostgreSQL Live</a>
                </div>
            </div>

            <?php endif; ?>

            <?php if (!$isGuest): ?>
            <div class="menu-group" data-menu-group="apps-projects">
                <button class="menu-title" type="button"><span><i class="nav-icon">◫</i><span class="nav-label">Aplikasi &amp; Projek</span></span></button>
                <div class="submenu-wrap">
                    <a class="submenu-link" href="http://www.kmp.matrik.edu.my/book" target="_blank" rel="noopener noreferrer">MBS Tempahan Bilik</a>
                    <a class="submenu-link" href="<?= e($moversUrl) ?>" target="_blank" rel="noopener noreferrer">MOVERs</a>
                    <a class="submenu-link" href="<?= e($esijilUrl) ?>"<?= $esijilUrl !== '#' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>e-Sijil PRO</a>
                    <a class="submenu-link" href="<?= e($jamPspmUrl) ?>" target="_blank" rel="noopener noreferrer">Jam PSPM</a>
                </div>
            </div>

            <?php endif; ?>

            <?php if (!$isGuest): ?>
            <div class="menu-group" data-menu-group="admin-documents">
                <button class="menu-title" type="button"><span><i class="nav-icon">♙</i><span class="nav-label">Pentadbiran &amp; Dokumen</span></span></button>
                <div class="submenu-wrap">
                    <span class="submenu-section-label">Pentadbiran</span>
                    <a class="submenu-link" href="/zurie/pages/credential_vault.php">Credential Vault</a>
                    <a class="submenu-link" href="/zurie/pages/quick_links.php">Semua Pautan</a>
                    <a class="submenu-link" href="/zurie/pages/utilities.php">Utilities / Backup</a>
                    <span class="submenu-section-label">Dokumen &amp; Drive</span>
                    <a class="submenu-link" href="<?= e($networkSheetUrl) ?>" target="_blank" rel="noopener noreferrer">Network Asset Sheet</a>
                    <a class="submenu-link" href="/zurie/pages/confidential.php">Confidential Documents</a>
                </div>
            </div>
            <?php endif; ?>
        </nav>

        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" type="button">
            <span>«</span><span class="nav-label">Collapse</span>
        </button>

        <div class="noc-sidebar-footer">
            <strong>Personal NOC Dashboard</strong>
            <span>Version 1.0</span>
            <span>Developed by Zurie</span>
            <span class="system-state"><i></i><b id="sidebarSystemState">Checking system</b></span>
        </div>
    </aside>

    <div class="mobile-overlay" id="mobileOverlay"></div>

    <main class="main-content noc-main">
        <header class="noc-topbar">
            <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" aria-label="Buka menu">☰</button>
            <div class="topbar-status-chip">
                <span class="clock-icon">◷</span>
                <b id="dateNow">--</b>
                <i></i>
                <strong id="clockNow">--:--:--</strong>
                <i></i>
                <span>Last refresh: <b id="topLastRefresh">Belum disemak</b></span>
                <em id="topStatusDot"></em>
            </div>
            <div class="topbar-actions">
                <button class="top-icon-button" id="topRefreshBtn" type="button" title="Refresh">↻</button>
                <button class="top-icon-button notification-button" type="button" title="Alert rangkaian">
                    ♧<span id="notificationCount">0</span>
                </button>
                <?php if (!$isGuest): ?>
                <a class="top-icon-button photo-notification-button" id="photoNotificationButton" href="/zurie/pages/upload_review.php" title="Tiada foto pelajar menunggu semakan" aria-label="Notifikasi foto pelajar">
                    ▤<span id="photoNotificationCount" class="photo-notification-count" hidden>0</span>
                </a>
                <?php endif; ?>
                <button class="top-icon-button" id="displayModeBtn" type="button" title="Mod paparan">☾</button>
                <div class="profile-menu-wrap" data-profile-menu>
                    <button class="profile-chip profile-menu-trigger" type="button" data-profile-trigger aria-haspopup="true" aria-expanded="false">
                        <img src="/zurie/image/zuriex.jpg" alt="Zurie">
                        <span><b><?= e($displayName) ?></b><small><?= e($displayRole) ?></small></span>
                        <i aria-hidden="true">⌄</i>
                    </button>
                    <div class="profile-dropdown" data-profile-dropdown hidden>
                        <div class="profile-dropdown-head">
                            <img src="/zurie/image/zuriex.jpg" alt="Zurie">
                            <span><b><?= e($displayName) ?></b><small><?= e($displayRole) ?></small></span>
                        </div>
                        <a class="profile-dropdown-logout" href="/zurie/logout.php">
                            <span>↪</span>
                            <b>Logout</b>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <?php if (!$isGuest): ?>
        <a id="photoUploadAlertBar" class="photo-upload-alert-bar" href="/zurie/pages/upload_review.php" hidden aria-live="polite">
            <span class="photo-upload-alert-icon" aria-hidden="true">▤</span>
            <span class="photo-upload-alert-text"><b>Foto baru:</b> <span id="photoUploadAlertStudent">Menunggu semakan</span></span>
            <em class="photo-upload-alert-action">Semak →</em>
        </a>
        <?php endif; ?>

        <section id="smartAlertBar" class="smart-alert-bar is-loading" aria-live="polite">
            <span class="alert-symbol">!</span>
            <strong id="smartAlertText">Sedang menyemak status peranti...</strong>
            <a id="smartAlertLink" href="#alert-panel">Lihat Peranti <span>→</span></a>
            <button id="dismissAlertBtn" type="button" aria-label="Tutup">×</button>
        </section>

        <section class="dashboard-overview-grid" aria-label="Ringkasan rangkaian dan alert terkini">
            <div class="overview-device-zone">
                <div class="overview-section-heading">
                    <div>
                        <h3>JENIS DEVICE <span>(LIVE)</span></h3>
                        <small>Status semasa Switch, Server, Network Services dan Access Point</small>
                    </div>
                    <?php if (!$isGuest): ?><a href="/zurie/pages/device_manager.php">Semua Device <span>→</span></a><?php else: ?><a href="/zurie/pages/live_ping.php">Monitoring <span>→</span></a><?php endif; ?>
                </div>
            <section id="noc-status" class="dashboard-gauge-grid">
                <a class="premium-gauge-card switch-card" data-noc-card="Switch" href="<?= $isGuest ? '/zurie/pages/live_ping.php' : '/zurie/pages/switch.php' ?>">
                    <div class="gauge-card-head">
                        <span class="device-icon">▣</span><b>SWITCH</b><small>LIVE</small>
                    </div>
                    <div class="premium-gauge">
                        <svg viewBox="0 0 220 125" role="img" aria-label="Switch uptime">
                            <defs><linearGradient id="switchGradient" x1="0" x2="1"><stop offset="0%" stop-color="#32d26b"/><stop offset="72%" stop-color="#60df6f"/><stop offset="84%" stop-color="#f8b52e"/><stop offset="100%" stop-color="#ef4c4c"/></linearGradient></defs>
                            <path class="premium-gauge-track" d="M22 108 A88 88 0 0 1 198 108"></path>
                            <path class="premium-gauge-colour" d="M22 108 A88 88 0 0 1 198 108" stroke="url(#switchGradient)"></path>
                            <line class="premium-gauge-needle" x1="110" y1="108" x2="110" y2="38"></line>
                            <circle class="premium-gauge-pin" cx="110" cy="108" r="6"></circle>
                            <text x="20" y="123">0</text><text x="105" y="25">50</text><text x="190" y="123">100</text>
                        </svg>
                        <b class="noc-pct">0%</b>
                    </div>
                    <div class="gauge-live-numbers"><span><b class="noc-up">0</b> UP</span><i>/</i><span><b class="noc-down">0</b> DOWN</span></div>
                    <small>Total: <b class="noc-total"><?= (int)$deviceTotals['Switch'] ?></b></small>
                </a>

                <a class="premium-gauge-card server-card" data-noc-card="Server" href="<?= $isGuest ? '/zurie/pages/live_ping.php' : '/zurie/pages/server.php' ?>">
                    <div class="gauge-card-head">
                        <span class="device-icon">▤</span><b>SERVER</b><small>LIVE</small>
                    </div>
                    <div class="premium-gauge">
                        <svg viewBox="0 0 220 125" role="img" aria-label="Server uptime">
                            <defs><linearGradient id="serverGradient" x1="0" x2="1"><stop offset="0%" stop-color="#32d26b"/><stop offset="72%" stop-color="#60df6f"/><stop offset="84%" stop-color="#f8b52e"/><stop offset="100%" stop-color="#ef4c4c"/></linearGradient></defs>
                            <path class="premium-gauge-track" d="M22 108 A88 88 0 0 1 198 108"></path>
                            <path class="premium-gauge-colour" d="M22 108 A88 88 0 0 1 198 108" stroke="url(#serverGradient)"></path>
                            <line class="premium-gauge-needle" x1="110" y1="108" x2="110" y2="38"></line>
                            <circle class="premium-gauge-pin" cx="110" cy="108" r="6"></circle>
                            <text x="20" y="123">0</text><text x="105" y="25">50</text><text x="190" y="123">100</text>
                        </svg>
                        <b class="noc-pct">0%</b>
                    </div>
                    <div class="gauge-live-numbers"><span><b class="noc-up">0</b> UP</span><i>/</i><span><b class="noc-down">0</b> DOWN</span></div>
                    <small>Total: <b class="noc-total"><?= (int)$deviceTotals['Server'] ?></b></small>
                </a>

                <a class="premium-gauge-card service-card" data-noc-card="Service" href="<?= $isGuest ? '/zurie/pages/live_ping.php' : '/zurie/pages/network_services.php' ?>">
                    <div class="gauge-card-head">
                        <span class="device-icon">◉</span><b>NETWORK SERVICES</b><small>LIVE</small>
                    </div>
                    <div class="premium-gauge">
                        <svg viewBox="0 0 220 125" role="img" aria-label="Network service uptime">
                            <defs><linearGradient id="serviceGradient" x1="0" x2="1"><stop offset="0%" stop-color="#32d26b"/><stop offset="72%" stop-color="#60df6f"/><stop offset="84%" stop-color="#f8b52e"/><stop offset="100%" stop-color="#ef4c4c"/></linearGradient></defs>
                            <path class="premium-gauge-track" d="M22 108 A88 88 0 0 1 198 108"></path>
                            <path class="premium-gauge-colour" d="M22 108 A88 88 0 0 1 198 108" stroke="url(#serviceGradient)"></path>
                            <line class="premium-gauge-needle" x1="110" y1="108" x2="110" y2="38"></line>
                            <circle class="premium-gauge-pin" cx="110" cy="108" r="6"></circle>
                            <text x="20" y="123">0</text><text x="105" y="25">50</text><text x="190" y="123">100</text>
                        </svg>
                        <b class="noc-pct">0%</b>
                    </div>
                    <div class="gauge-live-numbers"><span><b class="noc-up">0</b> UP</span><i>/</i><span><b class="noc-down">0</b> DOWN</span></div>
                    <small>Total: <b class="noc-total"><?= (int)$deviceTotals['Service'] ?></b></small>
                </a>

                <a class="premium-gauge-card ap-card" data-noc-card="AP" href="<?= $isGuest ? '/zurie/pages/live_ping.php' : '/zurie/pages/access_point.php' ?>">
                    <div class="gauge-card-head">
                        <span class="device-icon">◔</span><b>ACCESS POINT (AP)</b><small>LIVE</small>
                    </div>
                    <div class="premium-gauge">
                        <svg viewBox="0 0 220 125" role="img" aria-label="Access point uptime">
                            <defs><linearGradient id="apGradient" x1="0" x2="1"><stop offset="0%" stop-color="#32d26b"/><stop offset="72%" stop-color="#60df6f"/><stop offset="84%" stop-color="#f8b52e"/><stop offset="100%" stop-color="#ef4c4c"/></linearGradient></defs>
                            <path class="premium-gauge-track" d="M22 108 A88 88 0 0 1 198 108"></path>
                            <path class="premium-gauge-colour" d="M22 108 A88 88 0 0 1 198 108" stroke="url(#apGradient)"></path>
                            <line class="premium-gauge-needle" x1="110" y1="108" x2="110" y2="38"></line>
                            <circle class="premium-gauge-pin" cx="110" cy="108" r="6"></circle>
                            <text x="20" y="123">0</text><text x="105" y="25">50</text><text x="190" y="123">100</text>
                        </svg>
                        <b class="noc-pct">0%</b>
                    </div>
                    <div class="gauge-live-numbers"><span><b class="noc-up">0</b> UP</span><i>/</i><span><b class="noc-down">0</b> DOWN</span></div>
                    <small>Total: <b class="noc-total"><?= (int)$deviceTotals['AP'] ?></b></small>
                </a>
            </section>

            </div>

            <section class="top-alert-layout" aria-label="Alert rangkaian terkini">
                <article id="alert-panel" class="noc-panel alerts-panel alerts-panel-top">
                    <div class="panel-heading alert-top-heading">
                        <div class="alert-title-wrap">
                            <span class="alert-live-orb" aria-hidden="true"></span>
                            <div>
                                <h3>ALERT TERKINI <span>(LIVE)</span></h3>
                                <small>Peranti bermasalah dan kejadian terkini dipaparkan di sini</small>
                            </div>
                        </div>
                        <a class="alert-view-all" href="/zurie/pages/live_ping.php">Lihat Monitoring <span>→</span></a>
                    </div>
                    <div id="nocAlerts" class="premium-alert-list premium-alert-list-top">
                        <div class="premium-alert-row loading"><span>…</span><div><b>Loading status...</b><small>Menunggu API NOC</small></div></div>
                    </div>
                </article>
            </section>

        </section>

        <section class="dashboard-live-server-row" aria-label="Live Ping dan Server Detail">
            <section class="noc-panel live-ping-panel dashboard-live-ping" id="live-ping-panel"
                     data-live-ping data-compact="1" data-api="api/live_ping.php?limit=4"
                     data-interval="15000" data-server-detail-base="pages/server_detail.php">
                <div class="panel-heading live-ping-heading">
                    <div>
                        <h3>LIVE PING <span>(4 DEVICE)</span></h3>
                        <small>Klik kad untuk tukar Server Detail • refresh 15 saat</small>
                    </div>
                    <div class="live-ping-actions">
                        <span class="live-ping-last">Belum disemak</span>
                        <a href="/zurie/pages/live_ping.php" title="Besarkan Live Ping" aria-label="Besarkan Live Ping">⛶</a>
                    </div>
                </div>
                <div class="live-ping-grid" data-live-ping-grid>
                    <div class="live-ping-empty">Sedang memuatkan ping...</div>
                </div>
            </section>

            <article class="noc-panel dashboard-server-detail neutral" id="dashboard-server-detail"
                     data-dashboard-server-detail data-api="api/server_metrics_current.php"
                     data-default-server="dev_04b627acc6e597c8">
                <div class="dsd-head">
                    <div>
                        <h3>SERVER DETAIL <span>(GENERIC)</span></h3>
                        <small>Klik server dalam Live Ping untuk menukar paparan ini</small>
                    </div>
                    <div class="dsd-actions">
                        <a class="dsd-icon-btn" data-dsd-full-link href="pages/server_detail.php?device_id=dev_04b627acc6e597c8" title="Buka halaman detail penuh" aria-label="Buka halaman detail penuh">↗</a>
                        <button class="dsd-icon-btn" data-dsd-expand type="button" title="Besarkan paparan" aria-label="Besarkan paparan">⛶</button>
                    </div>
                </div>

                <div class="dsd-identity">
                    <div>
                        <div class="dsd-name-row"><span class="dsd-type" data-dsd-type>SERVER</span><strong class="dsd-name" data-dsd-name>Website KMP</strong></div>
                        <div class="dsd-meta" data-dsd-meta>10.14.48.100 • Windows Server</div>
                    </div>
                    <div class="dsd-state-box">
                        <span class="dsd-state"><i></i><b data-dsd-state>WAIT</b></span>
                        <span class="dsd-ping" data-dsd-ping>Ping sedang dimuatkan</span>
                    </div>
                </div>

                <div class="dsd-metric-grid">
                    <div class="dsd-metric"><span>CPU</span><strong data-dsd-cpu>--%</strong><div class="dsd-bar"><i data-dsd-cpu-bar></i></div><small>Penggunaan processor</small></div>
                    <div class="dsd-metric"><span>Memory</span><strong data-dsd-memory>--%</strong><div class="dsd-bar"><i data-dsd-memory-bar></i></div><small data-dsd-memory-note>Menunggu agent</small></div>
                    <div class="dsd-metric"><span>Disk Max</span><strong data-dsd-disk>--%</strong><div class="dsd-bar"><i data-dsd-disk-bar></i></div><small data-dsd-disk-note>Menunggu agent</small></div>
                    <div class="dsd-metric"><span>Uptime</span><strong data-dsd-uptime>--</strong><div class="dsd-bar dsd-uptime-flow"><i></i></div><small data-dsd-uptime-note>Menunggu agent</small></div>
                </div>

                <div class="dsd-extra">
                    <section class="dsd-extra-card">
                        <h4>Maklumat Sistem</h4>
                        <dl class="dsd-system-list">
                            <dt>Hostname</dt><dd data-dsd-hostname>--</dd>
                            <dt>Operating System</dt><dd data-dsd-os>--</dd>
                            <dt>Agent Version</dt><dd data-dsd-agent-version>--</dd>
                            <dt>Last Updated</dt><dd data-dsd-lastseen>--</dd>
                        </dl>
                    </section>
                    <section class="dsd-extra-card"><h4>Disk / Partition</h4><div class="dsd-list" data-dsd-disks></div></section>
                    <section class="dsd-extra-card"><h4>Process / Service</h4><div class="dsd-list" data-dsd-services></div></section>
                </div>

                <div class="dsd-foot"><span class="dsd-notice" data-dsd-notice>Sedang membaca Server Metrics...</span><a data-dsd-full-link href="pages/server_detail.php?device_id=dev_04b627acc6e597c8">Detail penuh →</a></div>
            </article>
        </section>
        <div class="dsd-backdrop" data-dsd-backdrop></div>

        <section class="pinned-server-strip" aria-label="Server utama dipantau">
            <article class="noc-panel pinned-server-card neutral" data-pinned-mis data-device-ip="10.14.48.75" tabindex="0" role="button" aria-label="Papar detail server MIS">
                <div class="psc-identity">
                    <span class="psc-kicker">PINNED SERVER</span>
                    <div class="psc-title-row">
                        <div>
                            <strong data-pmis-name>MIS</strong>
                            <span><b data-pmis-ip>10.14.48.75</b> • <em data-pmis-os>FreeBSD</em></span>
                        </div>
                        <span class="psc-status"><i></i><b data-pmis-state>WAIT</b></span>
                    </div>
                    <small data-pmis-updated>Menunggu data Server Metrics...</small>
                </div>

                <div class="psc-metrics">
                    <div><span>CPU</span><strong data-pmis-cpu>--%</strong><i><b data-pmis-cpu-bar></b></i></div>
                    <div><span>Memory</span><strong data-pmis-memory>--%</strong><i><b data-pmis-memory-bar></b></i></div>
                    <div><span>Disk Max</span><strong data-pmis-disk>--%</strong><i><b data-pmis-disk-bar></b></i></div>
                    <div><span>Uptime</span><strong data-pmis-uptime>--</strong><small data-pmis-hostname>--</small></div>
                </div>

                <div class="psc-actions">
                    <button type="button" data-pmis-view title="Papar dalam Server Detail" aria-label="Papar MIS dalam Server Detail">◎</button>
                    <a href="/zurie/pages/server_metrics.php" data-pmis-full title="Buka detail penuh MIS" aria-label="Buka detail penuh MIS">↗</a>
                </div>
            </article>
        </section>

        <section class="dashboard-insight-grid">
            <article id="status-chart" class="noc-panel status-chart-panel">
                <div class="panel-heading">
                    <h3>STATUS UP / DOWN <span>(SEMASA)</span></h3>
                    <span class="chart-legend"><i class="up"></i>UP <i class="down"></i>DOWN</span>
                </div>
                <div class="vertical-status-chart" id="verticalStatusChart">
                    <?php
                    $chartTypes = [
                        'Switch' => ['label' => 'Switch'],
                        'Server' => ['label' => 'Server'],
                        'AP' => ['label' => 'Access Point'],
                        'Service' => ['label' => 'Network Services'],
                    ];
                    foreach ($chartTypes as $type => $info):
                    ?>
                    <div class="chart-category" data-chart-type="<?= e($type) ?>">
                        <div class="chart-bars">
                            <div class="chart-bar up" style="height:0%"><b class="chart-up-value">0</b></div>
                            <div class="chart-bar down" style="height:0%"><b class="chart-down-value">0</b></div>
                        </div>
                        <span><?= e($info['label']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="noc-panel device-donut-panel">
                <div class="panel-heading"><h3>JENIS DEVICE <span>(JUMLAH)</span></h3></div>
                <div class="donut-layout">
                    <div class="device-donut" id="deviceDonut">
                        <div><strong id="donutTotal"><?= (int)$totalDevices ?></strong><span>JUMLAH<br>DEVICE</span></div>
                    </div>
                    <div class="donut-legend">
                        <a href="/zurie/pages/access_point.php"><i class="ap"></i><span>Access Point (AP)<b id="legendAP"><?= (int)$deviceTotals['AP'] ?></b></span></a>
                        <a href="/zurie/pages/switch.php"><i class="switch"></i><span>Switch<b id="legendSwitch"><?= (int)$deviceTotals['Switch'] ?></b></span></a>
                        <a href="/zurie/pages/server.php"><i class="server"></i><span>Server<b id="legendServer"><?= (int)$deviceTotals['Server'] ?></b></span></a>
                        <a href="/zurie/pages/network_services.php"><i class="service"></i><span>Network Services<b id="legendService"><?= (int)$deviceTotals['Service'] ?></b></span></a>
                    </div>
                </div>
            </article>

        </section>

        <section class="noc-panel quick-actions-panel">
            <div class="panel-heading"><h3>QUICK ACTIONS</h3></div>
            <div class="quick-action-grid">
                <a href="<?= e($pingPanelUrl) ?>" target="_blank"><i class="green">⌁</i><span><b>Ping Tools</b><small>Uji sambungan</small></span><em>›</em></a>
                <?php if (!$isGuest): ?>
                <a href="<?= e($wifiControllerUrl) ?>" target="_blank"><i class="blue">⌘</i><span><b>WiFi Controller</b><small>Urus rangkaian</small></span><em>›</em></a>
                <a href="/zurie/pages/device_manager.php"><i class="purple">▣</i><span><b>Device List</b><small>Senarai peranti</small></span><em>›</em></a>
                <a href="/zurie/pages/credential_vault.php"><i class="amber">◆</i><span><b>Credential Vault</b><small>Username & password</small></span><em>›</em></a>
                <a href="/zurie/pages/quick_links.php"><i class="cyan">□</i><span><b>System Links</b><small>Semua pautan</small></span><em>›</em></a>
                <a href="<?= e($networkSheetUrl) ?>" target="_blank" rel="noopener noreferrer"><i class="gold">▰</i><span><b>Network Asset GS</b><small>Inventori & credential</small></span><em>›</em></a>
                <?php else: ?>
                <a href="/zurie/pages/live_ping.php"><i class="blue">⌁</i><span><b>Live Ping</b><small>Monitoring sahaja</small></span><em>›</em></a>
                <a href="/zurie/pages/server_metrics.php"><i class="green">▤</i><span><b>Server Metrics</b><small>Health monitoring</small></span><em>›</em></a>
                <a href="/zurie/map/"><i class="cyan">◇</i><span><b>Network Map</b><small>Paparan status</small></span><em>›</em></a>
                <?php endif; ?>
            </div>
        </section>

        <footer class="noc-footer">
            <span>Personal NOC Dashboard</span><i>•</i><span>KMP Operations Center (KOC)</span><i>•</i><span>Version 1.0</span><i>•</i><span>Developed by Zurie</span>
        </footer>
    </main>
</div>
<script src="assets/js/app.js"></script>
<script src="assets/js/noc.js"></script>
<script src="assets/js/live-ping.js?v=20260622-compactdetail1"></script>
<script src="assets/js/dashboard-server-detail.js?v=20260623-mispinned1"></script>
<script src="assets/js/profile-menu.js?v=20260624-1"></script>
<script src="assets/js/navigation-fix.js?v=20260624-1"></script>

<?php if (!$isGuest): ?>
<script>
(function () {
    'use strict';

    const topBadge = document.getElementById('photoNotificationCount');
    const menuBadge = document.getElementById('photoMenuBadge');
    const topButton = document.getElementById('photoNotificationButton');
    const reviewLink = document.getElementById('photoReviewLink');
    const photoUploadAlertBar = document.getElementById('photoUploadAlertBar');
    const photoUploadAlertStudent = document.getElementById('photoUploadAlertStudent');
    let controller = null;

    function setBadge(badge, count) {
        if (!badge) return;
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.hidden = count < 1;
    }

    function normaliseLatestUploads(data) {
        if (!data || !Array.isArray(data.latest_uploads)) return [];
        return data.latest_uploads.filter(function (item) {
            return item && (String(item.nama || '').trim() !== '' || String(item.matrik || '').trim() !== '');
        });
    }

    function applyPhotoNotification(data) {
        const rawCount = Number(data && data.pending_count);
        const count = Number.isFinite(rawCount) && rawCount > 0 ? Math.floor(rawCount) : 0;
        const latestAt = data && data.latest_uploaded_at ? String(data.latest_uploaded_at) : '';
        const uploads = normaliseLatestUploads(data);
        const label = count > 0
            ? count + ' foto pelajar menunggu semakan' + (latestAt ? ' • terbaru ' + latestAt : '')
            : 'Tiada foto pelajar menunggu semakan';

        setBadge(topBadge, count);
        setBadge(menuBadge, count);

        if (topButton) {
            topButton.title = label;
            topButton.setAttribute('aria-label', label);
        }
        if (reviewLink) {
            reviewLink.title = label + '. Klik untuk semak, repair dan sync.';
        }

        if (photoUploadAlertBar && photoUploadAlertStudent) {
            if (count > 0) {
                const newest = uploads.length ? uploads[0] : null;
                const name = newest ? String(newest.nama || newest.matrik || 'Pelajar').trim() : 'Pelajar';
                const matrik = newest ? String(newest.matrik || '').trim() : '';
                photoUploadAlertStudent.textContent = matrik && name !== matrik
                    ? name + ', ' + matrik
                    : name;

                const directUrl = newest && newest.review_url
                    ? String(newest.review_url)
                    : '/zurie/pages/upload_review.php';
                photoUploadAlertBar.href = directUrl;
                photoUploadAlertBar.title = 'Semak foto ' + (matrik ? name + ' (' + matrik + ')' : name);
                photoUploadAlertBar.hidden = false;
            } else {
                photoUploadAlertBar.hidden = true;
            }
        }
    }

    async function refreshPhotoNotification() {
        if (controller) controller.abort();
        controller = new AbortController();

        try {
            const response = await fetch('/zurie/api/photo_upload_notifications.php?_=' + Date.now(), {
                cache: 'no-store',
                credentials: 'same-origin',
                signal: controller.signal
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const data = await response.json();
            if (!data || data.ok !== true) throw new Error('Invalid response');
            applyPhotoNotification(data);
        } catch (error) {
            if (error && error.name === 'AbortError') return;
            // Kekalkan badge terakhir jika API tergendala; jangan padam notifikasi sedia ada.
        }
    }

    refreshPhotoNotification();
    window.setInterval(refreshPhotoNotification, 15000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) refreshPhotoNotification();
    });
})();
</script>
<?php endif; ?>
</body>
</html>
