<?php /** A source as a card (sources). Data: s (find_sources row with areas), here, tz */
$sid = (int) $s['source_id'];
[$kindWords, $icon] = SOURCE_KINDS[$s['kind']] ?? ['Source', 'feather-file'];
$err = source_error_words($s['error'], (string) $s['status']); ?>
<div class="card h-100 mb-0" id="source-card-<?= $sid ?>"><div class="card-body d-flex flex-column">
    <div class="d-flex align-items-start gap-2">
        <span class="avatar-text avatar-md rounded flex-shrink-0" title="<?= e($kindWords) ?>"><i class="<?= e($icon) ?>"></i></span>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold fs-14 text-truncate"><?= hx_link(with_back('/sources/' . $sid, $here), e($s['title']), 'text-dark', 'id="source-card-' . $sid . '-link"') ?></div>
            <div class="fs-11 text-muted text-truncate"><?= e($kindWords) ?><?= $s['kind'] === 'file' || $s['kind'] === 'url' ? ' · ' . e((string) $s['origin']) : '' ?></div>
        </div>
    </div>
    <div class="mt-2 d-flex flex-wrap gap-1 fs-12">
        <?= view('sources/partials/status-chip.php', ['status' => $s['status'], 'id' => 'source-card-' . $sid . '-status']) ?>
        <?php if (!empty($s['is_stale'])): ?><?= chip(['stale', 'warning'], 'source-card-' . $sid . '-stale') ?><?php endif; ?>
        <?php foreach ($s['areas'] as $a): ?><?= chip([$a['name'], 'secondary']) ?><?php endforeach; ?>
    </div>
    <?php if ($err !== '' && in_array($s['status'], ['failed', 'no_text'], true)): ?><div class="fs-12 text-danger mt-1" id="source-card-<?= $sid ?>-error"><?= e($err) ?></div><?php endif; ?>
    <div class="fs-12 text-muted mt-2"><?= (int) $s['sections_count'] ?> section<?= (int) $s['sections_count'] === 1 ? '' : 's' ?> · <?= number_format((int) $s['char_count']) ?> characters<?= (int) $s['page_count'] > 0 ? ' · ' . (int) $s['page_count'] . ' page' . ((int) $s['page_count'] === 1 ? '' : 's') : '' ?></div>
    <div class="fs-11 text-muted mt-auto pt-2">Added by <?= e((string) ($s['added_by_name'] ?? 'the worker')) ?> · <?= e(format_ts($s['added_at'], $tz, 'M j, Y')) ?></div>
</div></div>
