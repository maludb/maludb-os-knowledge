<?php /** The Archivist's last note to me (home-note): its title and first lines, linking to the bell. Data: note (home_note|null), tz */ ?>
<div class="card mb-3" id="home-note">
    <div class="card-header"><h5 class="card-title mb-0">The Archivist's note</h5></div>
    <div class="card-body">
    <?php if ($note === null): ?>
        <div class="fs-12 text-muted" id="home-note-none">The Archivist writes to the curators of a base every Monday morning; the latest note is shown here.</div>
    <?php else: ?>
        <div class="fw-semibold fs-13"><?= hx_link(with_back('/notifications#notification-' . (int) $note['notification_id'], '/'), e((string) $note['title']), 'text-dark', 'id="home-note-link"') ?></div>
        <?php foreach ($note['lines'] as $l): ?><div class="fs-12 text-muted text-break"><?= e($l) ?></div><?php endforeach; ?>
        <div class="fs-11 text-muted mt-1"><?= e(format_ts($note['created_at'], $tz, 'M j, g:i A')) ?></div>
    <?php endif; ?>
    </div>
</div>
