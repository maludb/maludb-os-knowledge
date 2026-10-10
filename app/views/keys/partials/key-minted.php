<?php /** The copy-once box: the raw key, shown once and never again. Data: minted = [key_id, raw, expires_old (epoch|null)], tz */ ?>
<div class="alert alert-success" id="key-minted" role="status">
    <div class="fw-bold mb-2">Shown once — store it now; it cannot be shown again.</div>
    <div class="input-group mb-2">
        <input type="text" readonly class="form-control btn-touch user-select-all font-monospace fs-12" id="key-minted-value" value="<?= e($minted['raw']) ?>" aria-label="The new key" autocomplete="off" spellcheck="false">
        <button type="button" class="btn btn-light btn-touch" id="key-minted-copy" aria-label="Copy the key"><i class="feather-copy me-1"></i>Copy</button>
    </div>
    <?php if (!empty($minted['expires_old'])): ?><div class="fs-12" id="key-minted-old">The old key stops working at <?= e(format_ts(gmdate('Y-m-d H:i:s', (int) $minted['expires_old']), $tz)) ?>.</div><?php endif; ?>
    <script>
    (function () {
        var b = document.getElementById('key-minted-copy'), v = document.getElementById('key-minted-value');
        if (!b || !v || b.dataset.bound) { return; }
        b.dataset.bound = '1';
        b.addEventListener('click', function () {
            v.focus(); v.select();
            var done = function () { b.innerHTML = '<i class="feather-check me-1"></i>Copied'; };
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(v.value).then(done, function () { try { document.execCommand('copy'); done(); } catch (e) {} }); }
            else { try { document.execCommand('copy'); done(); } catch (e) {} }
        });
    })();
    </script>
</div>
