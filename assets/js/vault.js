(() => {
  'use strict';

  const cfg = window.ZURIE_VAULT || {};
  const table = document.getElementById('vaultTable');
  const select = document.getElementById('vaultDeviceSelect');
  const username = document.getElementById('vaultUsername');
  const password = document.getElementById('vaultPassword');
  const notes = document.getElementById('vaultNotes');
  const search = document.getElementById('vaultSearch');
  const clearBtn = document.getElementById('vaultClearForm');
  const stayBtn = document.getElementById('vaultStayUnlocked');
  const countdownEls = Array.from(document.querySelectorAll('[data-vault-countdown]'));

  let hideTimer = null;
  let countdownWarned = false;
  let expiryEpoch = Number(cfg.expiresAt || countdownEls[0]?.dataset.expiresAt || 0);
  const initialServerEpoch = Number(cfg.serverNow || countdownEls[0]?.dataset.serverNow || Math.floor(Date.now() / 1000));
  const clientStartMs = Date.now();
  let serverBaseEpoch = initialServerEpoch;
  let lockHandled = false;

  function serverNowEpoch() {
    return serverBaseEpoch + ((Date.now() - clientStartMs) / 1000);
  }

  function remainingSeconds() {
    return Math.max(0, Math.ceil(expiryEpoch - serverNowEpoch()));
  }

  function formatTime(totalSeconds) {
    const safe = Math.max(0, Math.floor(totalSeconds));
    const hours = Math.floor(safe / 3600);
    const minutes = Math.floor((safe % 3600) / 60);
    const seconds = safe % 60;
    if (hours > 0) {
      return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }
    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
  }

  function renderCountdown() {
    if (!countdownEls.length) return;
    const safe = remainingSeconds();
    const label = formatTime(safe);
    countdownEls.forEach((el) => {
      el.textContent = label;
      el.classList.toggle('warning', safe > 15 && safe <= 60);
      el.classList.toggle('danger', safe <= 15);
      el.setAttribute('aria-label', `Vault auto-lock dalam ${label}`);
    });
    document.title = safe > 0
      ? `(${label}) Credential Vault | Personal NOC Dashboard`
      : 'Credential Vault | Personal NOC Dashboard';

    if (safe <= 60 && safe > 0 && !countdownWarned) {
      countdownWarned = true;
      flash('Vault akan auto-lock dalam 1 minit. Klik Stay Unlocked untuk sambung masa.');
    }

    if (safe <= 0 && !lockHandled) {
      lockHandled = true;
      flash('Vault telah dikunci. Halaman akan dimuat semula.', true);
      window.setTimeout(() => window.location.reload(), 700);
    }
  }

  function updateSessionClock(expiresAt, serverNow) {
    const exp = Number(expiresAt);
    const now = Number(serverNow);
    if (Number.isFinite(exp) && exp > 0) expiryEpoch = exp;
    if (Number.isFinite(now) && now > 0) {
      serverBaseEpoch = now;
    }
    countdownWarned = false;
    lockHandled = false;
    renderCountdown();
  }

  function flash(text, isError = false) {
    let box = document.getElementById('vaultToast');
    if (!box) {
      box = document.createElement('div');
      box.id = 'vaultToast';
      box.className = 'vault-toast';
      document.body.appendChild(box);
    }
    box.textContent = text;
    box.classList.toggle('error', isError);
    box.classList.add('show');
    window.setTimeout(() => box.classList.remove('show'), 3000);
  }

  async function postForm(url, values) {
    const body = new URLSearchParams(values);
    const res = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
      body,
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const data = await res.json().catch(() => ({ok: false, message: 'Respons tidak sah.'}));
    if (!res.ok || !data.ok) throw new Error(data.message || 'Permintaan gagal.');
    return data;
  }

  async function fetchCredential(deviceId, purpose) {
    const data = await postForm(cfg.api, {
      csrf: cfg.csrf || '',
      device_id: deviceId,
      purpose
    });
    if (typeof data.expires_at !== 'undefined' || typeof data.server_now !== 'undefined') {
      updateSessionClock(data.expires_at || expiryEpoch, data.server_now || Math.floor(Date.now() / 1000));
    } else if (typeof data.seconds_left !== 'undefined') {
      updateSessionClock(Math.floor(Date.now() / 1000) + Number(data.seconds_left), Math.floor(Date.now() / 1000));
    }
    return data;
  }

  async function extendSession() {
    if (!cfg.sessionApi) throw new Error('Endpoint sesi Vault tidak tersedia.');
    stayBtn.disabled = true;
    stayBtn.textContent = '↻ Menyambung...';
    try {
      const data = await postForm(cfg.sessionApi, {
        csrf: cfg.csrf || '',
        action: 'extend'
      });
      updateSessionClock(data.expires_at, data.server_now);
      flash(data.message || 'Tempoh Vault berjaya disambung.');
    } finally {
      stayBtn.disabled = false;
      stayBtn.textContent = '↻ Stay Unlocked';
    }
  }

  async function syncSessionStatus() {
    if (!cfg.sessionApi || document.hidden) return;
    try {
      const data = await postForm(cfg.sessionApi, {
        csrf: cfg.csrf || '',
        action: 'status'
      });
      if (data.locked) {
        expiryEpoch = Number(data.server_now || Math.floor(Date.now() / 1000));
        renderCountdown();
        return;
      }
      updateSessionClock(data.expires_at, data.server_now);
    } catch (err) {
      // Timer tempatan terus berjalan. Ralat sync tidak perlu ganggu pengguna.
      console.warn('Vault session sync failed:', err);
    }
  }

  async function copyText(value, label) {
    if (!value) throw new Error(`${label} kosong.`);
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(value);
    } else {
      const temp = document.createElement('textarea');
      temp.value = value;
      temp.setAttribute('readonly', 'readonly');
      temp.style.position = 'fixed';
      temp.style.opacity = '0';
      document.body.appendChild(temp);
      temp.select();
      if (!document.execCommand('copy')) {
        temp.remove();
        throw new Error('Browser tidak membenarkan salinan clipboard.');
      }
      temp.remove();
    }
    flash(`${label} disalin. Tampal terus pada halaman device.`);
  }

  function maskRow(row) {
    if (!row) return;
    const userCell = row.querySelector('.vault-username-cell');
    const passCell = row.querySelector('.vault-password-cell');
    if (userCell) userCell.textContent = '••••••';
    if (passCell) passCell.textContent = '••••••••••';
    row.classList.remove('revealed');
  }

  async function act(button) {
    const row = button.closest('tr');
    const deviceId = row?.dataset.deviceId;
    const action = button.dataset.vaultAction;
    if (!deviceId || !action) return;

    if (action === 'add') {
      select.value = deviceId;
      username.value = '';
      password.value = '';
      notes.value = '';
      select.scrollIntoView({behavior: 'smooth', block: 'center'});
      username.focus();
      return;
    }

    const purposeMap = {
      copy_username: 'COPY_USERNAME',
      copy_password: 'COPY_PASSWORD',
      reveal: 'REVEAL',
      edit: 'EDIT'
    };

    try {
      button.disabled = true;
      const data = await fetchCredential(deviceId, purposeMap[action] || 'REVEAL');

      if (action === 'copy_username') {
        await copyText(data.username || '', 'Username');
      } else if (action === 'copy_password') {
        await copyText(data.password || '', 'Password');
      } else if (action === 'edit') {
        select.value = deviceId;
        username.value = data.username || '';
        password.value = data.password || '';
        notes.value = data.notes || '';
        select.scrollIntoView({behavior: 'smooth', block: 'center'});
        username.focus();
        flash('Credential dimuatkan untuk edit.');
      } else {
        if (hideTimer) window.clearTimeout(hideTimer);
        row.querySelector('.vault-username-cell').textContent = data.username || '(kosong)';
        row.querySelector('.vault-password-cell').textContent = data.password || '(kosong)';
        row.classList.add('revealed');
        hideTimer = window.setTimeout(() => maskRow(row), (data.hide_after_seconds || 20) * 1000);
      }
    } catch (err) {
      flash(err.message || 'Ralat vault.', true);
    } finally {
      button.disabled = false;
    }
  }

  table?.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-vault-action]');
    if (btn) act(btn);
  });

  search?.addEventListener('input', function () {
    const q = this.value.toLowerCase();
    table?.querySelectorAll('tbody tr').forEach((row) => {
      row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
  });

  clearBtn?.addEventListener('click', () => {
    select.value = '';
    username.value = '';
    password.value = '';
    notes.value = '';
  });

  stayBtn?.addEventListener('click', async () => {
    try {
      await extendSession();
    } catch (err) {
      flash(err.message || 'Gagal sambung tempoh Vault.', true);
    }
  });

  if (countdownEls.length) {
    renderCountdown();
    window.setInterval(renderCountdown, 250);
    window.setInterval(syncSessionStatus, 30000);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) syncSessionStatus();
    });
  }
})();
