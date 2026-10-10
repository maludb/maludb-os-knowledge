<?php /** One section, read at 375 first (screen `section-view`). Data: x, b, curate, marked (html, found), quote, neighbours, ancestors, back, taught, here */
$xid = (int) $x['section_id']; $sid = (int) $x['source_id']; $bid = (int) $b['base_id'];
$title = $x['heading'] !== '' ? $x['heading'] : ($x['heading_path'] !== '' ? $x['heading_path'] : $x['source_title']);
$crumbs = [['Home', '/'], [$b['name'], '/bases/' . $bid], [$x['source_title'], '/sources/' . $sid]];
foreach ($ancestors as $a) { $crumbs[] = [$a['heading'], '/sections/' . (int) $a['section_id']]; }
$crumbs[] = [$title, null];
$forgotten = $x['status'] === 'forgotten'; ?>
<?= view('shared/header.php', ['id' => 'section-view', 'title' => $title, 'crumbs' => $crumbs, 'back' => $back]) ?>
<div class="main-content" id="section-view-content">
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="fs-12 text-muted text-break" id="section-path"><?= e($x['heading_path']) ?></span>
            <?php if ($x['page_no'] !== null): ?><span class="badge bg-light text-dark border" id="section-page">p. <?= (int) $x['page_no'] ?></span><?php endif; ?>
            <?php if ($curate): ?><?= chip(section_status_chip((string) $x['status']), 'section-status') ?><?php endif; ?>
        </div>
        <?php if ($curate && ($x['extracted_version'] !== null || ($x['error'] ?? '') !== '')): ?>
            <div class="fs-11 text-muted mb-2" id="section-curator"><?= $x['extracted_version'] !== null ? 'Built under v' . (int) $x['extracted_version'] : '' ?><?= ($x['error'] ?? '') !== '' ? ' · ' . e((string) $x['error']) : '' ?></div>
        <?php endif; ?>
        <?php if ($forgotten): ?>
            <div class="alert alert-dark mb-0" id="section-forgotten">This section was removed with its source.</div>
        <?php else: ?>
            <?php if ($quote !== '' && $marked['found'] === 'none'): ?><div class="alert alert-warning py-2 fs-12" id="quote-missing">The quoted words are not in this section.</div><?php endif; ?>
            <div class="kn-prewrap kn-section-text" id="section-text" lang="<?= e((string) ($b['language'] ?? 'en')) ?>"><?= $marked['html'] ?></div>
        <?php endif; ?>
    </div></div>
    <?php if ($taught !== null): ?><?= view('graph/partials/taught.php', ['t' => $taught, 'b' => $b, 'here' => $here, 'scope' => 'section']) ?><?php endif; ?>
    <div class="d-flex gap-2 mb-3">
        <?php if ($neighbours['prev'] !== null): ?><?= hx_link('/sections/' . $neighbours['prev'], '<i class="feather-chevron-left me-1"></i>Previous', 'btn btn-light btn-touch flex-fill', 'id="section-prev"') ?><?php endif; ?>
        <?php if ($neighbours['next'] !== null): ?><?= hx_link('/sections/' . $neighbours['next'], 'Next<i class="feather-chevron-right ms-1"></i>', 'btn btn-light btn-touch flex-fill', 'id="section-next"') ?><?php endif; ?>
    </div>
    <?= hx_link('/sources/' . $sid, '<i class="feather-file-text me-1"></i>Open the source', 'btn btn-light btn-touch w-100', 'id="section-open-source"') ?>
</div>
<?php if ($marked['found'] === 'exact' || $marked['found'] === 'collapsed'): ?>
<script>(function () { var m = document.getElementById('quote'); if (m && m.scrollIntoView) { m.scrollIntoView({ block: 'center' }); } })();</script>
<?php endif; ?>
