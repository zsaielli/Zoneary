/* ============================================================
   Zoneary homepage — interactions
   ============================================================ */
(function () {
  'use strict';

  // ---- Always begin at the true top; never restore a mid-page scroll ----
  try { history.scrollRestoration = 'manual'; } catch (e) {}
  // pageshow fires on normal loads AND bfcache restores (back/forward) — reset both.
  window.addEventListener('pageshow', function () {
    if (!location.hash) window.scrollTo(0, 0);
  });

  // ---- Smooth scrolling ONLY on in-page anchor clicks (never on load) ----
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href^="#"]');
    if (!a) return;
    var id = a.getAttribute('href');
    if (id.length < 2) return;
    var target = document.querySelector(id);
    if (!target) return;
    e.preventDefault();
    var smooth = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    target.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'start' });
  });

  // ---- Nav scrolled state ----
  var nav = document.getElementById('nav');
  function onScroll() {
    if (nav) nav.classList.toggle('scrolled', window.scrollY > 24);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  // ---- Scroll reveal ----
  var reveals = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add('in');
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add('in'); });
  }

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ---- Hero settle (subtle tilt resolves as the shot fades in) ----
  var heroShot = document.getElementById('heroShot');
  if (heroShot) {
    setTimeout(function () { heroShot.classList.add('settle'); }, reduceMotion ? 0 : 2450);
  }

  // ---- THE BEACON: the Watchtower login reveal, brought to the homepage ----
  // Faithful port of the Watchtower login's beam moment. The intro runs on
  // black, the logo rises,
  // then at the beacon we measure the logo's lantern-room anchor live and sweep
  // one conic-wedge beam from it — the same reason the login measures it: so the
  // light truly originates at the tower regardless of the logo's responsive size
  // or position. CSS (.wt-beam / .wt-beam-glow) carries the wedge + sweep.
  var hero = document.querySelector('.hero');
  var heroMark = document.querySelector('.hero-mark');
  if (hero && heroMark && !reduceMotion) {
    // Phase schedule mirrors the login's TIMINGS: black 300 + logo 700 = 1000ms.
    var BEACON_AT = 1000; // ms from load — the moment the beam fires
    var BEAM_LIFE = 1500; // ms — the 1.4s sweep plus a short tail, then clean up
    setTimeout(function () {
      var hr = hero.getBoundingClientRect();
      var mr = heroMark.getBoundingClientRect();
      // Anchor = the lantern room: horizontal center, ~a third down the mark.
      var x = mr.left - hr.left + mr.width / 2;
      var y = mr.top - hr.top + mr.height * 0.32;

      var layer = document.createElement('div');
      layer.className = 'wt-beam-layer';
      layer.setAttribute('aria-hidden', 'true');

      var beam = document.createElement('div');
      beam.className = 'wt-beam';
      beam.style.left = x + 'px';
      beam.style.top = y + 'px';

      var glow = document.createElement('div');
      glow.className = 'wt-beam-glow';
      glow.style.left = x + 'px';
      glow.style.top = y + 'px';

      layer.appendChild(beam);
      layer.appendChild(glow);
      hero.appendChild(layer);

      setTimeout(function () { layer.remove(); }, BEAM_LIFE);
    }, BEACON_AT);
  }

  // ---- The Watchtower moment: slow cross-dissolve of real product scenes ----
  var scenes = document.querySelectorAll('.cine-scene');
  if (scenes.length > 1 && !reduceMotion) {
    var ci = 0;
    setInterval(function () {
      scenes[ci].classList.remove('is-active');
      ci = (ci + 1) % scenes.length;
      scenes[ci].classList.add('is-active');
    }, 5200);
  }

  // ---- Product tabs ----
  var tabs = document.querySelectorAll('.tab');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (t) {
        t.classList.remove('on');
        t.setAttribute('aria-selected', 'false');
      });
      document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('on'); });
      tab.classList.add('on');
      tab.setAttribute('aria-selected', 'true');
      var panel = document.getElementById('tab-' + tab.dataset.tab);
      if (panel) panel.classList.add('on');
    });
  });

  // ---- Products dropdown: keyboard support ----
  var trigger = document.querySelector('.has-menu .trigger');
  if (trigger) {
    trigger.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        trigger.parentElement.classList.toggle('open');
      }
    });
  }

  // ---- Mobile menu (lightweight): reveal the nav links inline ----
  var mobileBtn = document.getElementById('mobileBtn');
  var navLinks = document.querySelector('.nav-links');
  if (mobileBtn && navLinks) {
    mobileBtn.addEventListener('click', function () {
      var open = navLinks.style.display === 'flex';
      navLinks.style.display = open ? '' : 'flex';
      navLinks.style.position = 'absolute';
      navLinks.style.top = '66px';
      navLinks.style.left = '0';
      navLinks.style.right = '0';
      navLinks.style.flexDirection = 'column';
      navLinks.style.alignItems = 'flex-start';
      navLinks.style.gap = '2px';
      navLinks.style.padding = '12px';
      navLinks.style.background = 'rgba(8,10,17,.97)';
      navLinks.style.borderBottom = '1px solid rgba(255,255,255,.06)';
      navLinks.style.backdropFilter = 'blur(22px)';
    });
  }
})();
