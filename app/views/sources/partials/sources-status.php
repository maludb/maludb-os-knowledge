<?php /** The cards region of `sources`, polled while anything is pending (HTMX every 5 s while visible; 286 stops it). Data: b, sources, pending, filters, curate, tz, here */
$bid = (int) $b['base_id'];
$q = http_build_query(array_filter($filters, static fn ($v) => $v !== null && $v !== '' && $v !== false));
$poll = $pending !== []
    ? ' hx-get="/bases/' . $bid . '/sources-status' . ($q !== '' ? '?' . e($q) : '') . '" hx-trigger="every 5s [document.visibilityState==\'visible\']" hx-swap="outerHTML"'
    : ''; ?>
<div id="sources-status"<?= $poll ?>>
    <?php if ($pending !== []): ?>
        <div class="alert alert-info d-flex align-items-center gap-2 py-2 fs-12" id="sources-pending" role="status">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <span><?= count($pending) ?> source<?= count($pending) === 1 ? ' is' : 's are' ?> being read and cut into sections…</span>
        </div>
    <?php endif; ?>
    <div class="row g-3" id="sources-list">
        <?php if ($sources === []): ?>
            <div class="col-12"><div class="card"><div class="card-body text-muted fs-12" id="sources-empty">
                <?= ($filters['kind'] ?? '') !== '' || ($filters['status'] ?? '') !== '' || !empty($filters['area']) || ($filters['q'] ?? '') !== '' ? 'No source matches these filters.' : 'Nothing has been added to this base yet.' ?>
            </div></div></div>
        <?php endif; ?>
        <?php foreach ($sources as $s): ?><div class="col-12 col-md-6 col-xl-4"><?= view('sources/partials/source-card.php', ['s' => $s, 'here' => $here, 'tz' => $tz]) ?></div><?php endforeach; ?>
    </div>
</div>
