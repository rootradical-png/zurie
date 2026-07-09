<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive', true);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$configFile = dirname(__DIR__) . '/config/legacy_ap_access.php';
$config = file_exists($configFile) ? require $configFile : [];
$controllerUrl = (string)($config['controller_url'] ?? 'https://10.14.49.200:8443/manage/default/devices');
$informUrl = (string)($config['inform_url'] ?? 'http://10.14.49.200:8080/inform');
$username = (string)($config['username'] ?? '');
$password = (string)($config['password'] ?? '');
$firmwareUrl = (string)($config['firmware_url'] ?? '');
$controllerVersion = (string)($config['controller_version'] ?? '6.5.55');
$firmwareVersion = (string)($config['firmware_version'] ?? '4.0.80.10875');

$upgradeCommand = 'syswrapper.sh upgrade ' . $firmwareUrl;
$informCommand = 'set-inform ' . $informUrl;
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Legacy AP Access | Personal NOC</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
:root{--bg:#07111e;--panel:#0b1d30;--line:rgba(112,148,188,.18);--muted:#829ab0;--cyan:#55d9ff;--green:#55e5a7;--yellow:#ffd36c;--red:#ff7486}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top right,#0e2943 0,#07111e 42%,#040b13 100%);color:#edf8ff;font-family:Inter,Segoe UI,Arial,sans-serif;min-height:100vh}.wrap{width:min(1180px,calc(100% - 28px));margin:24px auto 46px}.top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}.back{color:#8bdcff;text-decoration:none;font-weight:800;font-size:13px}.top h1{margin:8px 0 4px;font-size:28px}.top p{margin:0;color:var(--muted);font-size:13px}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:0 13px;border:1px solid rgba(85,217,255,.28);border-radius:10px;background:rgba(85,217,255,.09);color:#bcefff;text-decoration:none;font-weight:800;font-size:12px;cursor:pointer}.btn:hover{background:rgba(85,217,255,.17)}.btn.green{border-color:rgba(85,229,167,.32);background:rgba(85,229,167,.09);color:#aaffd4}.btn.warn{border-color:rgba(255,211,108,.32);background:rgba(255,211,108,.08);color:#ffe5a1}.grid{display:grid;grid-template-columns:1.05fr 1.95fr;gap:14px}.panel{border:1px solid var(--line);border-radius:16px;background:linear-gradient(145deg,rgba(12,31,51,.98),rgba(6,18,31,.98));box-shadow:0 18px 50px rgba(0,0,0,.22);overflow:hidden}.panel-head{padding:14px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px}.panel-head h2{margin:0;font-size:14px}.panel-body{padding:16px}.badge{display:inline-flex;align-items:center;gap:6px;padding:5px 8px;border-radius:999px;background:rgba(85,229,167,.1);color:var(--green);font-size:10px;font-weight:900}.badge i{width:7px;height:7px;border-radius:50%;background:currentColor;box-shadow:0 0 10px currentColor}.badge.locked{background:rgba(255,116,134,.1);color:var(--red)}.kv{display:grid;grid-template-columns:120px 1fr;gap:9px 12px;margin:0}.kv dt{color:var(--muted);font-size:12px}.kv dd{margin:0;font-size:12px;word-break:break-word}.secret-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.secret{padding:8px 10px;border-radius:9px;border:1px solid rgba(112,148,188,.17);background:#05101c;color:#f4fbff;font-family:Consolas,monospace;min-width:160px}.copy{border:1px solid rgba(85,217,255,.25);background:rgba(85,217,255,.08);color:#9ce7ff;border-radius:8px;padding:7px 9px;font-size:11px;font-weight:800;cursor:pointer}.copy:disabled{opacity:.42;cursor:not-allowed}.notice{margin-top:14px;padding:11px 12px;border-radius:11px;border:1px solid rgba(255,211,108,.22);background:rgba(255,211,108,.07);color:#eadfb7;font-size:12px;line-height:1.55}.notice.danger{border-color:rgba(255,116,134,.24);background:rgba(255,116,134,.07);color:#ffc3ca}.steps{display:grid;gap:11px}.step{display:grid;grid-template-columns:34px 1fr;gap:11px;padding:12px;border:1px solid rgba(112,148,188,.14);border-radius:12px;background:rgba(255,255,255,.015)}.num{display:grid;place-items:center;width:32px;height:32px;border-radius:10px;background:rgba(85,217,255,.1);color:var(--cyan);font-weight:900}.step h3{margin:0 0 5px;font-size:13px}.step p{margin:0;color:var(--muted);font-size:11px;line-height:1.5}.codebox{position:relative;margin-top:8px;border:1px solid rgba(85,229,167,.18);border-radius:10px;background:#03100b;padding:11px 44px 11px 12px;color:#8fffc6;font:12px/1.55 Consolas,monospace;white-space:pre-wrap;word-break:break-all}.codebox .copy{position:absolute;right:6px;top:6px;padding:5px 7px}.flow{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:12px}.flow span{padding:6px 9px;border-radius:999px;border:1px solid var(--line);color:#adc1d2;font-size:10px}.flow b{color:#4fdcff}.locked-card{text-align:center;padding:22px 16px}.locked-card strong{display:block;font-size:17px;margin-bottom:7px}.locked-card p{color:var(--muted);font-size:12px;line-height:1.55;margin:0 0 14px}@media(max-width:820px){.grid{grid-template-columns:1fr}.top{display:block}.actions{margin-top:14px}.kv{grid-template-columns:1fr}.kv dd{margin-bottom:5px}}
</style>
</head>
<body>
<div class="wrap">
  <header class="top">
    <div>
      <a class="back" href="../index.php">← Dashboard</a>
      <h1>Legacy AP Access</h1>
      <p>Akses dan pemulihan AP UniFi lama untuk Controller <?= h($controllerVersion) ?>.</p>
    </div>
    <div class="actions">
      <a class="btn green" href="<?= h($controllerUrl) ?>" target="_blank" rel="noopener noreferrer">Buka Legacy Controller ↗</a>
    </div>
  </header>

  <div class="grid">
    <section class="panel">
      <div class="panel-head"><h2>Controller &amp; Credential</h2><span class="badge"><i></i>INTERNAL USE</span></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>Controller</dt><dd><a class="back" href="<?= h($controllerUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($controllerUrl) ?></a></dd>
          <dt>Version</dt><dd><?= h($controllerVersion) ?></dd>
          <dt>Inform URL</dt><dd><code><?= h($informUrl) ?></code></dd>
          <dt>Username</dt><dd><div class="secret-row"><span class="secret" id="legacyUser"><?= h($username) ?></span><button class="copy" data-copy-target="legacyUser">Copy</button></div></dd>
          <dt>Password</dt><dd><div class="secret-row"><span class="secret" id="legacyPass" data-secret="<?= h($password) ?>">••••••••••••••••</span><button class="copy" id="revealPass" type="button" aria-pressed="false">Reveal</button><button class="copy" data-copy-secret="legacyPass" type="button">Copy</button></div></dd>
          <dt>Legacy Firmware</dt><dd><?= h($firmwareVersion) ?></dd>
        </dl>
        <div class="notice">Credential disimpan untuk kegunaan dalaman pemulihan AP lama. Password disorok secara lalai; gunakan butang Reveal/Hide bila perlu.</div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Prosedur AP Offline di Controller</h2><span class="badge"><i></i>RECOVERY GUIDE</span></div>
      <div class="panel-body">
        <div class="steps">
          <div class="step"><span class="num">1</span><div><h3>SSH ke AP lama</h3><p>Gunakan IP AP sebenar dan credential pentadbir yang sah. Contoh:</p><div class="codebox" id="sshCmd">ssh admin@IP_AP<button class="copy" data-copy-target="sshCmd">Copy</button></div></div></div>
          <div class="step"><span class="num">2</span><div><h3>Paksa firmware legacy terakhir</h3><p>Gunakan hanya untuk model AP yang sepadan dengan keluarga firmware U7PG2. Proses ini akan reboot AP.</p><div class="codebox" id="upgradeCmd"><?= h($upgradeCommand) ?><button class="copy" data-copy-target="upgradeCmd">Copy</button></div></div></div>
          <div class="step"><span class="num">3</span><div><h3>Tunggu AP reboot</h3><p>Tunggu AP boleh diping atau boleh SSH semula sebelum teruskan set-inform.</p></div></div>
          <div class="step"><span class="num">4</span><div><h3>Set Inform dua kali</h3><p>Jalankan arahan pertama, tunggu respons, kemudian jalankan arahan yang sama sekali lagi.</p><div class="codebox" id="informCmd"><?= h($informCommand . "\n" . $informCommand) ?><button class="copy" data-copy-target="informCmd">Copy</button></div></div></div>
          <div class="step"><span class="num">5</span><div><h3>Semak Controller <?= h($controllerVersion) ?></h3><p>Buka Legacy Controller dan pastikan AP berubah kepada <b style="color:var(--green)">Connected</b> atau <b style="color:var(--yellow)">Adopting</b>.</p></div></div>
        </div>
        <div class="flow"><span>OFFLINE</span><b>→</b><span>SSH</span><b>→</b><span>Firmware <?= h($firmwareVersion) ?></span><b>→</b><span>Reboot</span><b>→</b><span>Set-Inform ×2</span><b>→</b><span>CONNECTED</span></div>
        <div class="notice danger"><b>Peringatan:</b> jangan gunakan firmware ini pada model AP yang berlainan. Pastikan AP mendapat bekalan kuasa stabil sepanjang proses upgrade.</div>
      </div>
    </section>
  </div>
</div>
<script>
(function(){
  function cleanText(node){
    if(!node)return '';
    var clone=node.cloneNode(true);
    clone.querySelectorAll('button').forEach(function(btn){btn.remove();});
    return (clone.textContent||'').trim();
  }
  function copyText(value,button){
    if(!value)return;
    navigator.clipboard.writeText(value).then(function(){
      var old=button.textContent;button.textContent='Copied';setTimeout(function(){button.textContent=old;},1200);
    }).catch(function(){window.prompt('Copy nilai ini:',value);});
  }
  document.querySelectorAll('[data-copy-target]').forEach(function(button){
    button.addEventListener('click',function(){copyText(cleanText(document.getElementById(button.dataset.copyTarget)),button);});
  });
  document.querySelectorAll('[data-copy-secret]').forEach(function(button){
    button.addEventListener('click',function(){var n=document.getElementById(button.dataset.copySecret);copyText(n?n.dataset.secret:'',button);});
  });
  var reveal=document.getElementById('revealPass');
  var pass=document.getElementById('legacyPass');
  if(reveal&&pass){reveal.addEventListener('click',function(){
    var visible=reveal.getAttribute('aria-pressed')==='true';
    if(visible){
      pass.textContent='••••••••••••••••';
      reveal.textContent='Reveal';
      reveal.setAttribute('aria-pressed','false');
    }else{
      pass.textContent=pass.dataset.secret||'';
      reveal.textContent='Hide';
      reveal.setAttribute('aria-pressed','true');
    }
  });}
})();
</script>
</body>
</html>
