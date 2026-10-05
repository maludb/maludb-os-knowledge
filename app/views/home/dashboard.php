<?php /** Home (screen `home`, design §9). Data: s (home_summary: bases, may), tz. A person with no bases sees ONE button; otherwise a plain list of the bases they may read.
 * (The design's other regions — builds, recent questions, pins due, the Archivist's note, the curators' gaps, the admin's usage — are Phase 3 slices'.) */
$may = $s['may'];
$bases = $s['bases'];
?>
<?= view('shared/header.php', ['id' => 'home', 'title' => 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
<?php if ($bases === []): ?>
    <div class="card" id="home-first-run"><div class="card-body">
        <div class="empty-state text-center py-4" id="home-empty">
            <span class="avatar-text avatar-lg rounded mb-3"><i class="feather-book-open"></i></span>
            <div class="fw-semibold mb-1">Nothing to ask yet</div>
            <div class="fs-12 text-muted mb-3">A knowledge base is a set of documents you can ask questions of, with the answer quoted from them. Create one, add documents, ask.</div>
            <?php if ($may['create']): ?>
                <a href="/bases/new" class="btn btn-primary btn-touch" id="home-create-base-btn" hx-get="/bases/new" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="/bases/new"><i class="feather-plus me-1"></i>Create a knowledge base</a>
            <?php else: ?>
                <div class="fs-12 text-muted" id="home-nothing-shared">No knowledge base has been shared with you yet.</div>
            <?php endif; ?>
        </div>
    </div></div>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0" id="home-bases-title">Your knowledge bases</h5>
        <?php if ($may['create']): ?><a href="/bases/new" class="btn btn-light btn-touch" id="home-create-base-btn" hx-get="/bases/new" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="/bases/new"><i class="feather-plus me-1"></i>Create a knowledge base</a><?php endif; ?>
    </div>
    <div class="row g-3" id="home-bases-list">
    <?php foreach ($bases as $b): $bid = (int) $b['base_id']; $badge = ['empty' => 'secondary', 'building' => 'info', 'ready' => 'success', 'needs_rebuild' => 'warning'][$b['status']] ?? 'secondary'; ?>
        <div class="col-md-6 col-xl-4"><div class="card h-100 mb-0" id="home-base-<?= $bid ?>"><div class="card-body">
            <div class="fw-semibold fs-14"><?= hx_link(with_back('/bases/' . $bid, '/'), e($b['name']), 'text-dark', 'id="home-base-' . $bid . '-link"') ?></div>
            <?php if (($b['description'] ?? '') !== ''): ?><div class="fs-12 text-muted text-truncate"><?= e($b['description']) ?></div><?php endif; ?>
            <div class="mt-2 d-flex flex-wrap gap-1 fs-12">
                <span class="badge bg-soft-<?= e($badge) ?> text-<?= e($badge) ?>"><?= e(str_replace('_', ' ', (string) $b['status'])) ?></span>
                <span class="badge bg-soft-secondary text-dark"><?= (int) $b['source_count'] ?> source<?= (int) $b['source_count'] === 1 ? '' : 's' ?></span>
                <span class="badge bg-soft-secondary text-dark"><?= (int) $b['section_count'] ?> section<?= (int) $b['section_count'] === 1 ? '' : 's' ?></span>
                <?php if ((int) $b['stale_count'] > 0): ?><span class="badge bg-soft-warning text-warning"><?= (int) $b['stale_count'] ?> to rebuild</span><?php endif; ?>
                <?php if (!empty($b['i_curate'])): ?><span class="badge bg-soft-primary text-primary">curator</span><?php endif; ?>
            </div>
            <div class="fs-11 text-muted mt-2"><?= $b['last_built_at'] !== null ? 'Last built ' . e(format_ts($b['last_built_at'], $tz, 'M j, Y')) : 'Not built yet' ?></div>
        </div></div></div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
