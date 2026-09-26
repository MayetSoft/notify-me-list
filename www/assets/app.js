/*
 * Notify Me List — small progressive enhancements (no framework).
 * Everything works without JavaScript except the live sending progress,
 * which falls back to cron.
 */
(function () {
  'use strict';

  // Confirmation dialogs: <form data-confirm="..."> or <button data-confirm="...">
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    var submitter = ev.submitter || document.activeElement;
    var msg = (submitter && submitter.getAttribute && submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      ev.preventDefault();
    }
  });

  // SMTP: suggest the usual port when the encryption changes.
  var enc = document.querySelector('select[data-port-hint]');
  if (enc) {
    var port = document.getElementById(enc.getAttribute('data-port-hint'));
    var defaults = { tls: '587', ssl: '465', none: '25' };
    enc.addEventListener('change', function () {
      var values = Object.keys(defaults).map(function (k) { return defaults[k]; });
      if (port && (port.value === '' || values.indexOf(port.value) !== -1)) {
        port.value = defaults[enc.value] || port.value;
      }
    });
  }

  // Campaign sending: calls admin/api.php chunk after chunk.
  var box = document.getElementById('campaign');
  if (!box) { return; }
  var api = box.getAttribute('data-api');
  var campaignId = box.getAttribute('data-campaign');
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
  var startBtn = box.querySelector('.js-start');
  var stopBtn = box.querySelector('.js-stop');
  var statusEl = box.querySelector('.js-status');
  var bar = box.querySelector('.progress-bar');
  var running = false;
  var timer = null;

  function setStatus(text) { if (statusEl) { statusEl.textContent = text || ''; } }

  function render(p) {
    if (!p) { return; }
    if (bar) {
      bar.style.width = p.percent + '%';
      bar.parentNode.setAttribute('aria-valuenow', p.percent);
    }
    ['sent', 'total', 'pending', 'failed', 'skipped', 'status_label'].forEach(function (k) {
      var el = box.querySelector('[data-field="' + k + '"]');
      if (el) { el.textContent = p[k]; }
    });
  }

  function stop(message) {
    running = false;
    if (timer) { clearTimeout(timer); timer = null; }
    if (startBtn) { startBtn.hidden = false; startBtn.disabled = false; }
    if (stopBtn) { stopBtn.hidden = true; }
    if (message !== undefined) { setStatus(message); }
  }

  function step() {
    if (!running) { return; }
    var body = new URLSearchParams();
    body.append('action', 'process');
    body.append('campaign', campaignId);
    body.append('csrf', csrf);
    fetch(api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
      body: body.toString()
    }).then(function (res) {
      return res.json().catch(function () { throw new Error('HTTP ' + res.status); });
    }).then(function (data) {
      if (data.error) { stop(data.error); return; }
      render(data.progress);
      var r = data.result || {};
      var p = data.progress || {};
      if (r.smtp_error) { stop(data.messages.smtp_error); return; }
      if (p.status && p.status !== 'sending') { stop(''); return; }
      if (!p.pending) { stop(''); return; }
      if (r.busy) { setStatus(data.messages.busy); timer = setTimeout(step, 10000); return; }
      if (r.throttled) { setStatus(data.messages.throttled); timer = setTimeout(step, 60000); return; }
      if (r.sent + r.failed + r.skipped === 0) {
        // Only retries scheduled later remain.
        timer = setTimeout(step, 30000); return;
      }
      setStatus('');
      timer = setTimeout(step, 300);
    }).catch(function (err) {
      // Network hiccup or PHP timeout: wait and try again.
      setStatus(String(err));
      timer = setTimeout(step, 15000);
    });
  }

  function start() {
    if (running) { return; }
    running = true;
    if (startBtn) { startBtn.hidden = true; }
    if (stopBtn) { stopBtn.hidden = false; }
    setStatus('…');
    step();
  }

  if (startBtn) { startBtn.addEventListener('click', start); }
  if (stopBtn) { stopBtn.addEventListener('click', function () { stop(''); }); }
  if (box.getAttribute('data-autostart') === '1' && startBtn) { start(); }
})();
