/* ============================================================
   IRIEBE TOWN SECONDARY SCHOOL (UBE) — SITE BEHAVIOUR
   File: script.js
   Used by: index.html, about.html, academics.html,
            admissions.html, news.html, contact.html

   What it does:
   1. Opens/closes the three-lines (hamburger) menu
   2. Runs the home-page photo slider
      (auto-play + arrows + dots + swipe on phones)
   3. Loads the Admin dashboard's saved settings — colors,
      ticker text, page text, photos, news, extra boxes —
      and applies them to the page automatically
   4. Small safety nets: current year in the footer,
      graceful fallback when a photo is missing

   SETTINGS SHAPE (what the Admin dashboard saves —
   File 9 and portal/site_settings.php both follow this):

   {
     "colors": { "--green": "#0f6b3f", "--gold": "#f2b01e" },
     "texts":  { "ticker": "…", "stat-students": "520" },
     "images": { "logo": "images/school-logo.png" },
     "news":   [ { "date": "…", "title": "…", "body": "…" } ],
     "boxes":  [ { "zone": "home", "title": "…", "body": "…" } ]
   }

   How the page receives them:
   - Text:    any element with  data-edit="ticker"  gets that text
   - Photos:  any <img> with    data-edit-img="logo" gets that photo
   - News:    a container with  data-news-list  (optional
              data-news-limit="3") gets the news cards
   - Boxes:   a container with  data-box-zone="home" gets
              the admin's added boxes for that zone
   ============================================================ */

