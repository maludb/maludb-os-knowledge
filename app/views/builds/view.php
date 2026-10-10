<?php /** One build (screen `build-view`). Data: x, b, p, items, may, log, tz, notice */
$id = (int) $x['build_id']; $bid = (int) $b['base_id'];
$usage = json_decode((string) $x['usage'], true) ?: []; ?>
<?= view('shared/header.php', ['id' => 'build-view', 'title' => 'Build ' . $id, 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Builds', '/bases/' . $bid . '/builds'], ['Build ' . $id, null]],
    'back' => back_link() ?? ['/bases/' . $bid . '/builds', 'the builds']]) ?>
<div class="main-content" id="build-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body">
        <?= view('builds/partials/progress.php', ['p' => $p, 'poll' => true, 'tz' => $tz, 'cancel' => $may['cancel']]) ?>
    </div></div>
    <div class="card mb-3" id="build-facts"><div class="card-body fs-12">
        <div><?= e(str_replace('_', ' ', $x['kind'])) ?><?= $x['started_by'] === null ? ' — automatic' : ' — started by ' . e((string) $x['started_by_name']) ?> · guidelines version <?= (int) $x['guideline_version'] ?></div>
        <?php if ($log !== []): ?><div class="text-muted text-break" id="build-hash"><?= e($log[0]) ?></div><?php endif; ?>
        <div class="mt-1"><?= number_format((int) $x['chars_processed']) ?> characters · <?= (int) $x['statements_added'] ?> statements added · <?= (int) $x['subjects_added'] ?> subjects added</div>
        <div class="text-muted" id="build-usage"><?= (int) ($usage['calls'] ?? 0) ?> engine calls, <?= number_format((int) ($usage['chars'] ?? 0)) ?> characters<?= (int) ($usage['retries'] ?? 0) > 0 ? ', ' . (int) $usage['retries'] . ' retries' : '' ?> — token counts arrive with MaluDB M2; the dollars are on the business's AI statement.</div>
    </div></div>
    <?php if (($p['failed_items'] ?? []) !== []): ?>
        <div class="card mb-3" id="build-failed"><div class="card-header"><h5 class="card-title mb-0">Failed sections</h5></div><div class="list-group list-group-flush">
            <?php foreach ($p['failed_items'] as $f): ?>
                <div class="list-group-item fs-12" id="build-failed-<?= (int) $f['section_id'] ?>"><?= hx_link('/sections/' . (int) $f['section_id'], e((string) $f['heading_path'])) ?><div class="text-muted"><?= e((string) $f['error']) ?></div></div>
            <?php endforeach; ?>
        </div></div>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Sections (<?= (int) $items['total'] ?>)</h5></div><div class="table-responsive" id="build-items-scroll">
        <table class="table table-sm mb-0" id="build-items"><thead><tr><th>Section</th><th>Status</th><th>Characters</th><th>Note</th></tr></thead>
            <tbody><?php foreach ($items['rows'] as $i): ?><?= view('builds/partials/item-row.php', ['i' => $i]) ?><?php endforeach; ?></tbody></table>
    </div>
        <?php if ($items['pages'] > 1): ?><div class="card-body d-flex gap-2"><?php for ($n = 1; $n <= $items['pages']; $n++): ?><?= hx_link('/builds/' . $id . '?page=' . $n, (string) $n, 'btn btn-light btn-sm btn-touch' . ($n === $items['page'] ? ' active' : ''), 'id="build-items-page-' . $n . '"') ?><?php endfor; ?></div><?php endif; ?>
    </div>
    <?php if (count($log) > 1): ?><details class="card mb-3" id="build-log"><summary class="card-body fw-semibold btn-touch d-flex align-items-center">The log</summary><pre class="kn-preview-pre mx-3 mb-3"><?= e(implode("\n", $log)) ?></pre></details><?php endif; ?>
</div>
