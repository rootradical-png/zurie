<?php
$switches = [
    [1,'DistAdmin01','H3C S5500-28F','210235A24UH104000187','10.14.60.3'],
    [2,'DistLibrary02','H3C S5500-28F','210235A24UH104000276','10.14.60.4'],
    [3,'DistBlokPensyarah03','H3C S5500-28F','210235A24UH104000255','10.14.60.2'],
    [4,'KMP_Wireless','H3C WX5004','210235A35JB09C000037','10.14.60.5'],
    [5,'vComLab6','Aruba Instant On 1930 48G','CN22KPH0Y5','10.14.60.9'],
    [6,'Switch MK 7','Aruba Instant On 1930 48G','CN58LNX0TF','10.14.62.8'],
    [7,'ComLab08','3COM 4210G 48-Port','210235A0F1H105000012','10.14.60.11'],
    [8,'ComLab9','3COM 4210G 48-Port','210235A0F1H104000288','10.14.60.12'],
    [9,'ComLab10','3COM 4210G 48-Port','210235A0F1H105000525','10.14.60.13'],
    [10,'Lect021stF','3COM 4210G 48-Port','210235A0F1H105000629','10.14.60.28'],
    [11,'Lect011stF','3COM 4210G 48-Port','210235A0F1H105000318','10.14.60.27'],
    [12,'Pensyarah_GF','3COM 4210G 48-Port','210235A0F1H105000312','10.14.60.30'],
    [13,'Lect011stF','3COM 4210G 48-Port','210235A0F1H105000318','10.14.60.27'],
    [14,'Lect021stF','3COM 4210G 48-Port','210235A0F1H105000629','10.14.60.28'],
    [15,'Admin02Gf','3COM 4210G 48-Port','210235A0F1H105000836','10.14.60.15'],
    [16,'Server_Room','3COM 4210G 48-Port','210235A0F1H105000260','10.14.60.6'],
    [17,'IbnuKhaldun','HPE OfficeConnect 1820 24G','CN01GMW1SR','10.14.60.33'],
    [18,'Lib02_GF','3Com Switch 4500 50-Port','YEDFA7MF8E700','10.14.60.17'],
    [19,'Lib_Level_1','3Com Switch 4500 26-Port','YECF9OLCC5F40','10.14.60.19'],
    [20,'LibraryGf2','Aruba Instant On 1930 24p','CN36LB34SS','10.14.60.20'],
    [21,'DewanKuliah','3COM 4210G 24-Port','210235A0F0H104000006','10.14.60.21'],
    [22,'MakmalSainsGf','3COM 4210G 48-Port','210235A0F1H105001007','10.14.60.22'],
    [23,'Tutoran_A','3COM 4210G 24-Port','210235A0F0H105000819','10.14.60.23'],
    [23,'Tutoran_B','3COM 4210G 24-Port','210235A0F0H105000819','10.14.60.24'],
    [24,'Asrama C1','Aruba Instant On 1930 24p','CN36LB34LY','10.14.60.26'],
    [25,'Asrama A3','Aruba Instant On 1930 24p','CN36LB34SN','10.14.60.25'],
    [26,'BLOK B1','3COM 4210G 48-Port','210235A0F1H105000256','10.14.60.7'],
];
function brandBadge($model) {
    $m = strtolower($model);
    if (strpos($m, 'aruba') !== false) return 'Aruba';
    if (strpos($m, 'h3c') !== false) return 'H3C';
    if (strpos($m, 'hpe') !== false) return 'HPE';
    if (strpos($m, '3com') !== false) return '3COM';
    return 'Switch';
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Switch Inventory | Zurie Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="simple-page wide-page switch-page">
    <a class="back-link" href="../index.php">← Kembali ke Dashboard</a>

    <div class="panel big-panel">
        <div class="breadcrumb-mini">📶 Network & WiFi / 🔀 Switch Inventory</div>
        <div class="switch-header-row">
            <div>
                <span class="badge">Network Asset</span>
                <h2>Senarai Switch KMP</h2>
                <p class="note-text">Klik nama switch atau butang <strong>Open IP</strong> untuk buka terus halaman pengurusan melalui alamat IP.</p>
            </div>
            <div class="switch-summary">
                <strong><?= count($switches) ?></strong>
                <span>Rekod Switch</span>
            </div>
        </div>

        <div class="switch-tools">
            <input type="text" id="switchSearch" placeholder="Cari nama, model, serial atau IP..." onkeyup="filterSwitchTable()">
            <a class="ghost-btn" href="device_manager.php?type=Switch">📝 Edit Switch</a> <a class="ghost-btn" href="../index.php">Dashboard</a>
        </div>

        <div class="table-wrap">
            <table class="switch-table" id="switchTable">
                <thead>
                    <tr>
                        <th>BIL</th>
                        <th>Switch / Peralatan</th>
                        <th>Model</th>
                        <th>Serial</th>
                        <th>IP</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($switches as $sw): [$bil,$nama,$model,$serial,$ip] = $sw; $url = 'http://' . $ip; ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$bil) ?></td>
                            <td>
                                <a class="switch-name" href="<?= htmlspecialchars($url) ?>" target="_blank">
                                    🔀 <?= htmlspecialchars($nama) ?>
                                </a>
                            </td>
                            <td><span class="brand-badge"><?= htmlspecialchars(brandBadge($model)) ?></span> <?= htmlspecialchars($model) ?></td>
                            <td><code><?= htmlspecialchars($serial) ?></code></td>
                            <td><a class="ip-link" href="<?= htmlspecialchars($url) ?>" target="_blank"><?= htmlspecialchars($ip) ?></a></td>
                            <td><a class="open-btn" href="<?= htmlspecialchars($url) ?>" target="_blank">Open IP</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function filterSwitchTable() {
    const input = document.getElementById('switchSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#switchTable tbody tr');
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(input) ? '' : 'none';
    });
}
</script>
</body>
</html>
