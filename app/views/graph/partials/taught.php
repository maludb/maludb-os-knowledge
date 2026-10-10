<?php /** What a section or a source taught the graph (slice 2's section-view and source-view). Data: t (section_taught|source_taught), b, here, scope (section|source) */
$id = (int) $b['base_id']; ?>
<div class="card mb-3" id="taught"><div class="card-body">
    <h6 class="mb-2">What it taught the graph</h6>
    <?php if (!$t['ok']): ?>
        <?= view('graph/partials/engine-error.php', ['region' => 'taught', 'code' => $t['code']]) ?>
    <?php elseif (!$t['built']): ?>
        <div class="text-muted fs-13" id="taught-not-built">Not in the graph yet.</div>
    <?php elseif ($t['subjects'] === [] && $t['statements'] === []): ?>
        <div class="text-muted fs-13" id="taught-empty">The build found no subjects or statements here.</div>
    <?php else: ?>
        <?php if ($scope === 'source'): ?><div class="fs-12 text-muted mb-2" id="taught-read"><?= (int) $t['sections_read'] ?> of <?= (int) $t['sections_total'] ?> sections read.</div><?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mb-2" id="taught-subjects">
            <?php foreach ($t['subjects'] as $i => $s): ?>
                <?= hx_link(with_back(graph_subject_url($id, $s['name']), $here), '<i class="feather-circle text-primary me-1"></i>' . e($s['name']) . (isset($s['sections']) ? ' <span class="text-muted fs-11">· ' . (int) $s['sections'] . '</span>' : ''), 'btn btn-light btn-touch', 'id="taught-subject-' . ($i + 1) . '"') ?>
            <?php endforeach; ?>
        </div>
        <?php if ($t['statements'] !== []): ?>
            <div class="fs-12 text-muted mb-1" id="taught-statements-count"><?= count($t['statements']) ?> statement<?= count($t['statements']) === 1 ? '' : 's' ?></div>
            <ul class="mb-0 fs-13" id="taught-statements">
                <?php foreach (array_slice($t['statements'], 0, 10) as $x): ?><li class="text-break"><?= e($x['text']) ?><?= $x['verb'] !== null ? ' <span class="text-muted fs-11">· ' . e($x['verb']) . '</span>' : '' ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</div></div>
