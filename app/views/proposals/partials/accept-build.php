<?php /** What an accepted stale_source proposal would build: the estimate of the base. Data: est (kn_build_estimate, or null), pid */ ?>
<div class="alert alert-light border fs-12 mb-2" id="proposal-accept-<?= (int) $pid ?>-estimate">
<?php if ($est === null): ?>
    <span class="text-muted">The estimate is not available to you.</span>
<?php elseif ($est['running_build_id'] !== null): ?>
    A build is already running — accepting now would be refused.
<?php elseif ((int) $est['sections'] === 0): ?>
    Nothing is waiting for a build any more.
<?php else: ?>
    Would build <strong><?= (int) $est['sections'] ?></strong> section<?= (int) $est['sections'] === 1 ? '' : 's' ?> — <strong><?= number_format((int) $est['characters']) ?></strong> characters.
    <?= number_format((int) $est['remaining_chars']) ?> remain this month<?= empty($est['within_limit']) ? ' — <span class="text-danger">more than the month has left</span>' : '' ?>.
<?php endif; ?>
</div>
