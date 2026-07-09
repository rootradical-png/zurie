(function(){
  'use strict';

  function esc(value){
    return String(value == null ? '' : value).replace(/[&<>'"]/g,function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch];
    });
  }

  function formatTime(ts){
    if(!ts) return '--:--:--';
    var d=new Date(Number(ts)*1000);
    return d.toLocaleTimeString('ms-MY',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  }

  function detailBase(root){
    var configured=root.getAttribute('data-server-detail-base');
    if(configured) return configured;
    return location.pathname.indexOf('/pages/') !== -1 ? 'server_detail.php' : 'pages/server_detail.php';
  }

  function serverDetailUrl(root,device){
    if(String(device.type||'').toLowerCase()!=='server' || !device.inventory_id) return '';
    return detailBase(root)+'?device_id='+encodeURIComponent(device.inventory_id);
  }

  function compactCardHtml(device){
    var paused=device.status==='PAUSED';
    var down=device.status!=='UP' && !paused;
    var latency=paused?'PAUSED':(down?'TIMEOUT':(device.latency_ms==null?'--':device.latency_ms+' ms'));
    var loss=device.packet_loss_pct==null?'--':device.packet_loss_pct+'%';
    var history=Array.isArray(device.history)?device.history:[];
    var latest=history.length?history[history.length-1]:null;
    return '<article class="live-ping-card live-ping-card-compact '+(down?'is-down ':'')+'is-fresh" tabindex="0" role="button" aria-label="Lihat detail '+esc(device.name)+'" data-ping-id="'+esc(device.id)+'" data-inventory-id="'+esc(device.inventory_id||'')+'">'+
      '<div class="live-ping-compact-main">'+
        '<div class="live-ping-device"><b>'+esc(device.name)+'</b><small>'+esc(device.type)+' • '+esc(device.ip)+'</small></div>'+
        '<span class="live-ping-state"><i></i>'+esc(device.status)+'</span>'+
      '</div>'+
      '<div class="live-ping-compact-bottom">'+
        '<div class="live-ping-compact-value"><strong>'+esc(latency)+'</strong><small>'+esc(device.received||0)+'/'+esc(device.sent||0)+' reply • loss '+esc(loss)+'</small></div>'+
        '<div class="live-ping-mini-chart"><canvas class="live-ping-canvas" width="210" height="54"></canvas><span class="live-ping-sweep"></span></div>'+
        '<span class="live-ping-detail-cue">Detail ›</span>'+
      '</div>'+
      '<div class="live-ping-sample-row"><span>● '+esc(history.length)+' sampel</span><span>'+esc(formatTime(latest&&latest.ts))+'</span></div>'+
    '</article>';
  }

  function fullCardHtml(root,device){
    var paused=device.status==='PAUSED';
    var down=device.status!=='UP' && !paused;
    var latency=paused?'PAUSED':(down?'TIMEOUT':(device.latency_ms==null?'--':device.latency_ms));
    var recent=Number(device.last_opened||0)>0;
    var loss=device.packet_loss_pct==null?'--':device.packet_loss_pct;
    var sent=Number(device.sent||0);
    var received=Number(device.received||0);
    var history=Array.isArray(device.history)?device.history:[];
    var latest=history.length?history[history.length-1]:null;
    var errorHtml=device.error?'<div class="live-ping-device-error">'+esc(device.error)+'</div>':'';
    var detailUrl=serverDetailUrl(root,device);
    var action=detailUrl
      ? '<button type="button" class="live-ping-open" data-open-detail data-device-id="'+esc(device.id)+'" data-detail-url="'+esc(detailUrl)+'">↗ Detail Server</button>'
      : '<button type="button" class="live-ping-open" data-open-device data-device-id="'+esc(device.id)+'" data-device-url="'+esc(device.url||'')+'">↗ Buka Device</button>';
    return '<article class="live-ping-card '+(down?'is-down ':'')+(recent?'is-recent ':'')+'is-fresh" data-ping-id="'+esc(device.id)+'" data-inventory-id="'+esc(device.inventory_id||'')+'" '+(detailUrl?'data-detail-url="'+esc(detailUrl)+'" tabindex="0" role="link"':'')+'>'+
      '<div class="live-ping-card-head">'+
        '<div class="live-ping-device"><b>'+esc(device.name)+'</b><small>'+esc(device.type)+' • '+esc(device.ip)+'</small></div>'+
        '<div class="live-ping-head-right">'+(recent?'<span class="live-ping-recent">RECENT</span>':'')+'<span class="live-ping-state"><i></i>'+esc(device.status)+'</span></div>'+
      '</div>'+
      '<div class="live-ping-metrics">'+
        '<div class="live-ping-latency"><strong>'+esc(latency)+'</strong>'+((down||paused)?'':'<span>ms avg</span>')+'<small>'+(paused?'Monitoring dipause':esc(received)+'/'+esc(sent)+' reply • loss '+esc(loss)+'%')+'</small></div>'+
        '<div class="live-ping-uptime"><b>'+esc(device.uptime_pct)+'%</b><small>uptime sampel</small></div>'+
      '</div>'+
      '<div class="live-ping-canvas-wrap"><canvas class="live-ping-canvas" width="520" height="128"></canvas><span class="live-ping-sweep"></span><span class="live-ping-current-pulse"></span></div>'+
      '<div class="live-ping-sample-row"><span>● Sampel '+esc(history.length)+'/60</span><span>'+esc(formatTime(latest&&latest.ts))+'</span></div>'+
      errorHtml+
      '<div class="live-ping-card-actions">'+action+'</div>'+
    '</article>';
  }

  function draw(canvas,history){
    if(!canvas||!canvas.getContext) return;
    var ctx=canvas.getContext('2d');
    var w=canvas.width,h=canvas.height;
    ctx.clearRect(0,0,w,h);
    var pad=canvas.height<=60?5:10;
    var usableW=w-pad*2,usableH=h-pad*2;
    var values=history.map(function(p){return p.status==='UP'&&p.latency_ms!=null?Number(p.latency_ms):null;});
    var valid=values.filter(function(v){return v!=null&&isFinite(v);});
    var max=valid.length?Math.max.apply(Math,valid.concat([10])):10;
    max=Math.min(Math.max(max*1.25,10),500);
    ctx.strokeStyle='rgba(116,148,181,.13)';ctx.lineWidth=1;
    for(var g=1;g<=2;g++){var gy=pad+(usableH/3)*g;ctx.beginPath();ctx.moveTo(pad,gy);ctx.lineTo(w-pad,gy);ctx.stroke();}
    var step=values.length>1?usableW/(values.length-1):usableW;
    var grad=ctx.createLinearGradient(0,0,w,0);grad.addColorStop(0,'#37d88a');grad.addColorStop(1,'#38a8ff');
    ctx.strokeStyle=grad;ctx.lineWidth=canvas.height<=60?2:3;ctx.lineJoin='round';ctx.lineCap='round';
    var started=false;ctx.beginPath();
    values.forEach(function(v,i){
      if(v==null){started=false;return;}
      var x=pad+i*step,y=pad+usableH-(Math.min(v,max)/max)*usableH;
      if(!started){ctx.moveTo(x,y);started=true;}else{ctx.lineTo(x,y);}
    });
    ctx.stroke();
    history.forEach(function(p,i){if(p.status==='UP')return;var x=pad+i*step,y=h-pad-2;ctx.fillStyle='#ff5260';ctx.beginPath();ctx.arc(x,y,canvas.height<=60?2.5:4,0,Math.PI*2);ctx.fill();});
    for(var i=values.length-1;i>=0;i--){
      if(values[i]==null)continue;
      var lx=pad+i*step,ly=pad+usableH-(Math.min(values[i],max)/max)*usableH;
      ctx.fillStyle='#c9ecff';ctx.beginPath();ctx.arc(lx,ly,canvas.height<=60?2.7:4,0,Math.PI*2);ctx.fill();break;
    }
  }

  function selectDashboardCard(root,device){
    root.dataset.selectedPingId=device.id||'';
    root.querySelectorAll('[data-ping-id]').forEach(function(card){card.classList.toggle('is-selected',card.getAttribute('data-ping-id')===device.id);});
    var detail={device:device};
    document.dispatchEvent(new CustomEvent('noc:ping-select',{detail:detail}));
    if(window.NocServerDetail&&typeof window.NocServerDetail.selectFromPing==='function') window.NocServerDetail.selectFromPing(device);
    markOpened(root,device.id||'');
  }

  function render(root,payload){
    var grid=root.querySelector('[data-live-ping-grid]');
    var last=root.querySelector('.live-ping-last');
    var state=root.querySelector('[data-live-state]');
    if(!grid)return;
    var compact=root.getAttribute('data-compact')==='1';
    var devices=Array.isArray(payload.devices)?payload.devices:[];
    root._livePingDevices={};
    devices.forEach(function(d){root._livePingDevices[d.id]=d;});
    if(!devices.length){grid.innerHTML='<div class="live-ping-empty">Tiada device dipilih. Klik <b>Pilih Device</b>.</div>';grid.setAttribute('data-count','0');return;}
    grid.setAttribute('data-count',String(devices.length));
    grid.innerHTML=devices.map(function(device){return compact?compactCardHtml(device):fullCardHtml(root,device);}).join('');
    devices.forEach(function(device){
      var card=grid.querySelector('[data-ping-id="'+CSS.escape(device.id)+'"]');
      if(card){draw(card.querySelector('canvas'),device.history||[]);setTimeout(function(){card.classList.remove('is-fresh');},850);}
    });
    if(compact){
      var selected=root.dataset.selectedPingId;
      var selectedDevice=selected&&root._livePingDevices[selected]?root._livePingDevices[selected]:null;
      if(!selectedDevice){selectedDevice=devices.find(function(d){return d.name==='Website KMP';})||devices.find(function(d){return String(d.type).toLowerCase()==='server';})||devices[0];}
      if(selectedDevice){root.dataset.selectedPingId=selectedDevice.id;var selectedCard=grid.querySelector('[data-ping-id="'+CSS.escape(selectedDevice.id)+'"]');if(selectedCard)selectedCard.classList.add('is-selected');}
    }
    if(last){var diag=payload.diagnostic||{};var pausedInfo=payload.paused_count?(' • '+payload.paused_count+' pause'):'';last.textContent='Sumber '+(payload.source||'NOC Server')+' • '+(payload.packet_count||1)+' paket • '+(diag.mode||'ping')+pausedInfo+' • '+(payload.checked_at||'');}
    if(state)state.textContent='Live • '+devices.length+' device • '+(payload.elapsed_ms||0)+' ms proses';
  }

  function markOpened(root,deviceId){
    if(!deviceId)return Promise.resolve(null);
    var api=root.getAttribute('data-api')||'../api/live_ping.php';
    api=api.split('?')[0];
    var csrf=root.getAttribute('data-csrf')||'';
    if(!csrf)return Promise.resolve(null);
    var body=new URLSearchParams();body.set('action','mark_opened');body.set('device_id',deviceId);body.set('csrf',csrf);
    return fetch(api,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString(),cache:'no-store',credentials:'same-origin'}).then(function(r){return r.json();}).catch(function(){return null;});
  }

  function bindOpen(root){
    var grid=root.querySelector('[data-live-ping-grid]');
    if(!grid||grid.dataset.openBound==='1')return;
    grid.dataset.openBound='1';
    function activate(target){
      var card=target.closest('[data-ping-id]');
      if(!card)return;
      var deviceId=card.getAttribute('data-ping-id')||'';
      var device=root._livePingDevices&&root._livePingDevices[deviceId];
      if(root.getAttribute('data-compact')==='1'){
        if(device)selectDashboardCard(root,device);
        return;
      }
      var detailButton=target.closest('[data-open-detail]');
      var openButton=target.closest('[data-open-device]');
      var detailUrl=(detailButton&&detailButton.getAttribute('data-detail-url'))||card.getAttribute('data-detail-url')||'';
      if(detailUrl){location.href=detailUrl;markOpened(root,deviceId);return;}
      if(openButton){var url=openButton.getAttribute('data-device-url')||'';if(url)window.open(url,'_blank','noopener,noreferrer');markOpened(root,deviceId);}
    }
    grid.addEventListener('click',function(ev){activate(ev.target);});
    grid.addEventListener('keydown',function(ev){if(ev.key==='Enter'||ev.key===' '){ev.preventDefault();activate(ev.target);}});
  }

  function start(root){
    var api=root.getAttribute('data-api')||'api/live_ping.php';
    var interval=Math.max(8000,Number(root.getAttribute('data-interval'))||10000);
    var busy=false,cycle=0,nextAt=Date.now()+interval;
    var progress=root.querySelector('[data-live-progress]');
    var nextLabel=root.querySelector('[data-live-next]');
    var cycleLabel=root.querySelector('[data-live-cycle]');
    var state=root.querySelector('[data-live-state]');
    function updateProgress(){
      var now=Date.now(),remain=Math.max(0,nextAt-now),pct=Math.max(0,Math.min(100,100-(remain/interval*100)));
      if(progress)progress.style.width=pct+'%';
      if(nextLabel)nextLabel.textContent=busy?'Ping sedang dijalankan...':'Semakan seterusnya dalam '+Math.ceil(remain/1000)+'s';
      requestAnimationFrame(updateProgress);
    }
    function load(){
      if(busy)return;
      busy=true;root.classList.add('is-loading');if(state)state.textContent='Ping sedang dijalankan...';
      fetch(api+(api.indexOf('?')>=0?'&':'?')+'_='+(Date.now()),{cache:'no-store',credentials:'same-origin'})
        .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
        .then(function(data){if(!data.ok)throw new Error(data.message||'Ping gagal');cycle++;render(root,data);if(cycleLabel)cycleLabel.textContent='Cycle #'+cycle;})
        .catch(function(err){var grid=root.querySelector('[data-live-ping-grid]');if(grid)grid.innerHTML='<div class="live-ping-empty live-ping-error">Live ping gagal: '+esc(err.message)+'</div>';if(state)state.textContent='Ralat ping';})
        .finally(function(){busy=false;root.classList.remove('is-loading');nextAt=Date.now()+interval;});
    }
    bindOpen(root);updateProgress();load();setInterval(load,interval);
  }

  document.querySelectorAll('[data-live-ping]').forEach(start);
})();