(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    initMenu();
    initSlider();
    initFooterYear();
    initImageFallbacks();
    loadSiteSettings();
  });

  /* ==========================================================
     1. THREE-LINES MENU
     ========================================================== */
  function initMenu() {
    var btn = document.querySelector('.nav-toggle');
    var nav = document.querySelector('.site-nav');
    if (!btn || !nav) return;

    function close() {
      nav.classList.remove('open');
      btn.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      var open = nav.classList.toggle('open');
      btn.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    /* Tapping a page link closes the menu */
    nav.addEventListener('click', function (e) {
      if (e.target.closest('a')) close();
    });

    /* Tapping anywhere outside closes it */
    document.addEventListener('click', function (e) {
      if (nav.classList.contains('open') && !nav.contains(e.target)) close();
    });

    /* Escape key closes it */
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
    });

    /* Stretching to a wide screen closes it (menu is only for phones) */
    window.addEventListener('resize', function () {
      if (window.innerWidth > 920) close();
    });
  }

  /* ==========================================================
     2. PHOTO SLIDER (home page)
     ========================================================== */
  function initSlider() {
    var slider = document.querySelector('.slider');
    if (!slider) return;

    var slides = Array.prototype.slice.call(slider.querySelectorAll('.slide'));
    if (!slides.length) return;

    var dotsBox = slider.querySelector('.slide-dots');
    var prevBtn = slider.querySelector('.slide-prev');
    var nextBtn = slider.querySelector('.slide-next');
    var current = 0;
    var timer = null;
    var DELAY = 6000; /* 6 seconds per photo */

    /* Build one dot per slide */
    var dots = [];
    if (dotsBox) {
      slides.forEach(function (_, n) {
        var d = document.createElement('button');
        d.type = 'button';
        d.setAttribute('aria-label', 'Go to slide ' + (n + 1));
        d.addEventListener('click', function () { show(n); restart(); });
        dotsBox.appendChild(d);
        dots.push(d);
      });
    }

    function show(n) {
      current = (n + slides.length) % slides.length;
      slides.forEach(function (s, k) { s.classList.toggle('is-on', k === current); });
      dots.forEach(function (d, k) { d.classList.toggle('on', k === current); });
    }
    function next() { show(current + 1); }
    function prev() { show(current - 1); }

    function start() { if (slides.length > 1) timer = setInterval(next, DELAY); }
    function stop()  { if (timer) clearInterval(timer); timer = null; }
    function restart() { stop(); start(); }

    if (nextBtn) nextBtn.addEventListener('click', function () { next(); restart(); });
    if (prevBtn) prevBtn.addEventListener('click', function () { prev(); restart(); });

    /* Pause while the visitor's mouse is on the slider */
    slider.addEventListener('mouseenter', stop);
    slider.addEventListener('mouseleave', start);

    /* Swipe left/right on phones */
    var touchX = null;
    slider.addEventListener('touchstart', function (e) {
      touchX = e.touches[0].clientX;
    }, { passive: true });
    slider.addEventListener('touchend', function (e) {
      if (touchX === null) return;
      var dx = e.changedTouches[0].clientX - touchX;
      if (Math.abs(dx) > 40) { if (dx < 0) next(); else prev(); restart(); }
      touchX = null;
    }, { passive: true });

    /* Arrow keys on computers */
    document.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { next(); restart(); }
      if (e.key === 'ArrowLeft')  { prev(); restart(); }
    });

    /* Only one photo? Hide the arrows and dots */
    if (slides.length < 2) {
      if (prevBtn) prevBtn.style.display = 'none';
      if (nextBtn) nextBtn.style.display = 'none';
      if (dotsBox) dotsBox.style.display = 'none';
    }

    show(0);
    start();
  }

  /* ==========================================================
     3. FOOTER YEAR — any element with data-year shows this year
     ========================================================== */
  function initFooterYear() {
    var year = String(new Date().getFullYear());
    document.querySelectorAll('[data-year]').forEach(function (el) {
      el.textContent = year;
    });
  }

  /* ==========================================================
     4. MISSING PHOTO SAFETY
     - Logo missing → green "I" badge appears instead
     - Other photo missing → hidden, so the school-green
       background shows instead of a broken-image icon
     ========================================================== */
  var LOGO_PLACEHOLDER = 'data:image/svg+xml;utf8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">' +
    '<circle cx="32" cy="32" r="32" fill="#0a4a2a"/>' +
    '<text x="32" y="42" font-family="Arial" font-size="30" font-weight="bold" ' +
    'fill="#f2b01e" text-anchor="middle">I</text></svg>'
  );

  function initImageFallbacks() {
    document.querySelectorAll('img').forEach(function (img) {
      img.addEventListener('error', function () {
        if (img.classList.contains('brand-logo')) {
          if (img.src !== LOGO_PLACEHOLDER) img.src = LOGO_PLACEHOLDER;
        } else {
          img.style.display = 'none';
        }
      });
      /* Catch images that already failed before this script ran */
      if (img.complete && img.naturalWidth === 0 && img.src) {
        img.dispatchEvent(new Event('error'));
      }
    });
  }

  /* ==========================================================
     5. LOAD THE ADMIN'S SAVED SETTINGS

     Order:
     a) Apply this browser's saved copy instantly (no flash)
     b) Ask the server (portal/site_settings.php) for the copy
        every visitor sees, and apply it when it answers.
        If the server file is not uploaded yet — or you are
        previewing by double-clicking the file — the browser
        copy simply stays in use. Nothing breaks either way.
     ========================================================== */
  var SETTINGS_URL = 'portal/site_settings.php';
  var LS_KEY = 'iriebeSiteSettings';

  function loadSiteSettings() {
    applySettings(readLocal());

    if (location.protocol === 'file:') return; /* offline preview */

    fetch(SETTINGS_URL, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data) return;
        var s = data.settings || data; /* accept either wrapper */
        if (s && typeof s === 'object') applySettings(s);
      })
      .catch(function () { /* server file not there yet — fine */ });
  }

  function readLocal() {
    try {
      var raw = localStorage.getItem(LS_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
  }

  function applySettings(s) {
    if (!s) return;

    /* ---- Colors: only valid CSS variables and hex colors ---- */
    if (s.colors) {
      Object.keys(s.colors).forEach(function (varName) {
        var val = s.colors[varName];
        if (/^--[a-z-]+$/.test(varName) && /^#[0-9a-fA-F]{3,8}$/.test(val)) {
          document.documentElement.style.setProperty(varName, val);
        }
      });
    }

    /* ---- Text: every element marked data-edit="key" ---- */
    var texts = s.texts || {};
    if (s.ticker && !texts.ticker) texts.ticker = s.ticker; /* older saves */
    document.querySelectorAll('[data-edit]').forEach(function (el) {
      var key = el.getAttribute('data-edit');
      if (texts[key] != null && texts[key] !== '') el.textContent = texts[key];
    });

    /* ---- Photos: every <img> marked data-edit-img="key" ---- */
    if (s.images) {
      document.querySelectorAll('[data-edit-img]').forEach(function (el) {
        var key = el.getAttribute('data-edit-img');
        if (s.images[key]) {
          el.style.display = '';
          el.src = s.images[key];
        }
      });
    }

    /* ---- News cards ---- */
    if (Array.isArray(s.news)) {
      document.querySelectorAll('[data-news-list]').forEach(function (box) {
        var limit = parseInt(box.getAttribute('data-news-limit'), 10) || s.news.length;
        box.innerHTML = '';
        if (!s.news.length) {
          var empty = document.createElement('p');
          empty.className = 'admin-note';
          empty.textContent = 'School news will appear here.';
          box.appendChild(empty);
          return;
        }
        s.news.slice(0, limit).forEach(function (item) {
          box.appendChild(newsCard(item));
        });
      });
    }

    /* ---- Extra boxes the admin added ---- */
    if (Array.isArray(s.boxes)) {
      document.querySelectorAll('[data-box-zone]').forEach(function (zone) {
        var name = zone.getAttribute('data-box-zone');
        zone.innerHTML = '';
        s.boxes.forEach(function (b) {
          if (b.zone === name) zone.appendChild(boxCard(b));
        });
      });
    }
  }

  /* Builders use textContent only — admin text can never
     inject code into the page. */
  function newsCard(item) {
    var card = document.createElement('article');
    card.className = 'news-card';
    var date = document.createElement('span');
    date.className = 'news-date';
    date.textContent = item.date || '';
    var h = document.createElement('h3');
    h.textContent = item.title || '';
    var p = document.createElement('p');
    p.textContent = item.body || '';
    card.appendChild(date);
    card.appendChild(h);
    card.appendChild(p);
    return card;
  }

  function boxCard(b) {
    var card = document.createElement('div');
    card.className = 'card';
    var h = document.createElement('h3');
    h.textContent = b.title || '';
    var p = document.createElement('p');
    p.textContent = b.body || '';
    card.appendChild(h);
    card.appendChild(p);
    return card;
  }

})();
