(function () {
    'use strict';

    var box = document.getElementById('photoUploadNotice');
    var countEl = document.getElementById('photoUploadNoticeCount');
    var latestEl = document.getElementById('photoUploadNoticeLatest');
    if (!box || !countEl || !latestEl) return;

    var previousCount = null;

    function render(data) {
        var count = data && data.ok ? Number(data.count || 0) : 0;
        if (!count) {
            box.classList.add('is-hidden');
            countEl.textContent = '0';
            latestEl.textContent = '';
            previousCount = count;
            return;
        }

        countEl.textContent = String(count);
        var latest = data.latest || {};
        var bits = [];
        if (latest.matrik) bits.push(latest.matrik);
        if (latest.nama) bits.push(latest.nama);
        latestEl.textContent = bits.length ? 'Terbaru: ' + bits.join(' · ') : '';
        box.classList.remove('is-hidden');

        if (previousCount !== null && count > previousCount) {
            box.classList.remove('is-new');
            void box.offsetWidth;
            box.classList.add('is-new');
        }
        previousCount = count;
    }

    function refresh() {
        fetch('/zurie/pages/photo_upload_notice.php?_=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store'
        })
        .then(function (response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        })
        .then(render)
        .catch(function () {
            // Senyap jika DB/rangkaian sementara gagal; dashboard lain kekal berfungsi.
        });
    }

    refresh();
    window.setInterval(refresh, 30000);
})();
