<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive', true);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$configFile = dirname(__DIR__) . '/config/ubiquiti_ap_access.php';
$config = file_exists($configFile) ? require $configFile : [];
$controllerUrl = (string)($config['controller_url'] ?? 'https://10.14.49.10:8443/manage/default/devices');
$informUrl = (string)($config['inform_url'] ?? 'http://10.14.49.10:8080/inform');
$username = (string)($config['username'] ?? '');
$password = (string)($config['password'] ?? '');
$informCommand = 'set-inform ' . $informUrl;
?>
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Ubiquiti AP Access | Personal NOC</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
:root{--bg:#07111e;--panel:#0b1d30;--line:rgba(112,148,188,.18);--muted:#829ab0;--cyan:#55d9ff;--green:#55e5a7;--yellow:#ffd36c;--red:#ff7486}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top right,#0e2943 0,#07111e 42%,#040b13 100%);color:#edf8ff;font-family:Inter,Segoe UI,Arial,sans-serif;min-height:100vh}.wrap{width:min(1080px,calc(100% - 28px));margin:24px auto 46px}.top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}.back{color:#8bdcff;text-decoration:none;font-weight:800;font-size:13px}.top h1{margin:8px 0 4px;font-size:28px}.top p{margin:0;color:var(--muted);font-size:13px}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:0 13px;border:1px solid rgba(85,217,255,.28);border-radius:10px;background:rgba(85,217,255,.09);color:#bcefff;text-decoration:none;font-weight:800;font-size:12px;cursor:pointer}.btn:hover{background:rgba(85,217,255,.17)}.btn.green{border-color:rgba(85,229,167,.32);background:rgba(85,229,167,.09);color:#aaffd4}.grid{display:grid;grid-template-columns:1fr 1.35fr;gap:14px}.panel{border:1px solid var(--line);border-radius:16px;background:linear-gradient(145deg,rgba(12,31,51,.98),rgba(6,18,31,.98));box-shadow:0 18px 50px rgba(0,0,0,.22);overflow:hidden}.panel-head{padding:14px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px}.panel-head h2{margin:0;font-size:14px}.panel-body{padding:16px}.badge{display:inline-flex;align-items:center;gap:6px;padding:5px 8px;border-radius:999px;background:rgba(85,229,167,.1);color:var(--green);font-size:10px;font-weight:900}.badge i{width:7px;height:7px;border-radius:50%;background:currentColor;box-shadow:0 0 10px currentColor}.kv{display:grid;grid-template-columns:110px 1fr;gap:9px 12px;margin:0}.kv dt{color:var(--muted);font-size:12px}.kv dd{margin:0;font-size:12px;word-break:break-word}.secret-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.secret{padding:8px 10px;border-radius:9px;border:1px solid rgba(112,148,188,.17);background:#05101c;color:#f4fbff;font-family:Consolas,monospace;min-width:180px}.copy{border:1px solid rgba(85,217,255,.25);background:rgba(85,217,255,.08);color:#9ce7ff;border-radius:8px;padding:7px 9px;font-size:11px;font-weight:800;cursor:pointer}.notice{margin-top:14px;padding:11px 12px;border-radius:11px;border:1px solid rgba(255,211,108,.22);background:rgba(255,211,108,.07);color:#eadfb7;font-size:12px;line-height:1.55}.notice.danger{border-color:rgba(255,116,134,.24);background:rgba(255,116,134,.07);color:#ffc3ca}.steps{display:grid;gap:11px}.step{display:grid;grid-template-columns:34px 1fr;gap:11px;padding:12px;border:1px solid rgba(112,148,188,.14);border-radius:12px;background:rgba(255,255,255,.015)}.num{display:grid;place-items:center;width:32px;height:32px;border-radius:10px;background:rgba(85,217,255,.1);color:var(--cyan);font-weight:900}.step h3{margin:0 0 5px;font-size:13px}.step p{margin:0;color:var(--muted);font-size:11px;line-height:1.5}.codebox{position:relative;margin-top:8px;border:1px solid rgba(85,229,167,.18);border-radius:10px;background:#03100b;padding:11px 44px 11px 12px;color:#8fffc6;font:12px/1.55 Consolas,monospace;white-space:pre-wrap;word-break:break-all}.codebox .copy{position:absolute;right:6px;top:6px;padding:5px 7px}.flow{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:12px}.flow span{padding:6px 9px;border-radius:999px;border:1px solid var(--line);color:#adc1d2;font-size:10px}.flow b{color:#4fdcff}@media(max-width:820px){.grid{grid-template-columns:1fr}.top{display:block}.actions{margin-top:14px}.kv{grid-template-columns:1fr}.kv dd{margin-bottom:5px}}
</style>
</head>
<body>
<div class="wrap">
  <header class="top">
    <div>
      <a class="back" href="../index.php">← Dashboard</a>
      <h1>Ubiquiti AP Access</h1>
      <p>Akses SSH AP Ubiquiti dan hantar semula inform ke Controller semasa.</p>
    </div>
    <div class="actions">
      <a class="btn green" href="<?= h($controllerUrl) ?>" target="_blank" rel="noopener noreferrer">Buka WiFi Controller ↗</a>
    </div>
  </header>

  <div class="grid">
    <section class="panel">
      <div class="panel-head"><h2>SSH Credential</h2><span class="badge"><i></i>INTERNAL USE</span></div>
      <div class="panel-body">
        <dl class="kv">
          <dt>Controller</dt><dd><a class="back" href="<?= h($controllerUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($controllerUrl) ?></a></dd>
          <dt>Inform URL</dt><dd><code><?= h($informUrl) ?></code></dd>
          <dt>Username</dt><dd><div class="secret-row"><span class="secret" id="apUser"><?= h($username) ?></span><button class="copy" data-copy-target="apUser" type="button">Copy</button></div></dd>
          <dt>Password</dt><dd><div class="secret-row"><span class="secret" id="apPass" data-secret="<?= h($password) ?>">••••••••••••••••</span><button class="copy" id="revealPass" type="button" aria-pressed="false">Reveal</button><button class="copy" data-copy-secret="apPass" type="button">Copy</button></div></dd>
        </dl>
        <div class="notice">Password disorok secara lalai. Gunakan Reveal/Hide hanya semasa diperlukan.</div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Prosedur Sambung AP ke Controller</h2><span class="badge"><i></i>QUICK GUIDE</span></div>
      <div class="panel-body">
        <div class="steps">
          <div class="step"><span class="num">1</span><div><h3>SSH menggunakan PuTTY</h3><p>Buka PuTTY, masukkan IP AP dan gunakan port 22.</p><div class="codebox" id="sshCmd">ssh <?= h($username) ?>@IP_AP<button class="copy" data-copy-target="sshCmd" type="button">Copy</button></div></div></div>
          <div class="step"><span class="num">2</span><div><h3>Login ke AP</h3><p>Username dan password boleh disalin dari panel sebelah kiri.</p></div></div>
          <div class="step"><span class="num">3</span><div><h3>Set Inform</h3><p>Jalankan arahan ini selepas berjaya login:</p><div class="codebox" id="informCmd"><?= h($informCommand) ?><button class="copy" data-copy-target="informCmd" type="button">Copy</button></div></div></div>
          <div class="step"><span class="num">4</span><div><h3>Semak Controller</h3><p>Buka WiFi Controller dan tunggu AP muncul sebagai <b style="color:var(--green)">Pending Adoption</b>, <b style="color:var(--yellow)">Adopting</b> atau <b style="color:var(--green)">Connected</b>.</p></div></div>
        </div>
        <div class="flow"><span>PuTTY</span><b>→</b><span>SSH AP</span><b>→</b><span>Set-Inform</span><b>→</b><span>Controller</span><b>→</b><span>Connected</span></div>
        <div class="notice danger"><b>Peringatan:</b> halaman ini mengandungi credential dalaman. Pastikan hanya boleh dicapai dalam rangkaian atau pengguna yang dibenarkan.</div>
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
  var pass=document.getElementById('apPass');
  if(reveal&&pass){reveal.addEventListener('click',function(){
    var visible=reveal.getAttribute('aria-pressed')==='true';
    if(visible){pass.textContent='••••••••••••••••';reveal.textContent='Reveal';reveal.setAttribute('aria-pressed','false');}
    else{pass.textContent=pass.dataset.secret||'';reveal.textContent='Hide';reveal.setAttribute('aria-pressed','true');}
  });}
})();
</script>
</body>
</html>
