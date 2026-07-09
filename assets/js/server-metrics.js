(() => {
  const api = window.SERVER_METRICS_API || '../api/server_metrics_current.php';
  let timer = null;

  const pct = value => Number.isFinite(Number(value)) ? Math.max(0, Math.min(100, Number(value))) : 0;
  const fmtPct = value => value === null || value === undefined ? '--%' : `${Number(value).toFixed(Number(value) % 1 ? 1 : 0)}%`;
  const fmtCpuPct = value => value === null || value === undefined || !Number.isFinite(Number(value)) ? '--%' : `${Number(value).toFixed(2)}%`;
  const fmtAge = seconds => {
    const sec = Number(seconds || 0);
    if (sec < 60) return `${sec}s lalu`;
    if (sec < 3600) return `${Math.floor(sec / 60)}m lalu`;
    return `${Math.floor(sec / 3600)}j lalu`;
  };
  const fmtUptime = seconds => {
    if (!Number.isFinite(Number(seconds))) return 'Uptime --';
    const sec = Number(seconds);
    const days = Math.floor(sec / 86400);
    const hours = Math.floor((sec % 86400) / 3600);
    return `Uptime ${days}h ${hours}j`;
  };

  function updateCard(server) {
    const card = document.querySelector(`[data-server-id="${CSS.escape(server.device_id)}"]`);
    if (!card) return;

    card.classList.remove('healthy', 'warning', 'critical', 'stale', 'neutral', 'sm-card-updated');
    card.classList.add(server.state.class || 'neutral');
    void card.offsetWidth;
    card.classList.add('sm-card-updated');

    const state = card.querySelector('.sm-state');
    if (state) state.textContent = server.state.label || server.state.code;

    const metric = server.metrics;
    const setMetric = (valueSelector, value, barSelector, barValue) => {
      const valueNode = card.querySelector(valueSelector);
      const barNode = card.querySelector(barSelector);
      if (valueNode) valueNode.textContent = value;
      if (barNode) barNode.style.width = `${pct(barValue)}%`;
    };

    if (!metric) {
      setMetric('.sm-cpu-value', '--%', '.sm-cpu-bar', 0);
      setMetric('.sm-memory-value', '--%', '.sm-memory-bar', 0);
      setMetric('.sm-disk-value', '--%', '.sm-disk-bar', 0);
      card.querySelector('.sm-uptime').textContent = 'Agent belum dipasang';
      card.querySelector('.sm-lastseen').textContent = 'Tiada data';
      return;
    }

    setMetric('.sm-cpu-value', fmtCpuPct(metric.cpu_percent), '.sm-cpu-bar', metric.cpu_percent);
    setMetric('.sm-memory-value', fmtPct(metric.memory_percent), '.sm-memory-bar', metric.memory_percent);
    setMetric('.sm-disk-value', fmtPct(metric.disk_max_percent), '.sm-disk-bar', metric.disk_max_percent);
    card.querySelector('.sm-uptime').textContent = fmtUptime(metric.uptime_seconds);
    card.querySelector('.sm-lastseen').textContent = server.state.age !== undefined ? fmtAge(server.state.age) : metric.received_at;
  }

  function updateSummary(servers) {
    let monitored = 0;
    let healthy = 0;
    let warning = 0;
    let critical = 0;

    servers.forEach(server => {
      if (server.metrics) monitored++;
      if (server.state.code === 'HEALTHY') healthy++;
      else if (server.state.code === 'WARNING') warning++;
      else if (['CRITICAL', 'STALE'].includes(server.state.code)) critical++;
    });

    document.getElementById('smMonitored').textContent = monitored;
    document.getElementById('smHealthy').textContent = healthy;
    document.getElementById('smWarning').textContent = warning;
    document.getElementById('smCritical').textContent = critical;

    const notice = document.getElementById('smNotice');
    if (!notice) return;
    notice.className = `sm-notice ${critical ? 'critical' : warning ? 'warning' : 'healthy'}`;
    notice.textContent = critical
      ? `${critical} server kritikal atau data stale.`
      : warning
        ? `${warning} server memerlukan perhatian.`
        : `${healthy} server sihat. ${servers.length - monitored} belum dipasang agent.`;
  }

  async function load() {
    const button = document.getElementById('smRefreshBtn');
    if (button) {
      button.disabled = true;
      button.textContent = '↻ Membaca...';
    }

    try {
      const response = await fetch(api, { cache: 'no-store' });
      const data = await response.json();
      if (!data.ok) throw new Error(data.error || 'Gagal membaca data');
      (data.servers || []).forEach(updateCard);
      updateSummary(data.servers || []);
    } catch (error) {
      const notice = document.getElementById('smNotice');
      if (notice) {
        notice.className = 'sm-notice critical';
        notice.textContent = `Server Metrics gagal: ${error.message}`;
      }
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = '↻ Refresh';
      }
    }
  }

  document.getElementById('smRefreshBtn')?.addEventListener('click', load);
  load();
  timer = setInterval(load, 30000);
  window.addEventListener('beforeunload', () => clearInterval(timer));
})();
