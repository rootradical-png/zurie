(() => {
  const api = window.SERVER_METRICS_API || '../api/server_metrics_current.php';
  const deviceId = window.SERVER_DETAIL_ID || document.body.dataset.selectedServer || '';
  const refreshSeconds = 30;
  let refreshTimer = null;
  let progressTimer = null;
  let nextRefreshAt = Date.now() + refreshSeconds * 1000;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[char]);
  const pct = value => Number.isFinite(Number(value)) ? Math.max(0, Math.min(100, Number(value))) : 0;
  const fmtPct = value => value === null || value === undefined ? '--%' : `${Number(value).toFixed(Number(value) % 1 ? 1 : 0)}%`;
  const fmtCpuPct = value => value === null || value === undefined || !Number.isFinite(Number(value)) ? '--%' : `${Number(value).toFixed(2)}%`;
  const fmtMb = value => {
    if (!Number.isFinite(Number(value))) return '-';
    const mb = Number(value);
    return mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${Math.round(mb)} MB`;
  };
  const fmtUptime = seconds => {
    if (!Number.isFinite(Number(seconds))) return '--';
    let remaining = Number(seconds);
    const days = Math.floor(remaining / 86400);
    remaining %= 86400;
    const hours = Math.floor(remaining / 3600);
    const minutes = Math.floor((remaining % 3600) / 60);
    return `${days} hari ${hours} jam ${minutes} min`;
  };
  const fmtAge = seconds => {
    const sec = Math.max(0, Number(seconds || 0));
    if (sec < 60) return `${sec} saat lalu`;
    if (sec < 3600) return `${Math.floor(sec / 60)} minit lalu`;
    return `${Math.floor(sec / 3600)} jam lalu`;
  };

  function setText(id, value) {
    const node = document.getElementById(id);
    if (node) node.textContent = value;
  }

  function setBar(id, value) {
    const node = document.getElementById(id);
    if (node) node.style.width = `${pct(value)}%`;
  }

  function copyIp() {
    const node = document.getElementById('sdCopyIp') || document.getElementById('sdIpChip');
    const ip = node?.dataset.ip || '';
    if (!ip) return;
    navigator.clipboard.writeText(ip).then(() => {
      const original = node.textContent;
      node.textContent = '✓ IP disalin';
      setTimeout(() => { node.textContent = original; }, 1500);
    }).catch(() => window.prompt('Salin alamat IP:', ip));
  }

  function drawHistory(history) {
    const canvas = document.getElementById('sdHistoryChart');
    if (!canvas) return;
    const context = canvas.getContext('2d');
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    const displayWidth = Math.max(620, Math.floor(rect.width));
    const displayHeight = Math.max(260, Math.floor(rect.height || 300));
    canvas.width = Math.floor(displayWidth * ratio);
    canvas.height = Math.floor(displayHeight * ratio);
    context.setTransform(ratio, 0, 0, ratio, 0, 0);

    const width = displayWidth;
    const height = displayHeight;
    const pad = { left: 38, right: 14, top: 18, bottom: 28 };
    context.clearRect(0, 0, width, height);
    context.font = '10px sans-serif';
    context.lineWidth = 1;
    context.strokeStyle = 'rgba(150,185,210,.14)';
    context.fillStyle = '#7892a7';

    for (let index = 0; index <= 4; index++) {
      const y = pad.top + (height - pad.top - pad.bottom) * index / 4;
      context.beginPath();
      context.moveTo(pad.left, y);
      context.lineTo(width - pad.right, y);
      context.stroke();
      context.fillText(`${100 - index * 25}%`, 3, y + 3);
    }

    if (!history.length) {
      context.fillText('Belum ada history 24 jam', pad.left + 18, height / 2);
      setText('sdSampleCount', '0 sampel');
      return;
    }

    const series = [
      ['cpu', '#55d9ff'],
      ['memory', '#51e3a4'],
      ['disk', '#ffd36c']
    ];

    series.forEach(([key, color]) => {
      context.strokeStyle = color;
      context.lineWidth = 2;
      context.beginPath();
      let started = false;
      history.forEach((row, index) => {
        const value = Number(row[key]);
        if (!Number.isFinite(value)) return;
        const x = pad.left + (width - pad.left - pad.right) * (history.length === 1 ? 0.5 : index / (history.length - 1));
        const y = pad.top + (height - pad.top - pad.bottom) * (1 - pct(value) / 100);
        if (!started) {
          context.moveTo(x, y);
          started = true;
        } else {
          context.lineTo(x, y);
        }
      });
      context.stroke();
    });

    const first = history[0];
    const last = history[history.length - 1];
    context.fillStyle = '#7892a7';
    context.fillText(first?.time?.slice(11, 16) || '', pad.left, height - 7);
    context.fillText(last?.time?.slice(11, 16) || '', width - pad.right - 30, height - 7);
    setText('sdSampleCount', `${history.length} sampel`);
  }

  function updateState(server) {
    const hero = document.getElementById('sdHero');
    if (hero) {
      hero.classList.remove('healthy', 'warning', 'critical', 'stale', 'neutral', 'sd-refreshed');
      hero.classList.add(server.state.class || 'neutral');
      void hero.offsetWidth;
      hero.classList.add('sd-refreshed');
    }
    setText('sdState', server.state.label || server.state.code);
    setText('sdLastSeen', server.state.age !== undefined ? fmtAge(server.state.age) : 'Menunggu data...');
  }

  function updateSystemInfo(server) {
    const metric = server.metrics;
    const agent = server.agent || {};
    const list = document.getElementById('sdSystemInfo');
    if (!list) return;

    list.innerHTML = `
      <dt>Nama Server</dt><dd>${esc(server.name || '-')}</dd>
      <dt>IP Address</dt><dd>${esc(server.ip || '-')}</dd>
      <dt>Model Inventori</dt><dd>${esc(server.model || '-')}</dd>
      <dt>Serial</dt><dd>${esc(server.serial || '-')}</dd>
      <dt>Hostname</dt><dd>${esc(metric?.hostname || '-')}</dd>
      <dt>Operating System</dt><dd>${esc(metric?.os_name || '-')}</dd>
      <dt>Agent Version</dt><dd>${esc(metric?.agent_version || '-')}</dd>
      <dt>Collected At</dt><dd>${esc(metric?.collected_at || '-')}</dd>
      <dt>Received At</dt><dd>${esc(metric?.received_at || '-')}</dd>
      <dt>Status Agent</dt><dd>${agent.enabled === undefined ? '-' : (Number(agent.enabled) === 1 ? 'Enabled' : 'Disabled')}</dd>`;
  }

  function updateDisks(metric) {
    const body = document.getElementById('sdDiskBody');
    if (!body) return;
    const disks = Array.isArray(metric?.disks) ? metric.disks : [];
    body.innerHTML = disks.length ? disks.map(disk => `
      <tr>
        <td><b>${esc(disk.name || disk.mount || 'Disk')}</b><small>${esc(disk.mount || '')}</small></td>
        <td>${disk.total_gb ?? '-'} GB</td>
        <td>${disk.used_gb ?? '-'} GB</td>
        <td>${disk.free_gb ?? '-'} GB</td>
        <td><span class="sd-disk-percent ${Number(disk.percent || 0) >= 90 ? 'critical' : Number(disk.percent || 0) >= 80 ? 'warning' : 'healthy'}">${fmtPct(disk.percent)}</span></td>
      </tr>`).join('') : '<tr><td colspan="5">Tiada data partition.</td></tr>';
  }

  function updateServices(metric) {
    const list = document.getElementById('sdServiceList');
    if (!list) return;
    const services = Array.isArray(metric?.services) ? metric.services : [];
    list.innerHTML = services.length ? services.map(service => {
      const status = String(service.status || 'UNKNOWN');
      const running = status.toUpperCase().includes('RUN');
      return `<div class="sm-service-item"><span>${esc(service.name || 'Service')}</span><b class="${running ? 'running' : 'stopped'}">${esc(status)}</b></div>`;
    }).join('') : 'Tiada service atau process dipilih dalam agent config.';
  }

  function updateAgent(server) {
    const list = document.getElementById('sdAgentInfo');
    if (!list) return;
    const agent = server.agent || {};
    list.innerHTML = `
      <dt>Device ID</dt><dd><code>${esc(server.device_id)}</code></dd>
      <dt>Agent Enabled</dt><dd>${agent.enabled === undefined ? 'Belum didaftarkan' : (Number(agent.enabled) === 1 ? 'Ya' : 'Tidak')}</dd>
      <dt>Last Agent IP</dt><dd>${esc(agent.last_ip || '-')}</dd>
      <dt>Last Seen DB</dt><dd>${esc(agent.last_seen_at || '-')}</dd>
      <dt>Current Health</dt><dd>${esc(server.state.label || server.state.code)}</dd>`;
  }

  function updateMetrics(server, history) {
    updateState(server);
    updateSystemInfo(server);
    updateAgent(server);

    const metric = server.metrics;
    const notice = document.getElementById('sdNotice');
    if (!metric) {
      setText('sdCpu', '--%');
      setText('sdMemory', '--%');
      setText('sdDisk', '--%');
      setText('sdUptime', '--');
      setText('sdCpuNote', 'Agent belum dipasang');
      setText('sdMemoryNote', '-- / --');
      setText('sdDiskNote', 'Tiada data partition');
      setText('sdCollected', 'Belum ada data');
      setBar('sdCpuBar', 0);
      setBar('sdMemoryBar', 0);
      setBar('sdDiskBar', 0);
      updateDisks(null);
      updateServices(null);
      drawHistory([]);
      if (notice) {
        notice.className = 'sm-notice neutral';
        notice.textContent = 'Agent belum menghantar data untuk server ini. Jana token dan pasang agent pada server berkenaan.';
      }
      return;
    }

    setText('sdCpu', fmtCpuPct(metric.cpu_percent));
    setText('sdMemory', fmtPct(metric.memory_percent));
    setText('sdDisk', fmtPct(metric.disk_max_percent));
    setText('sdUptime', fmtUptime(metric.uptime_seconds));
    setText('sdCpuNote', `Hostname: ${metric.hostname || '-'}`);
    setText('sdMemoryNote', `${fmtMb(metric.memory_used_mb)} / ${fmtMb(metric.memory_total_mb)}`);
    setText('sdDiskNote', 'Penggunaan partition tertinggi');
    setText('sdCollected', `Data: ${metric.collected_at || metric.received_at || '-'}`);
    setBar('sdCpuBar', metric.cpu_percent);
    setBar('sdMemoryBar', metric.memory_percent);
    setBar('sdDiskBar', metric.disk_max_percent);
    updateDisks(metric);
    updateServices(metric);
    drawHistory(history || []);

    if (notice) {
      notice.className = `sm-notice ${server.state.class || 'neutral'}`;
      notice.textContent = server.state.code === 'HEALTHY'
        ? 'Server beroperasi dengan bacaan semasa dalam julat sihat.'
        : server.state.code === 'WARNING'
          ? 'Server memerlukan perhatian. Semak CPU, memory atau disk.'
          : server.state.code === 'STALE'
            ? 'Data agent sudah stale. Semak Task Scheduler atau sambungan agent.'
            : 'Bacaan kritikal dikesan. Semak server dengan segera.';
    }
  }

  function updateProgress() {
    const track = document.getElementById('sdRefreshTrack');
    if (!track) return;
    const remaining = Math.max(0, nextRefreshAt - Date.now());
    const complete = 100 - (remaining / (refreshSeconds * 1000) * 100);
    track.style.width = `${Math.max(0, Math.min(100, complete))}%`;
  }

  async function load() {
    const button = document.getElementById('sdRefreshBtn');
    if (button) {
      button.disabled = true;
      button.textContent = '↻ Membaca...';
    }

    try {
      const response = await fetch(`${api}?device_id=${encodeURIComponent(deviceId)}`, { cache: 'no-store' });
      const data = await response.json();
      if (!data.ok) throw new Error(data.error || 'Gagal membaca data');
      const server = (data.servers || []).find(item => item.device_id === deviceId);
      if (!server) throw new Error('Server tidak ditemui dalam inventori');
      updateMetrics(server, data.history || []);
    } catch (error) {
      const notice = document.getElementById('sdNotice');
      if (notice) {
        notice.className = 'sm-notice critical';
        notice.textContent = `Gagal membaca detail server: ${error.message}`;
      }
      setText('sdState', 'ERROR');
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = '↻ Refresh';
      }
      nextRefreshAt = Date.now() + refreshSeconds * 1000;
      updateProgress();
    }
  }

  document.getElementById('sdRefreshBtn')?.addEventListener('click', load);
  document.getElementById('sdCopyIp')?.addEventListener('click', copyIp);
  document.getElementById('sdIpChip')?.addEventListener('click', copyIp);
  window.addEventListener('resize', () => load());
  window.addEventListener('beforeunload', () => {
    clearInterval(refreshTimer);
    clearInterval(progressTimer);
  });

  load();
  refreshTimer = setInterval(load, refreshSeconds * 1000);
  progressTimer = setInterval(updateProgress, 250);
})();
