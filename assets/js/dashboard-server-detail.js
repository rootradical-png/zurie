(function(){
  'use strict';

  var root=document.querySelector('[data-dashboard-server-detail]');
  if(!root) return;

  var api=root.getAttribute('data-api')||'api/server_metrics_current.php';
  var misCard=document.querySelector('[data-pinned-mis]');
  var defaultId=root.getAttribute('data-default-server')||'';
  var servers=[];
  var currentId=defaultId;
  var currentPing=null;
  var timer=null;
  var metricHistory={};
  var historyMax=60;
  var ecgReady=false;

  function safeText(value,fallback){
    if(value===null||value===undefined||value==='') return fallback||'--';
    return String(value);
  }
  function number(value){
    var n=Number(value);return Number.isFinite(n)?n:null;
  }
  function pct(value){
    var n=number(value);return n===null?0:Math.max(0,Math.min(100,n));
  }
  function fmtPct(value){
    var n=number(value);return n===null?'--%':(Math.round(n*10)/10).toString().replace('.0','')+'%';
  }
  function fmtCpuPct(value){
    var n=number(value);return n===null?'--%':n.toFixed(2)+'%';
  }
  function fmtGb(mb){
    var n=number(mb);return n===null?'--':(n/1024).toFixed(n>=10240?0:1)+' GB';
  }
  function fmtUptime(seconds){
    var n=number(seconds);if(n===null)return '--';
    var d=Math.floor(n/86400),h=Math.floor((n%86400)/3600),m=Math.floor((n%3600)/60);
    if(d>0)return d+' hari '+h+' jam';
    if(h>0)return h+' jam '+m+' min';
    return m+' min';
  }
  function fmtAge(seconds){
    var n=number(seconds);if(n===null)return '--';
    if(n<60)return Math.floor(n)+' saat lalu';
    if(n<3600)return Math.floor(n/60)+' min lalu';
    if(n<86400)return Math.floor(n/3600)+' jam lalu';
    return Math.floor(n/86400)+' hari lalu';
  }
  function esc(value){
    return String(value==null?'':value).replace(/[&<>'"]/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch];});
  }
  function setText(selector,value){var node=root.querySelector(selector);if(node)node.textContent=value;}
  function setWidth(selector,value){var node=root.querySelector(selector);if(node)node.style.width=pct(value)+'%';}
  function misText(selector,value){if(!misCard)return;var node=misCard.querySelector(selector);if(node)node.textContent=value;}
  function misWidth(selector,value){if(!misCard)return;var node=misCard.querySelector(selector);if(node)node.style.width=pct(value)+'%';}


  function ensureEcgDom(){
    if(ecgReady) return;
    if(root.querySelector('.dsd-monitor-grid')){ ecgReady=true; return; }
    var metricGrid=root.querySelector('.dsd-metric-grid');
    if(!metricGrid) return;
    var wrap=document.createElement('div');
    wrap.className='dsd-monitor-grid dsd-heartbeat-phase3';
    wrap.setAttribute('aria-label','Graf ECG live server');
    wrap.innerHTML=''
      +'<section class="dsd-monitor-card cpu" data-dsd-monitor="cpu">'
      +  '<div class="dsd-monitor-head"><span class="dsd-monitor-kicker">CPU ECG</span><b data-dsd-monitor-cpu-now>--%</b></div>'
      +  '<div class="dsd-monitor-screen">'
      +    '<div class="dsd-monitor-gridline"></div><div class="dsd-heartbeat-led"><i></i><span>LIVE</span></div>'
      +    '<svg viewBox="0 0 560 120" preserveAspectRatio="none" aria-hidden="true">'
      +      '<path class="dsd-trace-shadow" data-dsd-cpu-trace-shadow d=""></path>'
      +      '<path class="dsd-trace-line" data-dsd-cpu-trace d=""></path>'
      +      '<circle class="dsd-trace-dot" data-dsd-cpu-dot cx="0" cy="0" r="4"></circle>'
      +    '</svg><div class="dsd-scanline"></div><div class="dsd-sweep-glow"></div>'
      +  '</div>'
      +  '<div class="dsd-monitor-foot"><span data-dsd-cpu-caption>Signal menunggu data agent</span><small data-dsd-cpu-samples>0 sampel</small></div>'
      +'</section>'
      +'<section class="dsd-monitor-card memory" data-dsd-monitor="memory">'
      +  '<div class="dsd-monitor-head"><span class="dsd-monitor-kicker">Memory ECG</span><b data-dsd-monitor-memory-now>--%</b></div>'
      +  '<div class="dsd-monitor-screen">'
      +    '<div class="dsd-monitor-gridline"></div><div class="dsd-heartbeat-led"><i></i><span>LIVE</span></div>'
      +    '<svg viewBox="0 0 560 120" preserveAspectRatio="none" aria-hidden="true">'
      +      '<path class="dsd-trace-shadow memory" data-dsd-memory-trace-shadow d=""></path>'
      +      '<path class="dsd-trace-line memory" data-dsd-memory-trace d=""></path>'
      +      '<circle class="dsd-trace-dot memory" data-dsd-memory-dot cx="0" cy="0" r="4"></circle>'
      +    '</svg><div class="dsd-scanline"></div><div class="dsd-sweep-glow"></div>'
      +  '</div>'
      +  '<div class="dsd-monitor-foot"><span data-dsd-memory-caption>Signal menunggu data agent</span><small data-dsd-memory-samples>0 sampel</small></div>'
      +'</section>';
    metricGrid.insertAdjacentElement('afterend',wrap);
    ecgReady=true;
  }

  function keepHistory(deviceId,cpu,memory){
    if(!deviceId) return;
    if(!metricHistory[deviceId]) metricHistory[deviceId]={cpu:[],memory:[],updated:0};
    var bucket=metricHistory[deviceId];
    if(number(cpu)!==null){bucket.cpu.push(number(cpu));if(bucket.cpu.length>historyMax)bucket.cpu.shift();}
    if(number(memory)!==null){bucket.memory.push(number(memory));if(bucket.memory.length>historyMax)bucket.memory.shift();}
    bucket.updated=Date.now();
  }

  function buildPulseTrace(values,width,height){
    var arr=Array.isArray(values)?values.slice(-historyMax):[];
    if(!arr.length) arr=[0];
    var count=Math.max(arr.length,24);
    var step=width/Math.max(count-1,1);
    var baseline=height-16;
    var d='';
    var lastX=0,lastY=baseline;
    for(var i=0;i<count;i++){
      var src=i-(count-arr.length);
      var v=src>=0?Number(arr[src]||0):0;
      var amplitude=(Math.max(0,Math.min(100,v))/100)*(height-34);
      var x=i*step;
      var y=baseline-amplitude;
      if(i%8===1) y-=10;
      if(i%8===2) y+=8;
      if(i%8===3) y-=16;
      if(i===count-1) y-=18;
      y=Math.max(10,Math.min(height-8,y));
      d+=(i===0?'M':' L')+x.toFixed(2)+' '+y.toFixed(2);
      lastX=x;lastY=y;
    }
    return {d:d,cx:lastX,cy:lastY,samples:arr.length};
  }

  function setAttr(selector,name,value){var node=root.querySelector(selector);if(node)node.setAttribute(name,String(value));}

  function renderMonitor(prefix, values, nowValue, caption){
    ensureEcgDom();
    var trace=buildPulseTrace(values,560,120);
    setAttr('[data-dsd-'+prefix+'-trace]','d',trace.d);
    setAttr('[data-dsd-'+prefix+'-trace-shadow]','d',trace.d);
    setAttr('[data-dsd-'+prefix+'-dot]','cx',trace.cx);
    setAttr('[data-dsd-'+prefix+'-dot]','cy',trace.cy);
    setText('[data-dsd-monitor-'+prefix+'-now]', prefix==='cpu'?fmtCpuPct(nowValue):fmtPct(nowValue));
    setText('[data-dsd-'+prefix+'-caption]', caption || 'Signal live');
    setText('[data-dsd-'+prefix+'-samples]', trace.samples+' sampel');
    var card=root.querySelector('[data-dsd-monitor="'+prefix+'"]');
    if(card){card.classList.remove('beat');void card.offsetWidth;card.classList.add('beat');}
  }

  function renderBlankMonitor(prefix, label){
    ensureEcgDom();
    var base='M0 104 L80 104 L160 104 L240 104 L320 104 L400 104 L480 104 L560 104';
    setAttr('[data-dsd-'+prefix+'-trace]','d',base);
    setAttr('[data-dsd-'+prefix+'-trace-shadow]','d',base);
    setAttr('[data-dsd-'+prefix+'-dot]','cx',560);
    setAttr('[data-dsd-'+prefix+'-dot]','cy',104);
    setText('[data-dsd-monitor-'+prefix+'-now]','--%');
    setText('[data-dsd-'+prefix+'-caption]',label||'Signal menunggu data agent');
    setText('[data-dsd-'+prefix+'-samples]','0 sampel');
  }

  function findMisServer(){
    if(!misCard)return null;
    var wantedIp=misCard.getAttribute('data-device-ip')||'10.14.48.75';
    return servers.find(function(s){
      return String(s.ip||'')===wantedIp||String(s.name||'').trim().toUpperCase()==='MIS';
    })||null;
  }

  function renderPinnedMis(){
    if(!misCard)return;
    var server=findMisServer();
    misCard.classList.remove('healthy','warning','critical','stale','neutral');
    if(!server){
      misCard.classList.add('critical');
      misText('[data-pmis-state]','NOT FOUND');
      misText('[data-pmis-updated]','Server MIS tidak dijumpai dalam inventori.');
      misText('[data-pmis-cpu]','--%');misText('[data-pmis-memory]','--%');misText('[data-pmis-disk]','--%');misText('[data-pmis-uptime]','--');
      misWidth('[data-pmis-cpu-bar]',0);misWidth('[data-pmis-memory-bar]',0);misWidth('[data-pmis-disk-bar]',0);
      return;
    }
    misCard.dataset.deviceId=server.device_id||'';
    misCard.classList.add(server.state&&server.state.class?server.state.class:'neutral');
    misText('[data-pmis-name]',safeText(server.name,'MIS'));
    misText('[data-pmis-ip]',safeText(server.ip,'10.14.48.75'));
    misText('[data-pmis-state]',safeText(server.state&&server.state.label,'WAIT').toUpperCase());
    var full=misCard.querySelector('[data-pmis-full]');
    if(full&&server.device_id)full.href='pages/server_detail.php?device_id='+encodeURIComponent(server.device_id);
    var metric=server.metrics;
    if(!metric){
      misText('[data-pmis-os]',safeText(server.model,'FreeBSD'));
      misText('[data-pmis-updated]','Agent MIS belum menghantar data. Klik untuk semak detail.');
      misText('[data-pmis-cpu]','--%');misText('[data-pmis-memory]','--%');misText('[data-pmis-disk]','--%');misText('[data-pmis-uptime]','--');misText('[data-pmis-hostname]','Agent belum aktif');
      misWidth('[data-pmis-cpu-bar]',0);misWidth('[data-pmis-memory-bar]',0);misWidth('[data-pmis-disk-bar]',0);
      return;
    }
    misText('[data-pmis-os]',safeText(metric.os_name,'FreeBSD'));
    misText('[data-pmis-cpu]',fmtCpuPct(metric.cpu_percent));
    misText('[data-pmis-memory]',fmtPct(metric.memory_percent));
    misText('[data-pmis-disk]',fmtPct(metric.disk_max_percent));
    misText('[data-pmis-uptime]',fmtUptime(metric.uptime_seconds));
    misText('[data-pmis-hostname]',safeText(metric.hostname,'MIS'));
    misWidth('[data-pmis-cpu-bar]',metric.cpu_percent);
    misWidth('[data-pmis-memory-bar]',metric.memory_percent);
    misWidth('[data-pmis-disk-bar]',metric.disk_max_percent);
    misText('[data-pmis-updated]','Data '+(server.state&&server.state.age!==undefined?fmtAge(server.state.age):safeText(metric.received_at,'--'))+' • '+safeText(metric.agent_version,'Agent'));
  }

  function normalizeStatus(status){
    var s=String(status||'').toUpperCase();
    return ['RUNNING','UP','OK','STARTED','TRUE'].indexOf(s)!==-1?'running':'stopped';
  }

  function renderDisks(disks){
    var box=root.querySelector('[data-dsd-disks]');if(!box)return;
    if(!Array.isArray(disks)||!disks.length){box.innerHTML='<div class="dsd-list-row"><span>Tiada data disk</span><b>--</b></div>';return;}
    box.innerHTML=disks.map(function(d){
      var name=safeText(d.name||d.mount,'Disk');
      var info=safeText(d.used_gb,'--')+' / '+safeText(d.total_gb,'--')+' GB';
      return '<div class="dsd-list-row"><span>'+esc(name)+' <small>'+esc(info)+'</small></span><b>'+esc(fmtPct(d.percent))+'</b></div>';
    }).join('');
  }

  function renderServices(services){
    var box=root.querySelector('[data-dsd-services]');if(!box)return;
    if(!Array.isArray(services)||!services.length){box.innerHTML='<div class="dsd-list-row"><span>Tiada proses/service dipantau</span><b>--</b></div>';return;}
    box.innerHTML=services.map(function(s){
      var cls=normalizeStatus(s.status);
      return '<div class="dsd-list-row"><span>'+esc(s.name||'Service')+'</span><b class="'+cls+'">'+esc(s.status||'UNKNOWN')+'</b></div>';
    }).join('');
  }

  function renderGenericPing(device){
    root.classList.remove('healthy','warning','critical','stale','neutral');
    root.classList.add(device&&device.status==='UP'?'healthy':'critical');
    setText('[data-dsd-type]',safeText(device&&device.type,'DEVICE').toUpperCase());
    setText('[data-dsd-name]',safeText(device&&device.name,'Pilih device pada Live Ping'));
    setText('[data-dsd-meta]',safeText(device&&device.ip,'--')+' • '+safeText(device&&device.type,'Device'));
    setText('[data-dsd-state]',device&&device.status==='UP'?'ONLINE':'OFFLINE');
    setText('[data-dsd-ping]',device?'Ping '+safeText(device.latency_ms,'--')+' ms • loss '+safeText(device.packet_loss_pct,'--')+'%':'Ping --');
    setText('[data-dsd-cpu]','--%');setText('[data-dsd-memory]','--%');setText('[data-dsd-disk]','--%');setText('[data-dsd-uptime]','--');
    setText('[data-dsd-memory-note]','Server agent belum dipasang');setText('[data-dsd-disk-note]','Tiada data ruang storan');setText('[data-dsd-uptime-note]','Tiada data uptime');
    setWidth('[data-dsd-cpu-bar]',0);setWidth('[data-dsd-memory-bar]',0);setWidth('[data-dsd-disk-bar]',0);
    setText('[data-dsd-notice]','Paparan ping generik. Server Metrics hanya tersedia selepas agent dipasang.');
    setText('[data-dsd-hostname]','--');setText('[data-dsd-os]','--');setText('[data-dsd-agent-version]','--');setText('[data-dsd-lastseen]','--');
    renderDisks([]);renderServices([]);
    renderBlankMonitor('cpu','Tiada data CPU');
    renderBlankMonitor('memory','Tiada data memory');
    root.querySelectorAll('[data-dsd-full-link]').forEach(function(full){
      full.href=device&&device.url?device.url:'#';
      full.target=device&&device.url?'_blank':'';
      if(!full.classList.contains('dsd-icon-btn')) full.textContent=device&&String(device.type).toLowerCase()==='server'?'Buka Server →':'Buka Device →';
    });
  }

  function findServer(device){
    if(!device)return null;
    if(device.inventory_id){var byId=servers.find(function(s){return s.device_id===device.inventory_id;});if(byId)return byId;}
    return servers.find(function(s){return (device.ip&&s.ip===device.ip)||(device.name&&s.name===device.name);})||null;
  }

  function renderServer(server,ping){
    if(!server){renderGenericPing(ping);return;}
    currentId=server.device_id;
    root.classList.remove('healthy','warning','critical','stale','neutral');
    root.classList.add(server.state&&server.state.class?server.state.class:'neutral');
    setText('[data-dsd-type]','SERVER');setText('[data-dsd-name]',safeText(server.name,server.device_id));
    setText('[data-dsd-meta]',safeText(server.ip,'--')+(server.model?' • '+server.model:''));
    setText('[data-dsd-state]',safeText(server.state&&server.state.label,'WAIT').toUpperCase());
    setText('[data-dsd-ping]',ping?'Ping '+safeText(ping.latency_ms,'--')+' ms • loss '+safeText(ping.packet_loss_pct,'--')+'%':'Ping status dari Live Ping belum dipilih');
    root.querySelectorAll('[data-dsd-full-link]').forEach(function(full){
      full.href='pages/server_detail.php?device_id='+encodeURIComponent(server.device_id);
      full.target='';
      if(!full.classList.contains('dsd-icon-btn')) full.textContent='Detail penuh →';
    });
    var metric=server.metrics;
    if(!metric){
      setText('[data-dsd-cpu]','--%');setText('[data-dsd-memory]','--%');setText('[data-dsd-disk]','--%');setText('[data-dsd-uptime]','--');
      setText('[data-dsd-memory-note]','Agent belum dipasang');setText('[data-dsd-disk-note]','Agent belum dipasang');setText('[data-dsd-uptime-note]','Agent belum dipasang');
      setWidth('[data-dsd-cpu-bar]',0);setWidth('[data-dsd-memory-bar]',0);setWidth('[data-dsd-disk-bar]',0);
      setText('[data-dsd-notice]','Agent Server Metrics belum dipasang pada '+server.name+'.');
      setText('[data-dsd-hostname]','--');setText('[data-dsd-os]','--');setText('[data-dsd-agent-version]','--');setText('[data-dsd-lastseen]','Tiada data');renderBlankMonitor('cpu','Agent belum dipasang');renderBlankMonitor('memory','Agent belum dipasang');renderDisks([]);renderServices([]);return;
    }
    setText('[data-dsd-cpu]',fmtCpuPct(metric.cpu_percent));setText('[data-dsd-memory]',fmtPct(metric.memory_percent));setText('[data-dsd-disk]',fmtPct(metric.disk_max_percent));setText('[data-dsd-uptime]',fmtUptime(metric.uptime_seconds));
    setWidth('[data-dsd-cpu-bar]',metric.cpu_percent);setWidth('[data-dsd-memory-bar]',metric.memory_percent);setWidth('[data-dsd-disk-bar]',metric.disk_max_percent);
    setText('[data-dsd-memory-note]',fmtGb(metric.memory_used_mb)+' / '+fmtGb(metric.memory_total_mb));
    var disks=Array.isArray(metric.disks)?metric.disks:[];var maxDisk=disks.slice().sort(function(a,b){return Number(b.percent||0)-Number(a.percent||0);})[0];
    setText('[data-dsd-disk-note]',maxDisk?safeText(maxDisk.name||maxDisk.mount,'Disk')+' • '+safeText(maxDisk.free_gb,'--')+' GB free':'Disk maksimum');
    setText('[data-dsd-uptime-note]',safeText(metric.hostname,'Server'));
    setText('[data-dsd-notice]','Data '+(server.state&&server.state.age!==undefined?fmtAge(server.state.age):safeText(metric.received_at,'--'))+' • '+safeText(metric.os_name,'OS tidak diketahui'));
    setText('[data-dsd-hostname]',safeText(metric.hostname,'--'));setText('[data-dsd-os]',safeText(metric.os_name,'--'));setText('[data-dsd-agent-version]',safeText(metric.agent_version,'--'));setText('[data-dsd-lastseen]',server.state&&server.state.age!==undefined?fmtAge(server.state.age):safeText(metric.received_at,'--'));
    keepHistory(server.device_id, metric.cpu_percent, metric.memory_percent);
    var history=metricHistory[server.device_id]||{cpu:[],memory:[]};
    renderMonitor('cpu', history.cpu, metric.cpu_percent, 'Heartbeat CPU • refresh automatik');
    renderMonitor('memory', history.memory, metric.memory_percent, 'Heartbeat Memory • refresh automatik');
    renderDisks(disks);renderServices(metric.services||[]);
  }

  function renderCurrent(){
    if(currentPing){
      var selectedServer=findServer(currentPing);
      if(selectedServer){renderServer(selectedServer,currentPing);return;}
      renderGenericPing(currentPing);return;
    }
    var server=currentId?servers.find(function(s){return s.device_id===currentId;})||null:null;
    if(!server)server=servers.find(function(s){return s.metrics;})||servers[0]||null;
    if(server)renderServer(server,null);else renderGenericPing(null);
  }

  function selectFromPing(device){currentPing=device||null;var server=findServer(device);if(server)currentId=server.device_id;renderCurrent();}
  window.NocServerDetail={selectFromPing:selectFromPing,refresh:function(){load();}};

  async function load(){
    root.classList.add('is-loading');
    try{
      var response=await fetch(api+(api.indexOf('?')>=0?'&':'?')+'_='+Date.now(),{cache:'no-store',credentials:'same-origin'});
      var data=await response.json();
      if(!response.ok||!data.ok)throw new Error(data.error||'Gagal membaca server metrics');
      servers=Array.isArray(data.servers)?data.servers:[];
      servers.forEach(function(s){if(s&&s.metrics)keepHistory(s.device_id,s.metrics.cpu_percent,s.metrics.memory_percent);});
      renderCurrent();
      renderPinnedMis();
    }catch(error){setText('[data-dsd-notice]','Server Metrics gagal: '+error.message);root.classList.remove('healthy','warning','stale');root.classList.add('critical');setText('[data-dsd-state]','ERROR');renderBlankMonitor('cpu','Signal terganggu');renderBlankMonitor('memory','Signal terganggu');}
    finally{root.classList.remove('is-loading');}
  }

  var expand=root.querySelector('[data-dsd-expand]');
  var backdrop=document.querySelector('[data-dsd-backdrop]');
  function setExpanded(open){root.classList.toggle('is-expanded',open);if(backdrop)backdrop.classList.toggle('is-open',open);document.body.classList.toggle('dsd-body-lock',open);if(expand){expand.textContent=open?'×':'⛶';expand.title=open?'Tutup paparan besar':'Besarkan paparan';}}
  if(expand)expand.addEventListener('click',function(){setExpanded(!root.classList.contains('is-expanded'));});
  if(backdrop)backdrop.addEventListener('click',function(){setExpanded(false);});
  document.addEventListener('keydown',function(ev){if(ev.key==='Escape')setExpanded(false);});
  document.addEventListener('noc:ping-select',function(ev){if(ev.detail&&ev.detail.device)selectFromPing(ev.detail.device);});

  function showMisInDetail(){
    var server=findMisServer();
    if(!server)return;
    currentPing=null;currentId=server.device_id;renderServer(server,null);
    root.scrollIntoView({behavior:'smooth',block:'center'});
  }
  if(misCard){
    misCard.addEventListener('click',function(ev){
      if(ev.target.closest('[data-pmis-full]'))return;
      showMisInDetail();
    });
    misCard.addEventListener('keydown',function(ev){if(ev.key==='Enter'||ev.key===' '){ev.preventDefault();showMisInDetail();}});
    var misView=misCard.querySelector('[data-pmis-view]');
    if(misView)misView.addEventListener('click',function(ev){ev.preventDefault();ev.stopPropagation();showMisInDetail();});
  }

  load();timer=setInterval(load,30000);window.addEventListener('beforeunload',function(){clearInterval(timer);});
})();
