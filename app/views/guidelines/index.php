<?php /** A base's guidelines by kind (screen `guidelines`). Data: b, groups, built, compiled, may, tabs, tz, notice */
$id = (int) $b['base_id'];
$warn = [];
foreach ($compiled['truncated'] as $block => $n) { $warn[] = $n . ' ' . strtoupper(str_replace('_', ' ', $block)) . ' line' . ($n === 1 ? '' : 's') . ' did not fit — shorten the ' . ($block === 'term' ? 'vocabulary' : str_replace('_', ' ', $block) . ' guidelines') . '.'; }
foreach ($compiled['conflicts'] as $c) { $warn[] = '"' . $c['alias'] . '" is claimed by ' . $c['kept_for'] . ' and ' . $c['dropped_for'] . ' — only ' . $c['kept_for'] . ' keeps it.'; } ?>
<?= view('shared/header.php', ['id' => 'guidelines', 'title' => 'Guidelines', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Guidelines', null]], 'back' => back_link(),
    'action' => hx_link('/bases/' . $id . '/compiled', '<i class="feather-eye me-1"></i>See what the engine is told', 'btn btn-light btn-touch', 'id="guidelines-compiled-link"')]) ?>
<div class="main-content" id="guidelines-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'guidelines']) ?>
    <?php if ($may['manage'] && is_file(APP_ROOT . '/html/agents/draft-guidelines.php')): // slice 8: the expert is woken by a dispatch ?>
        <form method="post" action="/agents/draft-guidelines.php" hx-post="/agents/draft-guidelines.php" hx-target="#flash" class="mb-3" id="guidelines-draft-form">
            <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>">
            <button type="submit" class="btn btn-light btn-touch" id="guidelines-draft-btn"><i class="feather-cpu me-1"></i>Draft guidelines from a sample</button>
            <span class="fs-11 text-muted ms-1">The Knowledge Expert reads a sample of the sources and drafts guidelines — saved inactive, for you to review.</span>
        </form>
    <?php endif; ?>
    <div class="card mb-3" id="built-under"><div class="card-body p-3 fs-12">
        <div class="fw-semibold fs-13">The graph is built under version <?= $built['built_version'] !== null ? (int) $built['built_version'] : '—' ?>; the guidelines are at version <?= (int) $built['version'] ?>.</div>
        <div class="text-muted"><?php $parts = []; foreach ($built['by_version'] as $v => $n) { $parts[] = $n . ' section' . ($n === 1 ? '' : 's') . ' at v' . $v; } if ($built['stale'] > 0) { $parts[] = $built['stale'] . ' stale'; } if ($built['queued'] > 0) { $parts[] = $built['queued'] . ' not built yet'; } ?>
            <?= e($parts === [] ? 'No section is built yet.' : implode(' · ', $parts)) ?></div>
        <div class="mt-1"><?= hx_link('/bases/' . $id . '/vocabulary', 'The vocabulary', 'fw-semibold', 'id="guidelines-vocabulary-link"') ?></div>
    </div></div>
    <?php if ($built['stale'] > 0): ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" id="guidelines-needs-rebuild">
            <span class="me-auto"><?= (int) $built['stale'] ?> section<?= $built['stale'] === 1 ? '' : 's' ?> need a rebuild: the guidelines that shape the graph changed since they were built.</span>
            <?php if (base_tab_built('/bases/builds.php')): ?><?= hx_link('/bases/' . $id . '/builds', 'Builds', 'btn btn-warning btn-touch', 'id="guidelines-builds-link"') ?><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($warn !== []): ?><div class="alert alert-warning fs-12" id="guidelines-warnings"><?php foreach ($warn as $w): ?><div><?= e($w) ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php foreach ($groups as $kind => $rows): ?><?= view('guidelines/partials/kind-group.php', ['kind' => $kind, 'rows' => $rows, 'base_id' => $id, 'may' => $may]) ?><?php endforeach; ?>
</div>
