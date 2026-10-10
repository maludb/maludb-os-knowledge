<?php /** One pinned answer. Data: p, tz */
$pid = (int) $p['pinned_answer_id']; ?>
<div class="col-12 col-xl-6"><div class="card h-100" id="pin-<?= $pid ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
        <div class="fw-semibold"><i class="feather-bookmark me-1"></i><?= e((string) $p['title']) ?></div>
        <?php if ($p['review_due']): ?><?= chip(['review due', 'warning'], 'pin-due-' . $pid) ?><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mb-2"><?= hx_link('/bases/' . (int) $p['base_id'], e((string) $p['base_name'])) ?> · <?= e((string) $p['owner_name']) ?> · verified <?= e(format_ts($p['verified_at'], $tz, 'M j, Y')) ?> · review by <?= e(date('M j, Y', strtotime((string) $p['review_until']))) ?></div>
    <div class="kn-md fs-13 kn-pin-body"><?= kn_markdown_html((string) $p['answer_md'], null) ?></div>
    <div class="d-flex flex-wrap gap-2 mt-2">
        <?php if ($p['question_id'] !== null): ?><?= hx_link('/questions/' . (int) $p['question_id'] . '?back=' . rawurlencode('/pinned'), 'The question', 'btn btn-light btn-touch', 'id="pin-question-' . $pid . '"') ?><?php endif; ?>
        <?php if ($p['source_id'] !== null): ?><?= hx_link('/sources/' . (int) $p['source_id'], 'The source it became', 'btn btn-light btn-touch', 'id="pin-source-' . $pid . '"') ?><?php endif; ?>
        <?php if ($p['i_curate'] && has_right('answers.pin')): ?>
            <form method="post" action="/pinned/review.php" hx-post="/pinned/review.php" hx-target="#flash" class="m-0"><?= csrf_field() ?><input type="hidden" name="pinned_answer" value="<?= $pid ?>"><button type="submit" class="btn btn-light btn-touch" id="pin-review-<?= $pid ?>">Verify again</button></form>
            <form method="post" action="/pinned/unpin.php" hx-post="/pinned/unpin.php" hx-target="#flash" hx-confirm="Unpin it? Its note source is forgotten at the next pass." class="m-0"><?= csrf_field() ?><input type="hidden" name="pinned_answer" value="<?= $pid ?>"><button type="submit" class="btn btn-light btn-touch text-danger" id="pin-unpin-<?= $pid ?>">Unpin</button></form>
        <?php endif; ?>
    </div>
</div></div></div>
