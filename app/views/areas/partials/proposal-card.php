<?php /** A proposed area with Accept · Dismiss (areas, curators). Data: a, may */
$aid = (int) $a['focus_area_id']; ?>
<div class="card h-100 mb-0 border-primary" id="proposal-card-<?= $aid ?>"><div class="card-body d-flex flex-column">
    <div class="fw-semibold fs-14"><?= e($a['name']) ?></div>
    <div class="fs-12 text-muted mb-1">Proposed by <?= e((string) ($a['proposed_by_name'] ?? 'an agent')) ?> <?= chip(['agent', 'info']) ?></div>
    <div class="fs-12 kn-prewrap mb-2"><?= e((string) $a['proposal_note']) ?></div>
    <div class="d-flex flex-wrap gap-1 mb-2 fs-12"><?php foreach ($a['seed_terms_list'] as $t): ?><?= chip([$t, 'light']) ?><?php endforeach; ?></div>
    <?php if ($may['manage']): ?>
    <div class="d-flex gap-2 mt-auto">
        <form method="post" action="/areas/accept.php" hx-post="/areas/accept.php" hx-target="#flash" class="m-0 flex-fill" id="proposal-card-<?= $aid ?>-accept-form"><?= csrf_field() ?><input type="hidden" name="focus_area" value="<?= $aid ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="proposal-card-<?= $aid ?>-accept">Accept</button></form>
        <form method="post" action="/areas/dismiss.php" hx-post="/areas/dismiss.php" hx-target="#flash" class="m-0 flex-fill" id="proposal-card-<?= $aid ?>-dismiss-form"><?= csrf_field() ?><input type="hidden" name="focus_area" value="<?= $aid ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="proposal-card-<?= $aid ?>-dismiss">Dismiss</button></form>
    </div>
    <?php endif; ?>
</div></div>
