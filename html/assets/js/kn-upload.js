/*
 * kn-upload.js — the Files tab's drop zone (slice 2, sources-sections.md "source-add"). Progressive: without JavaScript the form posts up to
 * 20 files at once and the server shows the result lines; with it, each dropped or chosen file is its OWN POST (Accept: application/json)
 * with a progress bar and its own result line ("Handbook.pdf — added", "setup.exe — refused: …"). Idempotent: loaded again by an HTMX
 * navigation, it binds a form once.
 */
(function () {
  'use strict';
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function bind() {
    var form = document.getElementById('source-files-form');
    if (!form || form.dataset.knUpload === '1') return;
    form.dataset.knUpload = '1';
    var input = document.getElementById('source-form-field-files');
    var zone = document.getElementById('source-drop-zone');
    var results = document.getElementById('upload-results');
    var button = document.getElementById('source-files-btn');
    var csrf = form.querySelector('input[name="csrf_token"]').value;
    var base = form.dataset.base;
    var n = results.children.length;
    if (button) button.hidden = true;                       // each file goes as soon as it is chosen or dropped
    function line(name) {
      n += 1;
      var div = document.createElement('div');
      div.className = 'py-1 fs-12 border-bottom';
      div.id = 'upload-result-' + n;
      div.innerHTML = '<div class="d-flex align-items-center gap-2"><span class="fw-semibold text-truncate min-w-0">' + esc(name) + '</span><span class="ms-auto text-muted kn-upload-state">sending…</span></div>'
        + '<div class="progress mt-1" style="height:4px"><div class="progress-bar" role="progressbar" style="width:0%"></div></div>';
      results.appendChild(div);
      return div;
    }
    function done(div, name, ok, text, sourceId) {
      var bar = div.querySelector('.progress');
      if (bar) bar.remove();
      div.className = 'd-flex align-items-start gap-2 py-1 fs-12 border-bottom';
      div.innerHTML = '<i class="' + (ok ? 'feather-check-circle text-success' : 'feather-x-circle text-danger') + ' mt-1"></i><span class="min-w-0"><span class="fw-semibold">' + esc(name) + '</span> — '
        + (ok ? (sourceId ? '<a href="/sources/' + sourceId + '">added</a>' : 'added') : 'refused: ' + esc(text)) + '</span>';
    }
    function send(file) {
      var div = line(file.name);
      var fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('base', base);
      fd.append('kind', 'file');
      fd.append('files[]', file, file.name);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '/sources/add.php');
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.onprogress = function (e) {
        if (!e.lengthComputable) return;
        var p = Math.round(100 * e.loaded / e.total);
        var b = div.querySelector('.progress-bar');
        if (b) b.style.width = p + '%';
        var st = div.querySelector('.kn-upload-state');
        if (st) st.textContent = p < 100 ? p + '%' : 'reading…';
      };
      xhr.onload = function () {
        var body = {};
        try { body = JSON.parse(xhr.responseText); } catch (e) { body = {}; }
        if (xhr.status === 200 && body.ok) {
          var r = (body.results || [])[0] || {};
          done(div, file.name, r.ok !== false, r.sentence || '', body.record_id);
        } else {
          var msg = (body.error && body.error.message) || ('the server answered ' + xhr.status);
          done(div, file.name, false, msg.replace(/^.*?: /, '') || msg, null);
        }
      };
      xhr.onerror = function () { done(div, file.name, false, 'the upload did not arrive', null); };
      xhr.send(fd);
    }
    function sendAll(files) { for (var i = 0; i < files.length; i++) send(files[i]); }
    input.addEventListener('change', function () { if (input.files && input.files.length) { sendAll(input.files); input.value = ''; } });
    form.addEventListener('submit', function (e) { e.preventDefault(); if (input.files && input.files.length) { sendAll(input.files); input.value = ''; } });
    ['dragenter', 'dragover'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.add('kn-drop-over'); }); });
    ['dragleave', 'drop'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.remove('kn-drop-over'); }); });
    zone.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) sendAll(e.dataTransfer.files); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind); else bind();
  document.addEventListener('htmx:afterSettle', bind);
})();
