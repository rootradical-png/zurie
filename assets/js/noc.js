(function () {
  'use strict';

  var statusText = document.getElementById('nocLastUpdate');
  var alerts = document.getElementById('nocAlerts');
  var btn = document.getElementById('nocRefreshBtn');
  var topBtn = document.getElementById('topRefreshBtn');
  var auto = document.getElementById('nocAutoRefresh');
  var tbody = document.getElementById('deviceListBody');
  var pageType = document.body ? (document.body.getAttribute('data-device-type') || '') : '';
  var pageStatusFilter = document.body ? (document.body.getAttribute('data-device-status') || '') : '';
  var canEditMonitoring = document.body ? document.body.getAttribute('data-can-edit-monitoring') === '1' : false;
  var lastDeviceData = null;
  var deviceSortKey = 'name';
  var deviceSortDirection = 'asc';
  var refreshInProgress = false;
  var gaugeRefreshMinimumMs = 900;

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (character) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[character];
    });
  }


  function ipSortParts(value) {
    var host = String(value || '').split(':')[0];
    var octets = host.split('.').map(function (part) { return parseInt(part, 10); });
    return octets.length === 4 && octets.every(function (part) { return Number.isFinite(part); })
      ? octets : [999, 999, 999, 999];
  }

  function statusSortRank(value) {
    return ({ DOWN: 0, READY: 1, UP: 2 })[String(value || '').toUpperCase()] ?? 9;
  }

  function compareDeviceValues(a, b, key) {
    if (key === 'ip') {
      var aa = ipSortParts(a.ip);
      var bb = ipSortParts(b.ip);
      for (var i = 0; i < 4; i++) {
        if (aa[i] !== bb[i]) return aa[i] - bb[i];
      }
      return String(a.ip || '').localeCompare(String(b.ip || ''), undefined, { numeric: true, sensitivity: 'base' });
    }
    if (key === 'status') {
      return statusSortRank(a.status) - statusSortRank(b.status);
    }
    var av = key === 'type' ? typeLabel(a.type) : (a[key] || '');
    var bv = key === 'type' ? typeLabel(b.type) : (b[key] || '');
    return String(av).localeCompare(String(bv), undefined, { numeric: true, sensitivity: 'base' });
  }

  function sortDeviceRows(rows) {
    return rows.slice().sort(function (a, b) {
      var result = compareDeviceValues(a, b, deviceSortKey);
      return deviceSortDirection === 'asc' ? result : -result;
    });
  }

  function updateDeviceSortControls() {
    var buttons = document.querySelectorAll('[data-device-sort-key]');
    buttons.forEach(function (button) {
      var active = button.getAttribute('data-device-sort-key') === deviceSortKey;
      button.classList.toggle('active-sort', active);
      var arrow = button.querySelector('.sort-arrow');
      if (arrow) arrow.textContent = active ? (deviceSortDirection === 'asc' ? '▲' : '▼') : '';
    });
    var select = document.getElementById('deviceSortSelect');
    if (select) {
      var wanted = deviceSortKey + ':' + deviceSortDirection;
      var exists = Array.prototype.some.call(select.options, function (option) { return option.value === wanted; });
      if (exists) select.value = wanted;
    }
  }

  function apiPath() {
    var isDevicePage = Boolean(pageType);
    var base = isDevicePage ? '../api/noc_status.php' : 'api/noc_status.php';
    if (isDevicePage && pageType !== 'ALL') {
      return base + '?type=' + encodeURIComponent(pageType);
    }
    return base;
  }

  function normalSummary(summary, type) {
    return summary && summary[type] ? summary[type] : { total: 0, up: 0, down: 0 };
  }

  function sleep(milliseconds) {
    return new Promise(function (resolve) { window.setTimeout(resolve, milliseconds); });
  }

  function gaugeCards() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-noc-card]'));
  }

  function beginGaugeRefresh() {
    gaugeCards().forEach(function (card, index) {
      card.classList.remove('gauge-settled', 'gauge-refresh-error');
      // Force a new animation cycle for every refresh.
      void card.offsetWidth;
      window.setTimeout(function () {
        card.classList.add('gauge-refreshing');
        var liveLabel = card.querySelector('.gauge-card-head small');
        if (liveLabel) liveLabel.textContent = 'PING';
      }, index * 55);
    });
  }

  function endGaugeRefresh(success) {
    gaugeCards().forEach(function (card, index) {
      window.setTimeout(function () {
        card.classList.remove('gauge-refreshing');
        card.classList.toggle('gauge-refresh-error', !success);
        card.classList.add('gauge-settled');
        var liveLabel = card.querySelector('.gauge-card-head small');
        if (liveLabel) liveLabel.textContent = success ? 'LIVE' : 'ERROR';
        window.setTimeout(function () {
          card.classList.remove('gauge-settled');
        }, 850);
      }, index * 80);
    });
  }

  function animatePercentage(element, target) {
    if (!element) return;
    var start = parseInt(element.textContent, 10);
    if (!Number.isFinite(start)) start = 0;
    var startedAt = performance.now();
    var duration = 620;

    function frame(now) {
      var progress = Math.min(1, (now - startedAt) / duration);
      var eased = 1 - Math.pow(1 - progress, 3);
      element.textContent = Math.round(start + ((target - start) * eased)) + '%';
      if (progress < 1) window.requestAnimationFrame(frame);
    }

    window.requestAnimationFrame(frame);
  }

  function setCard(type, data) {
    var card = document.querySelector('[data-noc-card="' + type + '"]');
    if (!card) return;

    var total = Number(data.total || 0);
    var up = Number(data.up || 0);
    var down = Number(data.down || 0);
    var percentage = total ? Math.round((up / total) * 100) : 0;

    var upEl = card.querySelector('.noc-up');
    var downEl = card.querySelector('.noc-down');
    var totalEl = card.querySelector('.noc-total');
    var percentageEl = card.querySelector('.noc-pct');
    var needle = card.querySelector('.premium-gauge-needle, .gauge-needle');

    var gaugeAngle = -90 + (percentage * 1.8);
    card.style.setProperty('--gauge-angle', gaugeAngle + 'deg');

    if (upEl) upEl.textContent = up;
    if (downEl) downEl.textContent = down;
    if (totalEl) totalEl.textContent = total;
    animatePercentage(percentageEl, percentage);
    if (needle) needle.style.transform = 'rotate(var(--gauge-angle))';

    card.classList.remove('warning', 'critical', 'has-down', 'critical-down');
    if (down > 0) card.classList.add('has-down');
    if (percentage < 60 && total > 0) {
      card.classList.add('critical', 'critical-down');
    } else if (percentage < 80 && total > 0) {
      card.classList.add('warning');
    }
  }

  function updateStatusChart(summary) {
    var typeOrder = ['Switch', 'Server', 'AP', 'Service'];
    var maxValue = 1;
    typeOrder.forEach(function (type) {
      var item = normalSummary(summary, type);
      maxValue = Math.max(maxValue, Number(item.up || 0), Number(item.down || 0));
    });

    typeOrder.forEach(function (type) {
      var item = normalSummary(summary, type);
      var row = document.querySelector('[data-chart-type="' + type + '"]');
      if (!row) return;
      var up = Number(item.up || 0);
      var down = Number(item.down || 0);
      var upBar = row.querySelector('.chart-bar.up');
      var downBar = row.querySelector('.chart-bar.down');
      var upValue = row.querySelector('.chart-up-value');
      var downValue = row.querySelector('.chart-down-value');
      if (upBar) upBar.style.height = Math.max(2, Math.round((up / maxValue) * 88)) + '%';
      if (downBar) downBar.style.height = down ? Math.max(2, Math.round((down / maxValue) * 88)) + '%' : '2%';
      if (upValue) upValue.textContent = up;
      if (downValue) downValue.textContent = down;
    });
  }

  function updateDonut(summary) {
    var ap = Number(normalSummary(summary, 'AP').total || 0);
    var sw = Number(normalSummary(summary, 'Switch').total || 0);
    var server = Number(normalSummary(summary, 'Server').total || 0);
    var service = Number(normalSummary(summary, 'Service').total || 0);
    var total = ap + sw + server + service;
    var donut = document.getElementById('deviceDonut');

    var apEnd = total ? (ap / total) * 100 : 0;
    var swEnd = total ? apEnd + (sw / total) * 100 : 0;
    var serverEnd = total ? swEnd + (server / total) * 100 : 0;

    if (donut) {
      donut.style.background = 'conic-gradient(' +
        '#ffb61f 0 ' + apEnd.toFixed(2) + '%,' +
        '#278eff ' + apEnd.toFixed(2) + '% ' + swEnd.toFixed(2) + '%,' +
        '#42d56b ' + swEnd.toFixed(2) + '% ' + serverEnd.toFixed(2) + '%,' +
        '#895ce5 ' + serverEnd.toFixed(2) + '% 100%)';
    }

    var values = {
      donutTotal: total,
      legendAP: ap,
      legendSwitch: sw,
      legendServer: server,
      legendService: service
    };
    Object.keys(values).forEach(function (id) {
      var element = document.getElementById(id);
      if (element) element.textContent = values[id];
    });
  }

  function typeLabel(type) {
    return ({ Switch: 'Switch', Server: 'Server', AP: 'Access Point', Service: 'Network Service' })[type] || type;
  }

  function typePage(type) {
    return ({
      Switch: 'pages/switch.php',
      Server: 'pages/server.php',
      AP: 'pages/access_point.php',
      Service: 'pages/network_services.php'
    })[type] || 'pages/device_manager.php';
  }

  function renderSmartAlert(summary) {
    var bar = document.getElementById('smartAlertBar');
    var text = document.getElementById('smartAlertText');
    var link = document.getElementById('smartAlertLink');
    var notificationCount = document.getElementById('notificationCount');
    var topDot = document.getElementById('topStatusDot');
    var sidebarState = document.getElementById('sidebarSystemState');
    var sidebarStateWrap = sidebarState ? sidebarState.closest('.system-state') : null;
    if (!bar || !text) return;

    var downTypes = [];
    var totalDown = 0;
    ['Switch', 'Server', 'Service', 'AP'].forEach(function (type) {
      var down = Number(normalSummary(summary, type).down || 0);
      if (down > 0) {
        downTypes.push({ type: type, down: down });
        totalDown += down;
      }
    });

    bar.classList.remove('is-loading', 'all-good');
    if (totalDown === 0) {
      bar.classList.add('all-good');
      text.innerHTML = 'Semua peranti sedang <b>UP</b> dan beroperasi normal';
      if (link) { link.textContent = 'Lihat Dashboard →'; link.href = '#noc-status'; }
      if (notificationCount) notificationCount.textContent = '0';
      if (topDot) topDot.classList.remove('bad');
      if (sidebarState) sidebarState.textContent = 'System All Good';
      if (sidebarStateWrap) { sidebarStateWrap.classList.remove('bad'); sidebarStateWrap.classList.add('good'); }
      return;
    }

    var message = downTypes.map(function (item) {
      return '<b>' + item.down + '</b> ' + typeLabel(item.type);
    }).join(' dan ');
    text.innerHTML = message + ' sedang <b>DOWN</b>';
    if (link) {
      link.innerHTML = 'Lihat Peranti DOWN <span>→</span>';
      link.href = 'pages/down_devices.php';
    }
    if (notificationCount) notificationCount.textContent = totalDown > 99 ? '99+' : String(totalDown);
    if (topDot) topDot.classList.add('bad');
    if (sidebarState) sidebarState.textContent = totalDown + ' device down';
    if (sidebarStateWrap) { sidebarStateWrap.classList.remove('good'); sidebarStateWrap.classList.add('bad'); }
  }

  function deviceOpenUrl(device) {
    var rawUrl = String(device && device.url ? device.url : '').trim();
    if (/^https?:\/\//i.test(rawUrl)) return rawUrl;

    var ip = String(device && device.ip ? device.ip : '').trim();
    if (/^https?:\/\//i.test(ip)) return ip;
    if (!ip) return typePage(device && device.type ? device.type : '');

    var portMatch = ip.match(/:(443|8443|9443)$/);
    return (portMatch ? 'https://' : 'http://') + ip;
  }

  function alertRowHtml(device, now, isAllGood) {
    if (isAllGood) {
      return '<a class="premium-alert-row up alert-device-link" href="#noc-status">' +
        '<span>↑</span>' +
        '<div><b>Semua peranti aktif</b><small>Tiada device DOWN pada semakan terakhir</small></div>' +
        '<time>' + now + '</time>' +
        '</a>';
    }

    var url = deviceOpenUrl(device);
    return '<a class="premium-alert-row alert-device-link" href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer" title="Buka ' + escapeHtml(device.name) + '">' +
      '<span>↓</span>' +
      '<div><b>' + escapeHtml(device.name) + ' (' + escapeHtml(typeLabel(device.type)) + ')</b>' +
      '<small>' + escapeHtml(device.ip) + ' tidak aktif · klik untuk buka device</small></div>' +
      '<time>' + now + '</time>' +
      '</a>';
  }

  function renderAlerts(data) {
    if (!alerts) return;
    var now = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    var rows = (data.alerts || []).slice(0, 8);
    var items = [];

    if (!rows.length) {
      items.push(alertRowHtml({}, now, true));
    } else {
      rows.forEach(function (device) {
        items.push(alertRowHtml(device, now, false));
      });
    }

    // Pastikan ticker cukup panjang untuk bergerak secara berterusan walaupun alert sedikit.
    var baseItems = items.slice();
    while (items.length < 5) {
      items.push(baseItems[items.length % baseItems.length]);
    }

    var groupHtml = '<div class="alert-crawl-group">' + items.join('') + '</div>';
    var duration = Math.max(14, items.length * 3.2);
    alerts.classList.add('alert-crawl-viewport');
    alerts.innerHTML = '<div class="alert-crawl-track" style="--alert-crawl-duration:' + duration + 's">' + groupHtml + groupHtml + '</div>';
  }

  function renderDevicePage(data) {
    if (!tbody || !pageType) return;
    lastDeviceData = data || lastDeviceData;
    var searchElement = document.getElementById('deviceSearch');
    var query = searchElement ? searchElement.value.toLowerCase() : '';
    var source = lastDeviceData && lastDeviceData.devices ? lastDeviceData.devices : [];
    var rows = source.filter(function (device) {
      var haystack = [typeLabel(device.type), device.name, device.ip, device.model, device.serial].join(' ').toLowerCase();
      var typeMatch = pageType === 'ALL' || device.type === pageType;
      var statusMatch = !pageStatusFilter || String(device.status || '').toUpperCase() === pageStatusFilter.toUpperCase();
      return typeMatch && statusMatch && (!query || haystack.indexOf(query) !== -1);
    });
    rows = sortDeviceRows(rows);
    updateDeviceSortControls();

    var countElement = document.getElementById('deviceCount');
    if (countElement) countElement.textContent = rows.length;
    if (!rows.length && source.length) {
      tbody.innerHTML = '<tr><td colspan="' + (canEditMonitoring ? 7 : 6) + '">' + (pageStatusFilter === 'DOWN' ? 'Tiada peranti DOWN pada semakan ini.' : 'Tiada rekod yang sepadan.') + '</td></tr>';
      return;
    }
    if (!rows.length) return;

    tbody.innerHTML = rows.map(function (device) {
      var status = device.status || 'READY';
      var statusClass = status === 'READY' ? 'pending' : (status === 'UP' ? 'up' : (status === 'PAUSED' ? 'pending' : 'down'));
      var url = device.url || ('http://' + device.ip);
      var typeClass = 'type-' + String(device.type || 'Other').toLowerCase().replace(/[^a-z0-9]+/g, '-');
      var isPaused = String(device.monitoring_status || '').toLowerCase() === 'paused' || status === 'PAUSED';
      var monitoringNote = device.monitoring_note ? '<small>' + escapeHtml(device.monitoring_note) + '</small>' : '';
      var actionHtml = '';
      if (canEditMonitoring) {
        actionHtml = '<td><button class="open-link monitor-toggle-btn" type="button" data-device-id="' + escapeHtml(device.id || '') + '" data-next-status="' + (isPaused ? 'active' : 'paused') + '">' + (isPaused ? 'Aktifkan' : 'Pause') + '</button></td>';
      }
      return '<tr>' +
        '<td><span class="device-type-badge ' + typeClass + '">' + escapeHtml(typeLabel(device.type)) + '</span></td>' +
        '<td><b>' + escapeHtml(device.name) + '</b><small>' + escapeHtml(device.serial || '-') + '</small></td>' +
        '<td>' + escapeHtml(device.model || '-') + '</td>' +
        '<td><a href="' + escapeHtml(url) + '" target="_blank"><code>' + escapeHtml(device.ip) + '</code></a></td>' +
        '<td><span class="status-pill ' + statusClass + '">' + escapeHtml(status) + '</span>' + (isPaused ? monitoringNote : '') + '</td>' +
        '<td><a class="open-link" href="' + escapeHtml(url) + '" target="_blank">Open</a></td>' +
        actionHtml +
        '</tr>';
    }).join('');
  }


  async function toggleMonitoring(button) {
    if (!button || !canEditMonitoring) return;
    var id = button.getAttribute('data-device-id') || '';
    var nextStatus = button.getAttribute('data-next-status') || 'active';
    if (!id) return;
    var note = '';
    if (nextStatus === 'paused') {
      note = window.prompt('Nota pause monitoring (contoh: block ping / server ditutup):', 'block ping / ditutup') || '';
    }
    button.disabled = true;
    try {
      var form = new FormData();
      form.append('id', id);
      form.append('status', nextStatus);
      form.append('note', note);
      var response = await fetch('../api/device_monitoring_toggle.php', { method: 'POST', body: form, cache: 'no-store' });
      var data = await response.json().catch(function () { return {}; });
      if (!response.ok || !data.ok) throw new Error(data.error || 'Gagal tukar status monitoring.');
      await refreshNoc();
    } catch (error) {
      alert(error.message || 'Gagal tukar status monitoring.');
    } finally {
      button.disabled = false;
    }
  }

  function setLoading(isLoading) {
    [btn, topBtn].forEach(function (button) {
      if (button) button.disabled = isLoading;
    });
    if (topBtn) topBtn.classList.toggle('is-loading', isLoading);
  }

  async function refreshNoc() {
    if (refreshInProgress) return;
    refreshInProgress = true;
    var refreshStartedAt = Date.now();
    var refreshSucceeded = false;

    if (statusText) {
      var checkingLabel = pageType === 'ALL' ? 'semua peranti' : pageType;
      statusText.textContent = pageType ? 'Checking ' + checkingLabel + ' status...' : 'Checking...';
    }
    setLoading(true);
    if (!pageType) beginGaugeRefresh();

    try {
      var separator = apiPath().indexOf('?') >= 0 ? '&' : '?';
      var response = await fetch(apiPath() + separator + '_=' + Date.now(), { cache: 'no-store' });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      var data = await response.json();
      var summary = data.summary || {};

      if (!pageType) {
        var remainingAnimation = gaugeRefreshMinimumMs - (Date.now() - refreshStartedAt);
        if (remainingAnimation > 0) await sleep(remainingAnimation);
        setCard('Switch', normalSummary(summary, 'Switch'));
        setCard('Server', normalSummary(summary, 'Server'));
        setCard('Service', normalSummary(summary, 'Service'));
        setCard('AP', normalSummary(summary, 'AP'));
        updateStatusChart(summary);
        updateDonut(summary);
        renderSmartAlert(summary);
      }

      renderAlerts(data);
      renderDevicePage(data);

      var checkedAt = data.checked_at || '-';
      var topLastRefresh = document.getElementById('topLastRefresh');
      if (topLastRefresh) topLastRefresh.textContent = checkedAt;
      if (statusText) statusText.textContent = 'Last check: ' + checkedAt + ' (' + (data.elapsed_ms || 0) + ' ms)';
      refreshSucceeded = true;
    } catch (error) {
      if (statusText) statusText.textContent = 'Live ping gagal/timeout. Senarai peranti masih dipaparkan daripada fail data.';
      var smartText = document.getElementById('smartAlertText');
      var smartBar = document.getElementById('smartAlertBar');
      if (smartText && smartBar) {
        smartBar.classList.remove('all-good');
        smartBar.classList.add('is-loading');
        smartText.textContent = 'Live ping gagal atau timeout. Tekan refresh untuk cuba semula.';
      }
    } finally {
      if (!pageType) endGaugeRefresh(refreshSucceeded);
      setLoading(false);
      refreshInProgress = false;
    }
  }

  if (btn) btn.addEventListener('click', refreshNoc);
  if (topBtn) topBtn.addEventListener('click', refreshNoc);
  var search = document.getElementById('deviceSearch');
  if (search) search.addEventListener('input', function () { renderDevicePage(lastDeviceData); });
  if (tbody) {
    tbody.addEventListener('click', function (event) {
      var button = event.target && event.target.closest ? event.target.closest('.monitor-toggle-btn') : null;
      if (button) toggleMonitoring(button);
    });
  }

  var sortButtons = document.querySelectorAll('[data-device-sort-key]');
  sortButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      var key = this.getAttribute('data-device-sort-key') || 'name';
      if (deviceSortKey === key) deviceSortDirection = deviceSortDirection === 'asc' ? 'desc' : 'asc';
      else { deviceSortKey = key; deviceSortDirection = 'asc'; }
      renderDevicePage(lastDeviceData);
    });
  });
  var sortSelect = document.getElementById('deviceSortSelect');
  if (sortSelect) {
    sortSelect.addEventListener('change', function () {
      var parts = this.value.split(':');
      deviceSortKey = parts[0] || 'name';
      deviceSortDirection = parts[1] || 'asc';
      renderDevicePage(lastDeviceData);
    });
  }
  updateDeviceSortControls();

  if (document.querySelector('[data-noc-card]') || tbody) refreshNoc();
  window.setInterval(function () {
    if (!auto || auto.checked) refreshNoc();
  }, 60000);
})();
