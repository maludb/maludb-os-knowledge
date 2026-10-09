<?php /** A base at a glance (screen `base-view`). Data: b (mcp_bases), o (kn_base_overview), level, curate, may, timeline, deleting, tabs, tz, here, notice */
$id = (int) $b['base_id'];
$empty = (int) ($o['sources']['total'] ?? 0) === 0;
$archived = $b['archived_at'] !== null;
$curators = $o['curators'] ?? []; ?>
<?= view('shared/header.php', ['id' => 'base-view', 'title' => $b['name'], 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], null]], 'back' => back_link(),
    'action' => $may['manage'] && !$archived ? hx_link('/bases/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="base-view-edit-btn"') : '']) ?>
<div class="main-content" id="base-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'overview']) ?>
    <?php if ($deleting): ?>
        <div class="alert alert-warning" id="base-view-deleting">This base is being deleted: the engine forgets its sections first, then it disappears.</div>
    <?php elseif ($archived): ?>
        <div class="alert alert-dark d-flex flex-wrap align-items-center gap-2" id="base-view-archived">
            <span class="me-auto">Archived <?= e(format_ts($b['archived_at'], $tz, 'M j, Y')) ?>. It stays readable; it takes no new source or build.</span>
            <?php if ($may['manage']): ?>
                <form method="post" action="/bases/restore.php" hx-post="/bases/restore.php" hx-target="#flash" id="base-restore-form" class="m-0"><?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="base-restore-btn">Restore</button></form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($empty && !$archived): ?>
        <div class="card mb-3" id="base-view-first-run"><div class="card-body text-center py-4">
            <?php if ($may['add_sources']): ?>
                <div class="fw-semibold mb-1">Add documents to <?= e($b['name']) ?></div>
                <div class="fs-12 text-muted mb-3">Files, pasted text or a web page. Each is cut into sections, and every answer quotes them.</div>
                <a href="/sources/new?base=<?= $id ?>" class="btn btn-primary btn-touch" id="base-add-documents"><i class="feather-upload me-1"></i>Add documents</a>
            <?php else: ?>
                <div class="fs-12 text-muted" id="base-view-nothing-yet">Nothing has been added to this base yet.</div>
            <?php endif; ?>
        </div></div>
    <?php endif; ?>
    <form method="get" action="/ask" class="card mb-3" id="base-ask-box"><div class="card-body p-3">
        <input type="hidden" name="base" value="<?= $id ?>">
        <label class="form-label fs-12 text-muted" for="base-ask-box-q">Ask <?= e($b['name']) ?></label>
        <div class="input-group"><input type="text" name="q" id="base-ask-box-q" class="form-control btn-touch" maxlength="2000" placeholder="What do the documents say about…"><button type="submit" class="btn <?= $empty ? 'btn-light' : 'btn-primary' ?> btn-touch" id="base-ask-box-btn">Ask</button></div>
    </div></form>
    <div class="card mb-3" id="base-view-about"><div class="card-body">
        <div class="d-flex flex-wrap gap-1 mb-2">
            <?= chip(base_status_chip((string) $b['status']), 'base-view-status') ?>
            <?= chip(base_visibility_chip((string) $b['visibility']), 'base-view-visibility') ?>
            <?= chip(base_level_chip($level), 'base-view-level') ?>
            <?php if ($archived): ?><?= chip(['archived', 'dark'], 'base-view-archived-chip') ?><?php endif; ?>
            <?php if ((int) $b['stale_count'] > 0): ?><?= chip(['needs a rebuild', 'warning'], 'base-view-stale') ?><?php endif; ?>
        </div>
        <?php if (($b['description'] ?? '') !== ''): ?><div class="fs-13 mb-2 kn-prewrap" id="base-view-description"><?= e($b['description']) ?></div><?php endif; ?>
        <div class="fs-12" id="base-curators"><span class="text-muted">Owner</span> <?= e((string) $o['owner_name']) ?>
            <?php $others = array_values(array_filter($curators, static fn (array $c): bool => (int) $c['member_id'] !== (int) $o['owner_member_id'])); ?>
            <?php if ($others !== []): ?> · <span class="text-muted">curators</span> <?= e(implode(', ', array_map(static fn (array $c): string => (string) $c['display_name'], $others))) ?><?php endif; ?>
        </div>
        <div class="fs-11 text-muted mt-1">Template <?= e($b['template']) ?> · key <code><?= e($b['key']) ?></code> · last built <?= $b['last_built_at'] !== null ? e(format_ts($b['last_built_at'], $tz)) : 'never' ?></div>
    </div></div>
    <?= view('bases/partials/counts.php', ['o' => $o]) ?>
    <?php if (($o['areas'] ?? []) !== []): ?>
        <div class="card mb-3" id="base-view-areas"><div class="card-body p-3"><div class="fs-12 text-muted mb-1">Focus areas</div>
            <div class="d-flex flex-wrap gap-1"><?php foreach ($o['areas'] as $a): ?><?= chip([$a['name'] . ' · ' . (int) $a['source_count'], $a['status'] === 'proposed' ? 'warning' : 'secondary'], 'base-view-area-' . (int) $a['focus_area_id']) ?><?php endforeach; ?></div>
        </div></div>
    <?php endif; ?>
    <?php if (!empty($o['last_build'])): $lb = $o['last_build']; ?>
        <div class="card mb-3" id="base-view-last-build"><div class="card-body p-3 fs-12">
            <span class="text-muted">Last build</span> <?= e(str_replace('_', ' ', (string) $lb['kind'])) ?> · <?= e((string) $lb['status']) ?> · <?= (int) $lb['items_done'] ?> of <?= (int) $lb['items_total'] ?> sections<?= (int) $lb['items_failed'] > 0 ? ' · ' . (int) $lb['items_failed'] . ' failed' : '' ?> · <?= e(format_ts($lb['finished_at'] ?? $lb['requested_at'], $tz)) ?>
        </div></div>
    <?php endif; ?>
    <?php if ($curate): $g = $o['guidelines'] ?? []; $est = $o['estimate'] ?? null; ?>
        <div class="card mb-3" id="base-view-curator"><div class="card-header"><h5 class="card-title mb-0">For the curators</h5></div><div class="card-body fs-12">
            <div class="mb-1" id="base-view-guidelines">Guidelines: <?php if ($g === []): ?>none<?php else: ?><?= e(implode(' · ', array_map(static fn ($k, $n): string => $k . ' ' . $n, array_keys($g), array_values($g)))) ?><?php endif; ?></div>
            <div class="mb-1">Vocabulary <?= (int) ($o['vocabulary_count'] ?? 0) ?> terms · keys <?= (int) ($o['keys_count'] ?? 0) ?> · feeds <?= (int) ($o['feeds_count'] ?? 0) ?></div>
            <div class="mb-1">Open proposals <?= (int) ($o['open_proposals'] ?? 0) ?> · unanswered questions in 30 days <?= (int) ($o['gaps_30d'] ?? 0) ?></div>
            <?php if (is_array($est)): ?><div id="base-view-estimate">A build now: <?= (int) $est['sections'] ?> section<?= (int) $est['sections'] === 1 ? '' : 's' ?>, <?= number_format((int) $est['characters']) ?> characters — <?= e((string) $est['limit_pct_after']) ?>% of this month's <?= (int) $est['month_limit_mb'] ?> MB<?= empty($est['within_limit']) ? ' (over the limit)' : '' ?>.</div><?php endif; ?>
        </div></div>
    <?php endif; ?>
    <?php if ($may['manage'] || $may['share'] || $may['delete']): ?>
        <div class="card mb-3" id="base-view-actions"><div class="card-body d-flex flex-wrap gap-2">
            <?php if ($may['manage'] && !$archived): ?><?= hx_link('/bases/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="base-action-edit"') ?><?php endif; ?>
            <?php if ($may['share']): ?><?= hx_link('/bases/' . $id . '/share', '<i class="feather-users me-1"></i>Share', 'btn btn-light btn-touch', 'id="base-action-share"') ?><?php endif; ?>
            <?php if ($may['manage']): ?><?= hx_link('/bases/' . $id . '/settings', '<i class="feather-sliders me-1"></i>Settings', 'btn btn-light btn-touch', 'id="base-action-settings"') ?><?php endif; ?>
            <?php if ($may['manage'] && !$archived): ?>
                <form method="post" action="/bases/archive.php" hx-post="/bases/archive.php" hx-target="#flash" hx-confirm="Archive <?= e($b['name']) ?>? It stays readable and takes no new source or build." class="m-0" id="base-archive-form"><?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="base-action-archive"><i class="feather-archive me-1"></i>Archive</button></form>
            <?php endif; ?>
        </div>
        <?php if ($may['delete'] && !$deleting): ?>
            <details class="border-top px-3 py-2" id="base-delete">
                <summary class="fs-12 fw-semibold text-danger btn-touch d-flex align-items-center" id="base-delete-summary">Delete this knowledge base</summary>
                <form method="post" action="/bases/delete.php" hx-post="/bases/delete.php" hx-target="#flash" id="base-delete-form" class="pt-2">
                    <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>">
                    <div class="fs-12 text-muted mb-2">Its sources, sections, guidelines, questions and keys go with it; the engine forgets what it learned. This cannot be undone. Type its name to confirm.</div>
                    <label class="form-label fs-12 text-muted" for="base-delete-confirm">The base's name</label>
                    <input type="text" name="confirm_name" id="base-delete-confirm" class="form-control btn-touch mb-2" required autocomplete="off" placeholder="<?= e($b['name']) ?>">
                    <button type="submit" class="btn btn-danger btn-touch w-100" id="base-delete-btn">Delete</button>
                </form>
            </details>
        <?php endif; ?>
        </div>
    <?php endif; ?>
    <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'base']) ?>
</div>
