/*
 * Watchtower — Product Showcase (standalone, no dependencies)
 *
 * Cross-dissolves through real Watchtower page captures. Self-contained: no
 * network, no API, no HLS, no auth, no framework — just these files + scenes/.
 *
 * Behaviour mirrors the (retired) login background:
 *   • only the active scene and the one it's dissolving from are ever mounted
 *     (≤2 images live); the next is decoded before its dissolve begins
 *   • per-scene hold + saturation + veil; Operations, the hero, holds longest
 *   • Sites shows two atmospheric demo beacons (NOT real sites)
 *   • pauses while the tab is hidden; respects prefers-reduced-motion
 *
 * To reconfigure, edit SCENES below (order, hold, veil, sat) — see README.md.
 */

'use strict';

var FADE_MS = 2000; // cross-dissolve (mirror --showcase-fade in styles.css)

// Order = play order. hold = ms the scene rests before the next dissolve.
// veil = smoked-glass darkness (0–1); sat = saturate() amount.
var SCENES = [
  { id: 'operations', src: 'scenes/operations.webp', veil: 0.46, sat: 0.72, hold: 11000 },
  { id: 'live-view',  src: 'scenes/live-view.webp',  veil: 0.48, sat: 0.72, hold: 8500 },
  { id: 'playback',   src: 'scenes/playback.webp',   veil: 0.44, sat: 0.74, hold: 9000 },
  { id: 'sites',      src: 'scenes/sites.webp',      veil: 0.12, sat: 0.95, hold: 9500 },
  { id: 'health',     src: 'scenes/health.webp',     veil: 0.50, sat: 0.68, hold: 8500 },
  { id: 'recording',  src: 'scenes/recording.webp',  veil: 0.50, sat: 0.68, hold: 8500 },
];

var stage = document.getElementById('stage');
var beacons = document.getElementById('beacons');
var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

var activeIndex = 0;
var activeEl = null;
var timers = [];

function clearTimers() {
  timers.forEach(clearTimeout);
  timers = [];
}

function makeLayer(index, opening) {
  var scene = SCENES[index];
  var el = document.createElement('div');
  el.className = 'scene is-active' + (opening ? ' is-opening' : ' is-entering');
  el.style.backgroundImage = 'url(' + scene.src + ')';
  el.style.setProperty('--veil', String(scene.veil));
  el.style.setProperty('--s', String(scene.sat));
  return el;
}

function syncBeacons() {
  var onSites = SCENES[activeIndex].id === 'sites';
  beacons.classList.toggle('is-on', onSites);
}

function preload(src) {
  return new Promise(function (resolve) {
    var img = new Image();
    img.onload = img.onerror = function () { resolve(); };
    img.src = src;
    setTimeout(resolve, 4000); // safety timeout
  });
}

function goTo(nextIndex) {
  var prevEl = activeEl;
  if (prevEl) {
    prevEl.classList.remove('is-active');
    prevEl.classList.add('is-prev');
  }
  activeEl = makeLayer(nextIndex, false);
  stage.appendChild(activeEl);
  activeIndex = nextIndex;
  syncBeacons();
  if (prevEl) {
    var t = setTimeout(function () {
      if (prevEl.parentNode) prevEl.parentNode.removeChild(prevEl);
    }, FADE_MS + 150);
    timers.push(t);
  }
}

function queueNext() {
  var id = setTimeout(function () {
    var next = (activeIndex + 1) % SCENES.length;
    preload(SCENES[next].src).then(function () {
      if (document.hidden) return; // will resume via visibilitychange
      goTo(next);
      queueNext();
    });
  }, SCENES[activeIndex].hold);
  timers.push(id);
}

function start() {
  // Opening scene.
  activeEl = makeLayer(0, true);
  stage.appendChild(activeEl);
  activeIndex = 0;
  syncBeacons();

  if (prefersReduced) return; // hold a single scene; no cycling

  document.addEventListener('visibilitychange', function () {
    clearTimers();
    if (!document.hidden) queueNext(); // resume; stay put while hidden
  });
  if (!document.hidden) queueNext();
}

start();
