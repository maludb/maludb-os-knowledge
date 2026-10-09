<?php /** A focus area (screen `area-view`). Data: a, b, sources, may, before, timeline, tz, notice */
$aid = (int) $a['focus_area_id']; $bid = (int) $b['base_id']; ?>
<?= view('shared/header.php', ['id' => 'area-view', 'title' => $a['name'], 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Areas', '/bases/' . $bid . '/areas'], [$a['name'], null]],
    'back' => back_link() ?? ['/bases/' . $bid . '/areas', 'the areas'],
    'action' => $may['manage'] && $a['status'] !== 'proposed' ? hx_link('/areas/' . $aid . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="area-view-edit"') : '']) ?>
<div class="main-content" id="area-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap gap-1 mb-2"><?= chip(area_status_chip($a['status'])) ?></div>
        <?php if ($a['description'] !== ''): ?><div class="kn-prewrap fs-13 mb-2" id="area-description"><?= e($a['description']) ?></div><?php endif; ?>
        <?php if ($a['seed_terms_list'] !== []): ?><div class="d-flex flex-wrap gap-1 mb-2" id="area-seed-terms"><?php foreach ($a['seed_terms_list'] as $t): ?><?= chip([$t, 'light']) ?><?php endforeach; ?></div><?php endif; ?>
        <?php if ($may['curate']): ?>
            <div class="fs-12 d-flex flex-wrap align-items-center gap-2" id="area-engine-link">
                <?php if ($a['maludb_project_id'] !== null): ?>
                    <?= chip(['linked', 'success']) ?> <span class="text-muted">project #<?= (int) $a['maludb_project_id'] ?></span>
                <?php elseif ($a['status'] === 'active'): ?>
                    <?= chip(['not linked', 'warning']) ?>
                    <?php if ($may['manage']): ?><form method="post" action="/areas/link.php" hx-post="/areas/link.php" hx-target="#flash" class="m-0" id="area-link-form"><?= csrf_field() ?><input type="hidden" name="focus_area" value="<?= $aid ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="area-link-btn">Link now</button></form><?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div></div>
    <?php if ($a['example_questions_list'] !== []): ?>
        <div class="card mb-3" id="area-examples"><div class="card-header"><h5 class="card-title mb-0">Example questions</h5></div><div class="list-group list-group-flush">
            <?php foreach ($a['example_questions_list'] as $i => $q): ?>
                <div class="list-group-item d-flex flex-wrap align-items-center gap-2"><span class="me-auto fs-13"><?= e($q) ?></span>
                    <a class="btn btn-light btn-sm btn-touch" href="/ask?<?= e(http_build_query(['base' => $bid, 'area' => $aid, 'q' => $q])) ?>" id="area-ask-<?= $i ?>">Ask this</a></div>
            <?php endforeach; ?>
        </div></div>
    <?php endif; ?>
    <div class="card mb-3" id="area-sources"><div class="card-header"><h5 class="card-title mb-0">Sources in this area (<?= count($sources) ?>)</h5></div>
        <div class="list-group list-group-flush">
            <?php if ($sources === []): ?><div class="list-group-item fs-12 text-muted" id="area-sources-empty">No source yet.</div><?php endif; ?>
            <?php foreach ($sources as $s): $sid = (int) $s['source_id']; ?>
                <div class="list-group-item d-flex flex-wrap align-items-center gap-2" id="area-source-<?= $sid ?>">
                    <span class="me-auto min-w-0 text-truncate"><?= hx_link('/sources/' . $sid, e($s['title'])) ?></span>
                    <?php if ($may['manage']): ?>
                        <form method="post" action="/areas/assign.php" hx-post="/areas/assign.php" hx-target="#flash" class="m-0" id="area-source-<?= $sid ?>-remove-form">
                            <?= csrf_field() ?><input type="hidden" name="focus_area" value="<?= $aid ?>"><input type="hidden" name="source" value="<?= $sid ?>"><input type="hidden" name="assigned" value="no">
                            <button type="submit" class="btn btn-light btn-sm btn-touch text-danger" id="area-source-<?= $sid ?>-remove">Remove</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($may['manage'] && $a['status'] === 'active'): ?>
            <form method="post" action="/areas/assign.php" hx-post="/areas/assign.php" hx-target="#flash" class="card-body border-top" id="area-add-source-form">
                <?= csrf_field() ?><input type="hidden" name="focus_area" value="<?= $aid ?>"><input type="hidden" name="assigned" value="yes">
                <label class="form-label fs-12 text-muted" id="area-add-source-label">Add a source</label>
                <div class="mb-2"><?= picker_field(['id' => 'area-add-source-field', 'name' => 'source', 'source' => 'source', 'params' => ['area' => $aid], 'labelledby' => 'area-add-source-label', 'title' => 'A source of this base', 'placeholder' => 'Choose a source']) ?></div>
                <button type="submit" class="btn btn-primary btn-touch w-100" id="area-add-source-btn">Add</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($before > 0): ?><div class="alert alert-warning fs-12" id="area-built-before"><?= (int) $before ?> section<?= $before === 1 ? '' : 's' ?> of these sources were built before they joined — rebuild to tag them with this area.</div><?php endif; ?>
    <?php if ($may['manage']): ?>
        <form method="post" action="/areas/delete.php" hx-post="/areas/delete.php" hx-target="#flash" hx-confirm="Delete the area <?= e($a['name']) ?>? Its sources stay in the base." class="mb-3" id="area-delete-form">
            <?= csrf_field() ?><input type="hidden" name="focus_area" value="<?= $aid ?>"><button type="submit" class="btn btn-light btn-touch text-danger w-100" id="area-delete-btn">Delete the area</button>
        </form>
    <?php endif; ?>
    <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'area']) ?>
</div>
