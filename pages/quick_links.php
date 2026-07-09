<?php
$categories = [
    '🏫 KMP' => [
        ['title'=>'Laman Web Rasmi','desc'=>'Portal rasmi KMP','url'=>'http://www.kmp.matrik.edu.my/v6','icon'=>'🏫'],
        ['title'=>'Direktori Staf','desc'=>'Hubungi pegawai kolej','url'=>'http://www.kmp.matrik.edu.my/v6/index.php/hubungi-kami?view=article&id=124&catid=2','icon'=>'📞'],
    ],
    '🎓 Akademik' => [
        ['title'=>'MIS','desc'=>'Matrik Information System','url'=>'http://mis.kmp.matrik.edu.my','icon'=>'🗂️'],
        ['title'=>'Portal eLearning','desc'=>'Sistem pembelajaran pelajar','url'=>'http://portal.kmp.matrik.edu.my','icon'=>'💻'],
        ['title'=>'MBS','desc'=>'Sistem tempahan bilik','url'=>'http://www.kmp.matrik.edu.my/book','icon'=>'🏢'],
        ['title'=>'e-Sijil PRO','desc'=>'Sistem pengurusan dan pengesahan sijil','url'=>'#','icon'=>'🎓'],
        ['title'=>'Jam PSPM','desc'=>'Paparan jam rasmi peperiksaan PSPM','url'=>'http://www.kmp.matrik.edu.my/jam/','icon'=>'⏰'],
    ],
    '🗂️ i-SIMS' => [
        ['title'=>'Portal i-SIMS','desc'=>'Sistem pengurusan pelajar','url'=>'http://i-sims.kmp.matrik.edu.my','icon'=>'🧑‍🎓'],
        ['title'=>'i-SIMS Pelajar','desc'=>'Akses pelajar matrikulasi','url'=>'http://i-sims.kmp.matrik.edu.my/pelajar/','icon'=>'👨‍🎓'],
        ['title'=>'senarai_mis_lengkap','desc'=>'SQL generator data lengkap MIS','url'=>'isims_extract.php','icon'=>'📋'],
        ['title'=>'senarai','desc'=>'SQL generator data asas pelajar','url'=>'isims_senarai.php','icon'=>'📋'],
    ],
    '📧 Email & Identiti' => [
        ['title'=>'Email myGovUC','desc'=>'Email rasmi staf','url'=>'https://mail.google.com/mail/u/0/','icon'=>'✉️'],
        ['title'=>'Email DELIMA','desc'=>'Email pelajar KPM','url'=>'https://d2.delima.edu.my/login','icon'=>'📩'],
        ['title'=>'idME / ScanMe','desc'=>'Sistem pengesahan KPM','url'=>'https://idme.moe.gov.my/login','icon'=>'🪪'],
    ],
    '🛠️ Pentadbiran' => [
        ['title'=>'MOVERs','desc'=>'Matrix Online Vehicle Entry Reservation System','url'=>'http://www.kmp.matrik.edu.my/mover/web/%20MOVERs:%20Matrix%20Online%20Vehicle%20Entry%20Reservation%20System','icon'=>'🚗'],
        ['title'=>'Repository Script','desc'=>'Koleksi skrip dan tools pentadbiran','url'=>'utilities.php','icon'=>'📂'],
    ],
    '📡 Network & WiFi' => [
        ['title'=>'Ping Monitor','desc'=>'Paparan ping panel','url'=>'http://10.14.49.10:8888/','icon'=>'📡'],
        ['title'=>'WiFi Controller','desc'=>'Controller AP dan devices','url'=>'https://10.14.49.10:8443/manage/default/devices','icon'=>'📶'],
        ['title'=>'NOC Dashboard','desc'=>'Status live Switch, Server, Network Service dan AP','url'=>'../index.php#noc-status','icon'=>'📊'],
        ['title'=>'Switch Inventory','desc'=>'Senarai switch dan pautan IP','url'=>'switch_inventory.php','icon'=>'🔀'],
        ['title'=>'AP Inventory','desc'=>'Senarai AP dan pautan IP','url'=>'ap_inventory.php','icon'=>'📶'],
        ['title'=>'Device Manager','desc'=>'Edit IP Switch/AP/Server','url'=>'device_manager.php','icon'=>'📝'],
    ],
];
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Quick Links | Zurie Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="simple-page wide-page">
    <a class="back-link" href="../index.php">← Kembali ke Dashboard</a>
    <div class="panel big-panel">
        <div class="switch-header-row">
            <div>
                <span class="badge">Directory</span>
                <h2>Semua Pautan Sistem</h2>
                <p class="note-text">Pautan disusun ikut kategori. Klik kad untuk buka sistem dalam tab baru.</p>
            </div>
            <div class="switch-summary">
                <strong><?= array_sum(array_map('count', $categories)) ?></strong>
                <span>LINK</span>
            </div>
        </div>
        <div class="switch-tools">
            <input type="text" id="quickSearch" placeholder="Cari nama sistem, kategori atau penerangan..." onkeyup="filterQuickLinks()">
            <a class="ghost-btn" href="../index.php">Dashboard</a>
        </div>
        <?php foreach ($categories as $category => $links): ?>
            <section class="quicklink-category">
                <h3><?= htmlspecialchars($category) ?></h3>
                <div class="link-card-grid">
                    <?php foreach ($links as $link): ?>
                        <?php $external = preg_match('/^https?:\/\//', $link['url']); ?>
                        <a class="link-card searchable-link" data-keywords="<?= strtolower(htmlspecialchars($category.' '.$link['title'].' '.$link['desc'].' '.$link['url'])) ?>" href="<?= htmlspecialchars($link['url']) ?>" <?= $external ? 'target="_blank"' : '' ?>>
                            <div class="link-card-icon"><?= $link['icon'] ?></div>
                            <div class="link-card-body">
                                <h4><?= htmlspecialchars($link['title']) ?></h4>
                                <p><?= htmlspecialchars($link['desc']) ?></p>
                                <code><?= htmlspecialchars($link['url']) ?></code>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</div>
<script>
function filterQuickLinks(){
    const q = document.getElementById('quickSearch').value.toLowerCase();
    document.querySelectorAll('.searchable-link').forEach(card => {
        card.style.display = card.dataset.keywords.includes(q) ? 'flex' : 'none';
    });
}
</script>
</body>
</html>
