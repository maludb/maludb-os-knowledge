<?php /** One gap cluster. Data: g, b, tz */
$ids = kn_pg_literal_list($g['question_ids']) ?? []; $key = preg_replace('/[^a-z0-9_-]/', '-', (string) $g['cluster_key']); ?>
<div class="col-12 col-lg-6"><div class="card h-100" id="gap-<?= e($key) ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2 mb-1"><span class="badge bg-soft-secondary text-secondary">“<?= e((string) $g['cluster_key']) ?>”</span><span class="fs-12 text-muted"><?= (int) $g['question_count'] ?> question<?= (int) $g['question_count'] === 1 ? '' : 's' ?> · last <?= e(format_ts($g['last_asked_at'], $tz, 'M j')) ?></span></div>
    <div class="fw-semibold mb-2"><?= e(mb_substr((string) $g['sample_question'], 0, 200)) ?></div>
    <div class="fs-12 mb-2"><?php foreach (array_slice($ids, 0, 12) as $qid): ?><?= hx_link('/questions/' . (int) $qid . '?back=' . rawurlencode('/bases/' . (int) $b['base_id'] . '/gaps'), '#' . (int) $qid, 'me-2') ?><?php endforeach; ?></div>
    <div class="d-flex flex-wrap gap-2">
        <?= hx_link('/sources/new?base=' . (int) $b['base_id'], '<i class="feather-plus me-1"></i>Add a source', 'btn btn-light btn-touch', 'id="gap-add-' . e($key) . '"') ?>
        <a class="btn btn-light btn-touch" href="<?= e('/ask?base=' . (int) $b['base_id'] . '&q=' . rawurlencode(mb_substr((string) $g['sample_question'], 0, 2000))) ?>" id="gap-ask-<?= e($key) ?>">Ask again</a>
    </div>
</div></div></div>
