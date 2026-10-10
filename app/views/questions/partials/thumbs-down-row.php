<?php /** One thumbs-down answer. Data: f, tz */ ?>
<div class="list-group-item fs-13" id="thumbs-down-row-<?= (int) $f['feedback_id'] ?>">
    <div class="d-flex justify-content-between gap-2"><?= hx_link('/questions/' . (int) $f['question_id'], e(mb_substr(trim((string) strtok((string) $f['question'], "\n")), 0, 160))) ?><span class="fs-11 text-muted text-nowrap"><?= e((string) $f['member_name']) ?> · <?= e(format_ts($f['updated_at'], $tz, 'M j')) ?></span></div>
    <?php if ((string) $f['comment'] !== ''): ?><div class="text-muted mt-1"><i class="feather-thumbs-down text-danger me-1"></i><?= nl2br(e((string) $f['comment'])) ?></div><?php endif; ?>
</div>
