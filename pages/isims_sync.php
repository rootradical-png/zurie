<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/security.php';
require_once dirname(__DIR__) . '/lib/isims_smart_sync.php';
zurie_security_protect_sensitive_page();
if (function_exists('zurie_is_guest') && zurie_is_guest()) {
    http_response_code(403);
    exit('Guest read-only.');
}
$csrf = zurie_security_csrf_token();
$config = zurie_isims_sync_config();
$logs = zurie_isims_sync_recent_logs();
?><!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sync Senarai i-SIMS | ZURIE</title>
<style>
:root{--bg:#07111f;--card:#0d1c2e;--card2:#102840;--line:rgba(148,163,184,.22);--text:#eaf4ff;--muted:#93a8bd;--ok:#22c55e;--warn:#f59e0b;--bad:#ef4444;--cyan:#67e8f9}
*{box-sizing:border-box}body{margin:0;background:linear-gradient(135deg,#07111f,#0f2339);color:var(--text);font-family:Segoe UI,Arial,sans-serif}.wrap{max-width:1120px;margin:0 auto;padding:24px}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:18px}.top h1{margin:0;font-size:25px}.top p{margin:6px 0 0;color:var(--muted)}a{color:inherit}.btn{border:1px solid var(--line);background:#12304d;color:var(--text);padding:10px 14px;border-radius:12px;cursor:pointer;font-weight:700;text-decoration:none;display:inline-flex;gap:8px;align-items:center}.btn:hover{background:#173a5c}.btn.primary{background:#0e7490;border-color:#22d3ee}.btn.danger{background:#7f1d1d}.btn:disabled{opacity:.55;cursor:not-allowed}.grid{display:grid;grid-template-columns:1.15fr .85fr;gap:16px}.card{background:rgba(13,28,46,.88);border:1px solid var(--line);border-radius:18px;padding:18px;box-shadow:0 14px 40px rgba(0,0,0,.24)}.card h2{margin:0 0 12px;font-size:18px}.statgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:14px 0}.stat{background:rgba(255,255,255,.045);border:1px solid var(--line);border-radius:14px;padding:12px}.stat b{font-size:24px;display:block}.stat span{color:var(--muted);font-size:12px}.muted{color:var(--muted)}.pill{display:inline-flex;border:1px solid var(--line);border-radius:999px;padding:5px 9px;font-size:12px;background:rgba(255,255,255,.05)}.pill.ok{color:#86efac}.pill.warn{color:#fde68a}.pill.bad{color:#fca5a5}.progress{height:12px;border-radius:999px;background:rgba(255,255,255,.09);overflow:hidden;margin:14px 0}.bar{height:100%;width:0;background:linear-gradient(90deg,#22d3ee,#22c55e);transition:width .25s}.table{width:100%;border-collapse:collapse;margin-top:10px}.table th,.table td{padding:9px 8px;border-bottom:1px solid var(--line);text-align:left;font-size:13px}.table th{color:#cfe8ff}.sample{max-height:260px;overflow:auto;border:1px solid var(--line);border-radius:12px}.notice{border:1px solid rgba(245,158,11,.35);background:rgba(245,158,11,.08);padding:12px;border-radius:14px;color:#fde68a}.success{border:1px solid rgba(34,197,94,.35);background:rgba(34,197,94,.08);padding:12px;border-radius:14px;color:#bbf7d0}.error{border:1px solid rgba(239,68,68,.35);background:rgba(239,68,68,.08);padding:12px;border-radius:14px;color:#fecaca}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.code{font-family:Consolas,monospace;font-size:12px;background:rgba(0,0,0,.28);border:1px solid var(--line);border-radius:10px;padding:10px;overflow:auto}@media(max-width:860px){.grid{grid-template-columns:1fr}.statgrid{grid-template-columns:repeat(2,1fr)}.top{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div><h1>Sync Senarai i-SIMS</h1><p>Smart Sync daripada <b>senarai_mis_lengkap</b> ZURIE ke table <b>senarai</b> server i-SIMS.</p></div>
    <a class="btn" href="/zurie/index.php">← Dashboard</a>
  </div>
  <div class="grid">
    <section class="card">
      <h2>Status & Preview</h2>
      <div id="msg" class="notice">Tekan <b>Preview</b> untuk semak perubahan sebelum sync.</div>
      <div class="statgrid">
        <div class="stat"><b id="cSource">-</b><span>Rekod ZURIE</span></div>
        <div class="stat"><b id="cTarget">-</b><span>Rekod i-SIMS</span></div>
        <div class="stat"><b id="cInsert">-</b><span>Rekod baharu</span></div>
        <div class="stat"><b id="cUpdate">-</b><span>Rekod berubah</span></div>
      </div>
      <div class="progress"><div id="bar" class="bar"></div></div>
      <div class="actions">
        <button class="btn" id="previewBtn" type="button">Preview</button>
        <button class="btn primary" id="syncBtn" type="button" disabled>Sync Sekarang</button>
      </div>
      <div id="sampleBox" style="margin-top:16px" hidden>
        <h2>Contoh Rekod Terlibat</h2>
        <div class="sample"><table class="table"><thead><tr><th>Jenis</th><th>Matrik</th><th>Nama</th><th>Sebab</th></tr></thead><tbody id="sampleRows"></tbody></table></div>
      </div>
    </section>
    <aside class="card">
      <h2>Konfigurasi</h2>
      <?php if (!zurie_isims_sync_ready($config)): ?>
        <div class="error">Konfigurasi server i-SIMS belum lengkap.</div>
        <p class="muted">Salin fail contoh dan isi host/database/user/password sebenar.</p>
        <div class="code">/zurie/config/isims_mysql_config.php.example<br>→ /zurie/config/isims_mysql_config.php</div>
      <?php else: ?>
        <p><span class="pill ok">Configured</span></p>
      <?php endif; ?>
      <table class="table">
        <tr><th>Host</th><td><?= zurie_isims_sync_e($config['host'] !== '' ? $config['host'] : '-') ?></td></tr>
        <tr><th>Database</th><td><?= zurie_isims_sync_e($config['database'] !== '' ? $config['database'] : '-') ?></td></tr>
        <tr><th>Source</th><td><?= zurie_isims_sync_e($config['source_table'] ?: '-') ?></td></tr>
        <tr><th>Target</th><td><?= zurie_isims_sync_e($config['target_table'] ?: '-') ?></td></tr>
      </table>
      <h2 style="margin-top:18px">Log Terkini</h2>
      <?php if (!$logs): ?>
        <p class="muted">Belum ada log sync.</p>
      <?php else: ?>
        <table class="table"><thead><tr><th>Masa</th><th>Insert</th><th>Update</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($logs as $log): ?>
          <tr><td><?= zurie_isims_sync_e(substr((string)($log['time'] ?? ''),0,19)) ?></td><td><?= (int)($log['inserted'] ?? 0) ?></td><td><?= (int)($log['updated'] ?? 0) ?></td><td><?= !empty($log['ok']) ? '<span class="pill ok">OK</span>' : '<span class="pill bad">Error</span>' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </aside>
  </div>
</div>
<script>
const csrf = <?= json_encode($csrf) ?>;
const msg = document.getElementById('msg');
const bar = document.getElementById('bar');
const previewBtn = document.getElementById('previewBtn');
const syncBtn = document.getElementById('syncBtn');
const sampleBox = document.getElementById('sampleBox');
const sampleRows = document.getElementById('sampleRows');
function setMsg(type, html){ msg.className = type; msg.innerHTML = html; }
function n(v){ return Number(v || 0).toLocaleString('en-MY'); }
function setCounts(c){
  document.getElementById('cSource').textContent = n(c.source);
  document.getElementById('cTarget').textContent = n(c.target);
  document.getElementById('cInsert').textContent = n(c.insert);
  document.getElementById('cUpdate').textContent = n(c.update);
}
function renderSample(data){
  sampleRows.innerHTML = '';
  const rows = [];
  (data.sample?.insert || []).forEach(r => rows.push(['Baharu', r.key, r.nama || '', r.reason || '']));
  (data.sample?.update || []).forEach(r => rows.push(['Berubah', r.key, r.nama || '', r.reason || '']));
  rows.forEach(r => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${r[0]}</td><td>${r[1]}</td><td>${r[2]}</td><td>${r[3]}</td>`;
    sampleRows.appendChild(tr);
  });
  sampleBox.hidden = rows.length === 0;
}
previewBtn.addEventListener('click', async () => {
  previewBtn.disabled = true; syncBtn.disabled = true; bar.style.width = '20%';
  setMsg('notice','Sedang semak perbezaan rekod...');
  try {
    const res = await fetch('/zurie/api/isims_sync_preview.php', {cache:'no-store'});
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Preview gagal');
    setCounts(data.counts || {}); renderSample(data); bar.style.width = '100%';
    const total = data.counts?.total_changes || 0;
    if (total === 0) { setMsg('success','✓ Tiada perubahan dikesan. Sync tidak diperlukan.'); }
    else { setMsg('notice',`Dikesan <b>${n(total)}</b> perubahan. Semak contoh rekod, kemudian tekan <b>Sync Sekarang</b>.`); syncBtn.disabled = false; }
  } catch (e) { bar.style.width='0'; setMsg('error','Preview gagal: ' + e.message); }
  finally { previewBtn.disabled = false; }
});
syncBtn.addEventListener('click', async () => {
  if (!confirm('Teruskan sync rekod berubah ke server i-SIMS?')) return;
  previewBtn.disabled = true; syncBtn.disabled = true; bar.style.width = '35%';
  setMsg('notice','Sync sedang berjalan. Jangan tutup halaman ini.');
  try {
    const fd = new FormData(); fd.append('_csrf', csrf); fd.append('limit', '10000');
    const res = await fetch('/zurie/api/isims_sync_execute.php', {method:'POST', body:fd});
    const data = await res.json(); bar.style.width = '100%';
    if (!data.ok) throw new Error(data.error || (data.errors && data.errors[0]?.error) || 'Sync gagal');
    setMsg('success',`✓ Sync selesai. Insert: <b>${n(data.inserted)}</b>, Update: <b>${n(data.updated)}</b>, Skip: <b>${n(data.skipped)}</b>.`);
  } catch(e) { bar.style.width='0'; setMsg('error','Sync gagal: ' + e.message); }
  finally { previewBtn.disabled = false; }
});
</script>
</body>
</html>
