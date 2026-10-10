<?php /** Pins due for review (home-pins): mine, and those on bases I curate. Data: rows */ ?>
<div class="card mb-3" id="home-pins">
    <div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Pins due for review</h5><?= hx_link('/pinned?due=1', 'Pinned', 'fs-12 fw-semibold', 'id="home-pins-all"') ?></div>
    <div class="card-body">
    <?php if ($rows === []): ?>
        <div class="fs-12 text-muted" id="home-pins-none">A pinned answer whose review date has passed appears here.</div>
    <?php else: ?>
        <?php foreach ($rows as $p): $pid = (int) $p['pinned_answer_id']; ?>
        <div class="mb-2" id="home-pin-<?= $pid ?>">
            <div class="fs-13"><?= hx_link(with_back('/pinned?due=1', '/'), e((string) $p['title']), 'text-dark', 'id="home-pin-' . $pid . '-link"') ?></div>
            <div class="fs-11 text-muted"><?= e((string) $p['base_name']) ?> · review was due <?= e(format_date((string) $p['review_until'])) ?></div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>
