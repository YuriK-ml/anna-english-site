/* Minimal site analytics (views + outbound clicks) with consent */
(function () {
  'use strict';

  var API_URL = '/api/analytics.php';

  var LS = {
    consent: 'anna_analytics_consent', // 'allow' | 'deny'
    optout: 'anna_analytics_optout',   // '1'
    id: 'anna_analytics_id',
  };

  function qs(name) {
    try {
      return new URLSearchParams(location.search || '').get(name);
    } catch (e) {
      var m = (location.search || '').match(new RegExp('(?:\\?|&)' + name + '=([^&]+)'));
      return m ? decodeURIComponent(m[1]) : null;
    }
  }

  function getConsent() {
    return String(localStorage.getItem(LS.consent) || '');
  }

  function isOptOut() {
    return String(localStorage.getItem(LS.optout) || '') === '1';
  }

  function setOptOut(on) {
    if (on) {
      localStorage.setItem(LS.optout, '1');
      localStorage.setItem(LS.consent, 'deny');
      try { localStorage.removeItem(LS.id); } catch (e) {}
    } else {
      localStorage.removeItem(LS.optout);
    }
  }

  function uuid() {
    // RFC4122-ish, no external libs
    var t = Date.now();
    var r = (typeof performance !== 'undefined' && performance.now) ? performance.now() : 0;
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var n = Math.random() * 16;
      n = (t > 0) ? (t + n) % 16 : (r + n) % 16;
      t = Math.floor(t / 16);
      r = Math.floor(r / 16);
      var v = (c === 'x') ? n : (n & 0x3) | 0x8;
      return Math.floor(v).toString(16);
    });
  }

  function ensureIdAfterConsent() {
    // IMPORTANT: do not read or create analytics ID before explicit consent.
    if (isOptOut()) return null;
    if (getConsent() !== 'allow') return null;

    var cur = String(localStorage.getItem(LS.id) || '');
    if (!cur) {
      cur = uuid();
      localStorage.setItem(LS.id, cur);
    }
    return cur;
  }

  function normalizePageId() {
    var p = (location.pathname || '/');
    if (p === '') p = '/';

    // Canonicalize some index variants
    if (p === '/articles/index.html') return '/articles/';
    if (p === '/tutor/index.html') return '/tutor/';

    // Normalize directory access
    if (p === '/articles') return '/articles/';
    if (p === '/tutor') return '/tutor/';


    // Normalize article URLs that may come without .html or with a trailing slash (server rewrites)
    var m1 = p.match(/^\/articles\/([a-z0-9-]+)\.html\/$/i);
    if (m1) p = '/articles/' + m1[1] + '.html';

    var m2 = p.match(/^\/articles\/([a-z0-9-]+)\/?$/i);
    if (m2) p = '/articles/' + m2[1] + '.html';
    // Allowlist
    if (p === '/' || p === '/teacher.html' || p === '/ai.html' || p === '/articles/' || p === '/tutor/') return p;

    // Articles
    if (/^\/articles\/[a-z0-9-]+\.html$/i.test(p)) return p;

    return null;
  }

  function postEvent(payload) {
    try {
      return fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        credentials: 'same-origin',
        keepalive: true,
        body: JSON.stringify(payload),
      }).catch(function () {});
    } catch (e) {
      return null;
    }
  }

  function trackView() {
    var pageId = normalizePageId();
    if (!pageId) return;

    var visitorId = ensureIdAfterConsent();
    if (!visitorId) return;

    // Only once per page load.
    postEvent({ event: 'view', page: pageId, visitor_id: visitorId });
  }

  function trackOut(targetId) {
    if (!targetId) return;

    var visitorId = ensureIdAfterConsent();
    if (!visitorId) return;

    postEvent({ event: 'out_click', target: String(targetId), page: normalizePageId() || '/', visitor_id: visitorId });
  }

  function installOutboundClickTracking() {
    document.addEventListener('click', function (e) {
      try {
        var el = e.target;
        if (!el) return;
        var a = el.closest ? el.closest('a[data-ana-out]') : null;
        if (!a) return;
        var id = a.getAttribute('data-ana-out');
        if (!id) return;
        trackOut(id);
      } catch (err) {}
    }, true);
  }

  function bannerHtml() {
    return (
      '<div id="anaBanner" class="ana" role="dialog" aria-label="Статистика посещений" hidden>' +
      '  <div class="ana__backdrop"></div>' +
      '  <div class="ana__card">' +
      '    <div class="ana__title">Минимальная статистика посещений</div>' +
      '    <div class="ana__text">Для улучшения сайта мы используем минимальную статистику посещений: количество просмотров страниц и переходов по ссылкам. Для приблизительного подсчёта уникальных посетителей может использоваться случайный идентификатор браузера. Имена, email и содержание ваших действий в приложениях для этой статистики не собираются.</div>' +
      '    <details class="ana__details">' +
      '      <summary>Подробнее</summary>' +
      '      <div class="ana__more">' +
      '        <div><b>Цель:</b> понимать, какие страницы и материалы полезны.</div>' +
      '        <div><b>Собираем:</b> просмотры страниц и клики по внешним ссылкам, плюс псевдо‑ID браузера (случайный).</div>' +
      '        <div><b>Не собираем:</b> имена, email, тексты сообщений в Tutor, аудио и изображения.</div>' +
      '        <div><b>Срок хранения:</b> до 90 дней.</div>' +
      '        <div><b>Отзыв:</b> можно в любой момент запретить статистику (кнопка «Не разрешать»).</div>' +
      '      </div>' +
      '    </details>' +
      '    <div class="ana__row">' +
      '      <button id="anaAllow" class="ana__btn">Разрешить</button>' +
      '      <button id="anaDeny" class="ana__btn ana__btn--ghost">Не разрешать</button>' +
      '    </div>' +
      '  </div>' +
      '</div>' +
      '<button id="anaSettings" class="anaSettings" type="button" aria-label="Настройки статистики" hidden>Статистика</button>'
    );
  }

  function bannerCss() {
    return (
      '.ana{position:fixed;inset:0;z-index:9998;font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif}' +
      '.ana[hidden]{display:none!important}' +
      '.ana__backdrop{position:absolute;inset:0;background:rgba(0,0,0,0.55);backdrop-filter:blur(3px)}' +
      '.ana__card{position:relative;max-width:720px;margin:12vh auto 0;background:rgba(0,0,0,0.78);border:1px solid rgba(255,255,255,0.10);border-radius:14px;padding:16px 14px;color:rgba(255,255,255,0.92);box-shadow:0 18px 60px rgba(0,0,0,0.45)}' +
      '.ana__title{font-weight:800;font-size:15px;margin:0 0 8px}' +
      '.ana__text{font-size:13px;line-height:1.5;color:rgba(255,255,255,0.84)}' +
      '.ana__details{margin:10px 0 0;font-size:12px;color:rgba(255,255,255,0.80)}' +
      '.ana__details summary{cursor:pointer;opacity:0.9}' +
      '.ana__more{margin-top:8px;display:grid;gap:6px;line-height:1.45}' +
      '.ana__row{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-top:12px}' +
      '.ana__btn{border:1px solid rgba(255,255,255,0.14);border-radius:10px;padding:10px 12px;background:linear-gradient(135deg,#3498db,#2c80b4);color:#fff;font-weight:800;cursor:pointer}' +
      '.ana__btn--ghost{background:rgba(255,255,255,0.06);font-weight:700}' +
      '.anaSettings{position:fixed;right:10px;bottom:10px;z-index:9997;border:1px solid rgba(255,255,255,0.12);border-radius:999px;padding:8px 10px;background:rgba(0,0,0,0.45);color:rgba(255,255,255,0.84);cursor:pointer;font-size:12px}' +
      '.anaSettings:hover{color:#fff;filter:brightness(1.05)}' +
      '@media (max-width:720px){.ana__card{margin:8vh 12px 0}.ana__row{justify-content:stretch}.ana__btn{flex:1 1 auto}}'
    );
  }

  function mountBanner() {
    if (document.getElementById('anaBanner')) return;

    var st = document.createElement('style');
    st.textContent = bannerCss();
    document.head.appendChild(st);

    var host = document.createElement('div');
    host.innerHTML = bannerHtml();
    document.body.appendChild(host);

    var banner = document.getElementById('anaBanner');
    var allowBtn = document.getElementById('anaAllow');
    var denyBtn = document.getElementById('anaDeny');
    var settingsBtn = document.getElementById('anaSettings');

    function close() {
      if (banner) banner.hidden = true;
    }

    if (banner) {
      banner.querySelector('.ana__backdrop').addEventListener('click', close);
    }

    if (allowBtn) {
      allowBtn.addEventListener('click', function () {
        localStorage.setItem(LS.consent, 'allow');
        localStorage.removeItem(LS.optout);
        close();
        // After consent, we can create/read ID.
        ensureIdAfterConsent();
        trackView();
        refreshSettingsButton();
      });
    }

    if (denyBtn) {
      denyBtn.addEventListener('click', function () {
        localStorage.setItem(LS.consent, 'deny');
        localStorage.setItem(LS.optout, '1');
        try { localStorage.removeItem(LS.id); } catch (e) {}
        close();
        refreshSettingsButton();
      });
    }

    if (settingsBtn) {
      settingsBtn.addEventListener('click', function () {
        if (!banner) return;
        banner.hidden = false;
      });
    }

    function refreshSettingsButton() {
      if (!settingsBtn) return;
      var c = getConsent();
      settingsBtn.hidden = !(c === 'allow' || c === 'deny' || isOptOut());
    }

    // expose for internal pages with custom actions
    window.annaAnalytics = {
      trackOut: trackOut,
      showSettings: function () {
        if (banner) banner.hidden = false;
      },
      setOptOut: setOptOut,
    };

    refreshSettingsButton();

    // Show banner only when there is no explicit choice.
    var c = getConsent();
    if (!isOptOut() && c !== 'allow' && c !== 'deny') {
      banner.hidden = false;
    }
  }

  function handleQueryToggles() {
    var a = qs('analytics');
    if (!a) return;

    if (a === 'off') {
      setOptOut(true);
      // Clean URL (best-effort)
      try {
        var u = new URL(location.href);
        u.searchParams.delete('analytics');
        history.replaceState(null, '', u.toString());
      } catch (e) {}
      return;
    }

    if (a === 'on') {
      // Clear opt-out and re-ask for consent
      localStorage.removeItem(LS.optout);
      localStorage.removeItem(LS.consent);
      try { localStorage.removeItem(LS.id); } catch (e) {}
      try {
        var u2 = new URL(location.href);
        u2.searchParams.delete('analytics');
        history.replaceState(null, '', u2.toString());
      } catch (e2) {}
      return;
    }

    if (a === 'settings') {
      // Open banner (requires mount)
      return;
    }
  }

  function boot() {
    handleQueryToggles();
    mountBanner();
    installOutboundClickTracking();

    // If user already allowed, send view.
    trackView();

    // If analytics=settings, open banner.
    if (qs('analytics') === 'settings') {
      try { window.annaAnalytics && window.annaAnalytics.showSettings && window.annaAnalytics.showSettings(); } catch (e) {}
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();



