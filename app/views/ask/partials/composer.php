<?php /** The composer (sticky at the bottom): Enter sends, Shift-Enter a newline; the bubble echoes at once with "Searching the base…". Without JavaScript the form posts and the page shows the turn. Data: base_id, area (row|null), c, prefill, limit */ ?>
<form method="post" action="/ask/send.php" hx-post="/ask/send.php" hx-target="#ask-turns" hx-swap="beforeend" class="kn-composer" id="ask-composer">
    <?= csrf_field() ?>
    <input type="hidden" name="base" value="<?= (int) $base_id ?>">
    <?php if ($area !== null): ?><input type="hidden" name="area" value="<?= (int) $area['focus_area_id'] ?>"><?php endif; ?>
    <input type="hidden" name="c" value="<?= e((string) $c) ?>" id="ask-c">
    <div class="d-flex gap-2 align-items-end">
        <div class="flex-grow-1">
            <label class="visually-hidden" for="ask-question">Your question</label>
            <textarea name="question" id="ask-question" class="form-control" rows="2" maxlength="<?= (int) $limit ?>" placeholder="Ask this base a question…" required><?= e((string) $prefill) ?></textarea>
            <div class="fs-11 text-muted text-end d-none" id="ask-counter" data-limit="<?= (int) $limit ?>"></div>
        </div>
        <button type="submit" class="btn btn-primary btn-touch" id="ask-send" aria-label="Send"><i class="feather-send"></i></button>
    </div>
</form>
<script>
(function () {
    if (window.__knAsk) { return; }
    window.__knAsk = true;
    var pending = null, timer = null;
    document.addEventListener('keydown', function (ev) {
        var t = ev.target;
        if (t && t.id === 'ask-question' && ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
            ev.preventDefault();
            if (t.value.trim() !== '') { t.form.requestSubmit(); }
        }
    });
    document.addEventListener('input', function (ev) {
        var t = ev.target, c = document.getElementById('ask-counter');
        if (!t || t.id !== 'ask-question' || !c) { return; }
        var lim = parseInt(c.dataset.limit, 10), n = t.value.length;
        c.classList.toggle('d-none', n < 1800);
        c.textContent = n + ' / ' + lim;
    });
    document.addEventListener('click', function (ev) {
        var a = ev.target.closest && ev.target.closest('[data-kn-fill]');
        var box = document.getElementById('ask-question');
        if (!a || !box) { return; }
        ev.preventDefault();
        box.value = a.getAttribute('data-kn-fill');
        box.focus();
    });
    document.addEventListener('htmx:beforeRequest', function (ev) {
        var f = ev.detail.elt;
        if (!f || f.id !== 'ask-composer') { return; }
        var q = f.querySelector('#ask-question').value.trim();
        var turns = document.getElementById('ask-turns');
        pending = document.createElement('div');
        pending.className = 'kn-turn mb-4';
        pending.id = 'ask-pending';
        var b = document.createElement('div'); b.className = 'd-flex justify-content-end mb-2';
        var bubble = document.createElement('div'); bubble.className = 'kn-bubble'; bubble.textContent = q;
        b.appendChild(bubble);
        var w = document.createElement('div'); w.className = 'd-flex align-items-center gap-2 fs-13 text-muted'; w.setAttribute('aria-live', 'polite'); w.id = 'ask-waiting';
        w.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Searching the base…</span>';
        pending.appendChild(b); pending.appendChild(w);
        turns.appendChild(pending);
        pending.scrollIntoView({block: 'end'});
        timer = setTimeout(function () { var s = w.querySelector('span:last-child'); if (s) { s.textContent = 'Still composing — a long answer can take up to a minute.'; } }, 20000);
        var sug = document.getElementById('ask-suggestions'); if (sug) { sug.remove(); }
    });
    document.addEventListener('htmx:afterRequest', function (ev) {
        var f = ev.detail.elt;
        if (!f || f.id !== 'ask-composer') { return; }
        clearTimeout(timer);
        if (pending) { pending.remove(); pending = null; }
        if (ev.detail.successful) {
            var box = document.getElementById('ask-question'); box.value = ''; box.dispatchEvent(new Event('input', {bubbles: true}));
            var all = document.querySelectorAll('#ask-turns > .kn-turn'); if (all.length) { all[all.length - 1].scrollIntoView({block: 'start'}); }
        }
    });
})();
</script>
