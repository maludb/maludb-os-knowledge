<?php /** My recent questions (home-recent): the five newest. Data: rows, tz */ ?>
<div class="card mb-3" id="home-recent">
    <div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">My recent questions</h5><?= hx_link('/ask', 'Ask', 'fs-12 fw-semibold', 'id="home-recent-ask"') ?></div>
    <div class="card-body">
    <?php if ($rows === []): ?>
        <div class="fs-12 text-muted" id="home-recent-none">Questions you ask are listed here, newest first.</div>
    <?php else: ?>
        <?php foreach ($rows as $q): $qid = (int) $q['question_id']; ?>
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2" id="home-question-<?= $qid ?>">
            <div class="min-w-0">
                <div class="fs-13 text-break"><?= hx_link(with_back('/questions/' . $qid, '/'), e(home_question_line((string) $q['question'])), 'text-dark', 'id="home-question-' . $qid . '-link"') ?></div>
                <div class="fs-11 text-muted"><?= e((string) $q['base_name']) ?> · <?= e(format_ts($q['created_at'], $tz, 'M j, g:i A')) ?></div>
            </div>
            <span class="text-nowrap"><?= chip(question_chip((string) $q['status'])) ?></span>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>
