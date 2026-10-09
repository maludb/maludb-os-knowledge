<?php /** A guideline's versions (screen `guideline-view`). Data: g, b, versions, builtVersionNo, may, timeline, tz */
$gid = (int) $g['guideline_id']; $bid = (int) $b['base_id'];
$title = $g['title'] !== '' ? $g['title'] : GUIDELINE_KINDS[$g['kind']][0] . ' guideline'; ?>
<?= view('shared/header.php', ['id' => 'guideline-view', 'title' => $title, 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Guidelines', '/bases/' . $bid . '/guidelines'], [$title, null]],
    'back' => back_link() ?? ['/bases/' . $bid . '/guidelines', 'the guidelines']]) ?>
<div class="main-content" id="guideline-view-content">
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap gap-1 mb-2"><?= chip([GUIDELINE_KINDS[$g['kind']][0], 'info']) ?><?= chip(guideline_origin_chip($g)) ?><?= chip(['v' . (int) $g['version_no'], 'light']) ?><?php if (empty($g['is_active'])): ?><?= chip(['inactive', 'dark']) ?><?php endif; ?></div>
        <div class="kn-prewrap fs-13" id="guideline-body"><?= e($g['body']) ?></div>
        <?php if ($may['manage'] && $b['archived_at'] === null): ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <?= hx_link('/guidelines/' . $gid . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="guideline-view-edit"') ?>
                <form method="post" action="/guidelines/delete.php" hx-post="/guidelines/delete.php" hx-target="#flash" hx-confirm="Delete this guideline?" class="m-0" id="guideline-delete-form">
                    <?= csrf_field() ?><input type="hidden" name="guideline" value="<?= $gid ?>"><button type="submit" class="btn btn-light btn-touch text-danger" id="guideline-view-delete">Delete</button>
                </form>
            </div>
        <?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Versions</h5></div>
        <div class="table-responsive"><table class="table mb-0" id="guideline-versions">
            <thead><tr><th>Version</th><th>Saved by</th><th>When</th><th>Base after</th><th>The words</th></tr></thead>
            <tbody>
            <?php foreach ($versions as $v): $vn = (int) $v['version_no']; ?>
                <tr id="guideline-version-<?= $vn ?>"<?= $vn === $builtVersionNo ? ' class="table-success"' : '' ?>>
                    <td class="text-nowrap">v<?= $vn ?><?= $vn === $builtVersionNo ? ' ' . chip(['the graph is built under this', 'success']) : '' ?><?= empty($v['is_active']) ? ' ' . chip(['inactive', 'dark']) : '' ?></td>
                    <td class="fs-12 text-nowrap"><?= e((string) ($v['saved_by_name'] ?? '')) ?></td>
                    <td class="fs-12 text-nowrap"><?= e(format_ts($v['saved_at'], $tz)) ?></td>
                    <td class="fs-12">v<?= (int) $v['base_guideline_version'] ?></td>
                    <td><details><summary class="fs-12 btn-touch d-flex align-items-center">Show</summary><div class="kn-prewrap fs-12"><?= e($v['body']) ?></div></details></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'guideline']) ?>
</div>
