<?php /** The duplicates region (curators). Data: b, dups (duplicate_subjects|null), error, here */
$id = (int) $b['base_id']; ?>
<div class="card mb-3" id="graph-duplicates"><div class="card-body">
    <h6 class="mb-2">Subjects that look like duplicates</h6>
    <?php if ($error !== null): ?>
        <?= view('graph/partials/engine-error.php', ['region' => 'duplicates', 'code' => $error]) ?>
    <?php elseif ($dups === null || $dups['groups'] === []): ?>
        <div class="text-muted fs-13" id="graph-duplicates-empty">No duplicate-looking subjects among the documents read<?= $dups !== null ? ' (' . (int) $dups['documents_read'] . ' of ' . (int) $dups['documents_total'] . ')' : '' ?>.</div>
    <?php else: ?>
        <?php foreach ($dups['groups'] as $n => $g): $merge = graph_merge_url($id, $g); ?>
            <div class="border rounded p-3 mb-2" id="dup-group-<?= $n + 1 ?>">
                <div class="fs-12 text-muted mb-1"><?= $g['reason'] === 'vocabulary' ? 'The vocabulary makes one the other\'s alias, yet both are still subjects.' : 'These names read alike.' ?></div>
                <ul class="list-unstyled mb-2">
                    <?php foreach ($g['subjects'] as $s): ?>
                        <li class="d-flex flex-wrap align-items-center gap-2"><?= hx_link(with_back(graph_subject_url($id, $s['name']), $here), e($s['name']), 'kn-row-link text-break') ?>
                            <span class="fs-12 text-muted"><?= (int) $s['documents'] ?> document<?= (int) $s['documents'] === 1 ? '' : 's' ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <div class="fs-13 mb-2">Suggested name: <strong class="text-break" id="dup-suggested-<?= $n + 1 ?>"><?= e($g['suggested']) ?></strong></div>
                <?= hx_link($merge, '<i class="feather-git-merge me-1"></i>Merge in the vocabulary', 'btn btn-light btn-touch', 'id="dup-merge-' . ($n + 1) . '"') ?>
                <div class="fs-11 text-muted mt-1">Saving the term marks the built sections for a rebuild; the next build folds the names into one subject.</div>
            </div>
        <?php endforeach; ?>
        <div class="fs-12 text-muted" id="graph-duplicates-read"><?= (int) $dups['documents_read'] ?> of <?= (int) $dups['documents_total'] ?> documents read.</div>
    <?php endif; ?>
</div></div>
