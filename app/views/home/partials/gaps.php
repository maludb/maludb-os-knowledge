<?php /** Gaps this week (home-gaps; curators): per base I curate, the top three clusters of questions it could not answer and the thumbs-down count. Data: gaps */ ?>
<div class="card mb-3" id="home-gaps">
    <div class="card-header"><h5 class="card-title mb-0">Gaps this week</h5></div>
    <div class="card-body">
    <?php if ($gaps === []): ?>
        <div class="fs-12 text-muted" id="home-gaps-none">Questions your bases could not answer, and answers rated down, in the last seven days, appear here.</div>
    <?php else: ?>
        <?php foreach ($gaps as $g): $bid = (int) $g['base_id']; ?>
        <div class="mb-3" id="home-gap-<?= $bid ?>">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-1"><span class="fw-semibold fs-13 text-truncate"><?= hx_link('/bases/' . $bid . '/gaps?since=7', e((string) $g['name']), 'text-dark', 'id="home-gap-' . $bid . '-link"') ?></span>
                <?= (int) $g['thumbs_down'] > 0 ? chip([(int) $g['thumbs_down'] . ' rated down', 'danger'], 'home-gap-' . $bid . '-down') : '' ?></div>
            <?php foreach ($g['clusters'] as $c): ?><div class="fs-12 text-muted text-break"><?= (int) $c['question_count'] ?> × “<?= e(home_question_line((string) $c['sample_question'])) ?>”</div><?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>
