<?php /** A source (screen `source-view`). Data: s, b, outline, pages, may (manage, retry, areas), curate, successor, timeline, activeAreas, tz, here, notice */
$sid = (int) $s['source_id']; $bid = (int) $b['base_id'];
[$kindWords, $icon] = SOURCE_KINDS[$s['kind']] ?? ['Source', 'feather-file'];
$err = source_error_words($s['error'], (string) $s['status']);
$stale = 0;
$countStale = static function (array $nodes) use (&$countStale, &$stale): void { foreach ($nodes as $n) { if ($n['status'] === 'stale') { $stale++; } $countStale($n['children']); } };
$countStale($outline); ?>
<?= view('shared/header.php', ['id' => 'source-view', 'title' => $s['title'], 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Sources', '/bases/' . $bid . '/sources'], [$s['title'], null]],
    'back' => back_link() ?? ['/bases/' . $bid . '/sources', 'the sources']]) ?>
<div class="main-content" id="source-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($successor !== null): ?><div class="alert alert-dark" id="source-replaced-by">Replaced by <?= hx_link('/sources/' . (int) $successor['source_id'], e($successor['title']), 'alert-link') ?>.</div><?php endif; ?>
    <div class="card mb-3" id="source-about"><div class="card-body">
        <div class="d-flex flex-wrap gap-1 mb-2">
            <?= chip([$kindWords, 'secondary'], 'source-kind') ?>
            <?= view('sources/partials/status-chip.php', ['status' => $s['status'], 'id' => 'source-status']) ?>
            <?php if ($stale > 0): ?><?= chip([$stale . ' section' . ($stale === 1 ? '' : 's') . ' stale', 'warning'], 'source-stale') ?><?php endif; ?>
            <?php foreach ($s['areas'] as $a): ?><?= chip([$a['name'], 'secondary']) ?><?php endforeach; ?>
        </div>
        <?php if ($err !== '' && in_array($s['status'], ['failed', 'no_text'], true)): ?><div class="alert alert-<?= $s['status'] === 'failed' ? 'danger' : 'warning' ?> py-2 fs-12" id="source-error"><?= e($err) ?></div><?php endif; ?>
        <div class="fs-12" id="source-origin">
            <?php if ($s['kind'] === 'file'): ?>
                <span class="text-muted">File</span> <?= e((string) $s['origin']) ?>
                <?php if ($s['attachment_id'] !== null && $s['status'] !== 'forgotten'): ?> · <a href="/files/<?= (int) $s['attachment_id'] ?>" id="source-download">Download</a><?php endif; ?>
            <?php elseif ($s['kind'] === 'url'): ?>
                <span class="text-muted">Address</span> <a href="<?= e((string) $s['origin']) ?>" rel="noopener nofollow" target="_blank" id="source-url" class="text-break"><?= e((string) $s['origin']) ?></a>
                <?php if ($s['url_checked_at'] !== null): ?><span class="text-muted"> · checked <?= e(format_ts($s['url_checked_at'], $tz)) ?></span><?php endif; ?>
            <?php elseif ($s['kind'] === 'feed'): ?>
                <span class="text-muted">From a feed</span>
            <?php else: ?>
                <span class="text-muted">Pasted text</span>
            <?php endif; ?>
        </div>
        <div class="fs-12 text-muted mt-1"><?= number_format((int) $s['char_count']) ?> characters · <?= (int) $s['page_count'] ?> page<?= (int) $s['page_count'] === 1 ? '' : 's' ?> · <?= (int) $s['sections_count'] ?> section<?= (int) $s['sections_count'] === 1 ? '' : 's' ?>
            <?= $s['built_under'] !== null ? ' · built under v' . (int) $s['built_under'] : '' ?><?= $s['language'] !== null ? ' · ' . e($s['language']) : '' ?></div>
        <div class="fs-11 text-muted mt-1">Added by <?= e((string) ($s['added_by_name'] ?? 'the worker')) ?> · <?= e(format_ts($s['added_at'], $tz)) ?></div>
    </div></div>
    <?php if ($may['manage'] || $may['retry']): ?>
        <div class="card mb-3" id="source-actions"><div class="card-body d-flex flex-wrap gap-2">
            <?php if ($may['manage']): ?>
                <?= hx_link('/sources/' . $sid . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="source-action-edit"') ?>
                <?= hx_link('/sources/' . $sid . '/replace', '<i class="feather-refresh-cw me-1"></i>Replace', 'btn btn-light btn-touch', 'id="source-action-replace"') ?>
                <form method="post" action="/sources/remove.php" hx-post="/sources/remove.php" hx-target="#flash" hx-confirm="Remove <?= e($s['title']) ?>? The base forgets what it taught." class="m-0" id="source-remove-form">
                    <?= csrf_field() ?><input type="hidden" name="source" value="<?= $sid ?>"><button type="submit" class="btn btn-light btn-touch text-danger" id="source-action-remove"><i class="feather-trash-2 me-1"></i>Remove</button>
                </form>
            <?php endif; ?>
            <?php if ($may['retry']): ?>
                <form method="post" action="/sources/retry.php" hx-post="/sources/retry.php" hx-target="#flash" class="m-0" id="source-retry-form">
                    <?= csrf_field() ?><input type="hidden" name="source" value="<?= $sid ?>"><button type="submit" class="btn btn-primary btn-touch" id="source-action-retry"><i class="feather-rotate-ccw me-1"></i>Try again</button>
                </form>
            <?php endif; ?>
        </div></div>
    <?php endif; ?>
    <?php if ($may['areas'] && $activeAreas !== []): ?><?= view('sources/partials/areas-form.php', ['s' => $s, 'areas' => $activeAreas]) ?><?php endif; ?>
    <?php if ($outline !== []): ?>
        <div class="card mb-3" id="source-outline-card"><div class="card-header"><h5 class="card-title mb-0">Outline</h5></div><div class="card-body kn-outline-scroll">
            <?= view('sources/partials/outline.php', ['nodes' => $outline, 'curate' => $curate]) ?>
        </div></div>
    <?php elseif (in_array($s['status'], ['uploaded', 'extracting_text', 'sectioning'], true)): ?>
        <div class="card mb-3"><div class="card-body fs-12 text-muted" id="source-pending">Being read and cut into sections — within the minute.</div></div>
    <?php endif; ?>
    <?php if ($pages !== []): ?><?= view('sources/partials/pages.php', ['pages' => $pages]) ?><?php endif; ?>
    <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'source']) ?>
</div>
