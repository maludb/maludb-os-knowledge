<?php /** A base's vocabulary (screen `vocabulary`). Data: b, terms, conflicts, filters, may, tabs, notice */
$id = (int) $b['base_id'];
$action = $may['manage'] && $b['archived_at'] === null ? hx_link('/bases/' . $id . '/vocabulary-import', '<i class="feather-upload me-1"></i>Import CSV', 'btn btn-light btn-touch me-2', 'id="vocabulary-import-link"')
    . hx_link('/vocabulary/new?base=' . $id, '<i class="feather-plus me-1"></i>New term', 'btn btn-primary btn-touch', 'id="vocabulary-add-btn"') : ''; ?>
<?= view('shared/header.php', ['id' => 'vocabulary', 'title' => 'Vocabulary', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Guidelines', '/bases/' . $id . '/guidelines'], ['Vocabulary', null]],
    'back' => back_link() ?? ['/bases/' . $id . '/guidelines', 'the guidelines'], 'action' => $action]) ?>
<div class="main-content" id="vocabulary-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'guidelines']) ?>
    <div class="fs-12 text-muted mb-2">Every term changes the graph: saving one marks the built sections for a rebuild.</div>
    <?php if ($conflicts !== []): ?>
        <div class="alert alert-warning fs-12" id="alias-conflicts"><?php foreach ($conflicts as $c): ?><div>"<?= e($c['alias']) ?>" is claimed by <?= e($c['kept_for']) ?> and <?= e($c['dropped_for']) ?> — only <?= e($c['kept_for']) ?> keeps it.</div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="get" action="/bases/<?= $id ?>/vocabulary" hx-get="/bases/<?= $id ?>/vocabulary" hx-target="#page-content" hx-swap="innerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#vocabulary-filter-q" hx-push-url="true" class="card mb-3" id="vocabulary-filters"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-8"><label class="form-label fs-12 text-muted" for="vocabulary-filter-q">A term or an alias</label><input type="search" name="q" id="vocabulary-filter-q" class="form-control btn-touch" maxlength="80" value="<?= e($filters['q']) ?>"></div>
        <div class="col-12 col-md-4"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="vocabulary-filter-never"><input type="checkbox" class="form-check-input mt-0" name="never" value="1" id="vocabulary-filter-never" <?= $filters['never'] ? 'checked' : '' ?>>Never met</label></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <div class="card"><div class="table-responsive" id="vocabulary-scroll">
        <table class="table table-hover mb-0" id="vocabulary-table">
            <thead><tr><th>Term</th><th>Aliases</th><th>Type</th><th>Description</th><th>Engine</th><th class="text-end"><span class="visually-hidden">Edit</span></th></tr></thead>
            <tbody>
                <?php if ($terms === []): ?><tr><td colspan="6" class="text-muted fs-12" id="vocabulary-empty">No terms<?= $filters['q'] !== '' || $filters['never'] ? ' match' : ' yet — a term names a thing the documents talk about, with the other names it goes by' ?>.</td></tr><?php endif; ?>
                <?php foreach ($terms as $t): ?><?= view('vocabulary/partials/term-row.php', ['t' => $t, 'may' => $may]) ?><?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>
