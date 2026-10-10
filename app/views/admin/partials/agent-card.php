<?php /** A shipped agent (maludb-os.json agents[]). Data: a (declared_agents row), who (the member named for it|''), note (last_monday_note|null — the Archivist's), tz */
$key = (string) $a['key']; ?>
<div class="card mb-3" id="agent-card-<?= e($key) ?>"><div class="card-body">
    <div class="d-flex justify-content-between gap-2 mb-1"><span class="fw-semibold fs-14"><i class="feather-cpu me-1"></i><?= e(ucfirst($key)) ?></span><span class="text-nowrap"><?= $who !== '' ? chip(['is ' . $who, 'success'], 'agent-card-' . $key . '-who') : chip(['not named', 'warning'], 'agent-card-' . $key . '-who') ?></span></div>
    <div class="fs-12 text-muted mb-2" id="agent-card-<?= e($key) ?>-job"><?= e((string) $a['job']) ?></div>
    <div class="fs-12" id="agent-card-<?= e($key) ?>-duty"><?php if ($a['duty'] !== null): ?>Duty: <?= e($a['duty']['name']) ?> — <strong><?= e($a['duty']['schedule']) ?></strong><?php else: ?><span class="text-muted">No duty: it works when it is asked.</span><?php endif; ?></div>
    <div class="fs-12 text-muted" id="agent-card-<?= e($key) ?>-counts"><?= (int) $a['skills'] ?> skills · <?= (int) $a['tools'] ?> tools granted<?= $a['hired_on_install'] ? ' · hired on install' : '' ?></div>
    <?php if ($note !== null): ?><div class="fs-12 mt-1" id="agent-card-<?= e($key) ?>-note">Last Monday note: <?= e(format_ts($note['at'], $tz, 'M j, g:i A')) ?> · told <?= (int) $note['recipients'] ?> curator<?= (int) $note['recipients'] === 1 ? '' : 's' ?></div>
    <?php elseif ($a['duty'] !== null): ?><div class="fs-12 text-muted mt-1" id="agent-card-<?= e($key) ?>-note">No note has been sent yet.</div><?php endif; ?>
</div></div>
