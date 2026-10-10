<?php /** The search region. Data: b, q, search (graph_search|null), error, here */
$id = (int) $b['base_id']; ?>
<div class="card mb-3" id="graph-search"><div class="card-body">
    <h6 class="mb-2">Search the graph</h6>
    <form method="get" action="/bases/<?= $id ?>/graph" hx-get="/bases/<?= $id ?>/graph" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="d-flex gap-2 mb-2" id="graph-search-form">
        <label class="visually-hidden" for="graph-search-q">Words to look for</label>
        <input type="search" name="q" id="graph-search-q" class="form-control btn-touch" maxlength="200" value="<?= e($q) ?>" placeholder="A subject, a product, a term…" autocomplete="off">
        <button type="submit" class="btn btn-primary btn-touch" id="graph-search-btn">Search</button>
    </form>
    <?php if ($error !== null): ?>
        <?= view('graph/partials/engine-error.php', ['region' => 'search', 'code' => $error]) ?>
    <?php elseif ($search !== null): ?>
        <?php if ($search['items'] === []): ?>
            <div class="text-muted fs-13" id="graph-search-empty">Nothing in this base matches those words.</div>
        <?php else: ?>
            <div class="list-group list-group-flush" id="graph-search-results">
                <?php foreach ($search['items'] as $i => $it): ?>
                    <?= hx_link(with_back(graph_subject_url($id, $it['name']), $here), '<span class="d-flex flex-wrap align-items-center gap-2 w-100"><i class="feather-circle text-primary"></i><span class="fw-semibold text-break">' . e($it['name']) . '</span>'
                        . ($it['seed'] ? '<span class="badge bg-soft-primary text-primary">match</span>' : '') . '<span class="ms-auto fs-12 text-muted">' . (int) $it['documents'] . ' document' . ((int) $it['documents'] === 1 ? '' : 's') . '</span></span>',
                        'list-group-item list-group-item-action kn-row-link', 'id="graph-search-item-' . ($i + 1) . '"') ?>
                <?php endforeach; ?>
            </div>
            <?php if ($search['more']): ?><div class="fs-12 text-muted mt-2" id="graph-search-more">and more — narrow the words.</div><?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div></div>
