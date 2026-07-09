(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function () {
    var body = document.body;
    var groups = Array.prototype.slice.call(document.querySelectorAll('.noc-menu .menu-group'));

    groups.forEach(function (group) {
      var title = group.querySelector('.menu-title');
      if (!title) return;
      title.setAttribute('aria-expanded', group.classList.contains('open') ? 'true' : 'false');
      title.addEventListener('click', function () {
        var willOpen = !group.classList.contains('open');
        groups.forEach(function (other) {
          if (other !== group) {
            other.classList.remove('open');
            var otherTitle = other.querySelector('.menu-title');
            if (otherTitle) otherTitle.setAttribute('aria-expanded', 'false');
          }
        });
        group.classList.toggle('open', willOpen);
        title.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        try {
          localStorage.setItem('nocOpenMenu', willOpen ? (group.getAttribute('data-menu-group') || '') : '');
        } catch (e) {}
      });
    });

    try {
      var savedMenu = localStorage.getItem('nocOpenMenu');
      if (savedMenu) {
        var savedGroup = document.querySelector('[data-menu-group="' + savedMenu.replace(/"/g, '') + '"]');
        if (savedGroup) {
          savedGroup.classList.add('open');
          var savedTitle = savedGroup.querySelector('.menu-title');
          if (savedTitle) savedTitle.setAttribute('aria-expanded', 'true');
        }
      }
    } catch (e) {}

    var collapseBtn = document.getElementById('sidebarCollapseBtn');
    if (collapseBtn) {
      try {
        if (localStorage.getItem('nocSidebarCollapsed') === '1') {
          body.classList.add('sidebar-collapsed');
        }
      } catch (e) {}
      collapseBtn.addEventListener('click', function () {
        body.classList.toggle('sidebar-collapsed');
        try {
          localStorage.setItem('nocSidebarCollapsed', body.classList.contains('sidebar-collapsed') ? '1' : '0');
        } catch (e) {}
      });
    }

    var mobileMenuBtn = document.getElementById('mobileMenuBtn');
    var mobileCloseBtn = document.getElementById('sidebarMobileClose');
    var overlay = document.getElementById('mobileOverlay');
    function closeMobileMenu() { body.classList.remove('mobile-menu-open'); }
    if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', function () { body.classList.add('mobile-menu-open'); });
    if (mobileCloseBtn) mobileCloseBtn.addEventListener('click', closeMobileMenu);
    if (overlay) overlay.addEventListener('click', closeMobileMenu);

    var displayBtn = document.getElementById('displayModeBtn');
    if (displayBtn) {
      displayBtn.addEventListener('click', function () {
        body.classList.toggle('soft-mode');
      });
    }

    var dismissAlertBtn = document.getElementById('dismissAlertBtn');
    if (dismissAlertBtn) {
      dismissAlertBtn.addEventListener('click', function () {
        var bar = document.getElementById('smartAlertBar');
        if (bar) bar.style.display = 'none';
      });
    }

    function updateClock() {
      var date = new Date();
      var timeEl = document.getElementById('clockNow');
      var dateEl = document.getElementById('dateNow');
      if (timeEl) timeEl.textContent = date.toLocaleTimeString('en-GB');
      if (dateEl) {
        dateEl.textContent = date.toLocaleDateString('ms-MY', {
          day: '2-digit', month: 'short', year: 'numeric'
        });
      }
    }
    updateClock();
    window.setInterval(updateClock, 1000);
  });
})();
