<?php /** A base's builds (screen `builds`). Data: b, est, all, calls, callsAll, blocker, may, builds, running, tabs, tz, notice */
$id = (int) $b['base_id']; ?>
<?= view('shared/header.php', ['id' => 'builds', 'title' => 'Builds', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Builds', null]], 'back' => back_link()]) ?>
<div class="main-content" id="builds-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'builds']) ?>
    <?php if ($running !== null): ?>
        <div class="card mb-3" id="build-running"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Building now</h5><?= hx_link('/builds/' . (int) $running['build_id'], 'Open', 'btn btn-light btn-sm btn-touch', 'id="build-running-link"') ?></div>
            <div class="card-body"><?= view('builds/partials/progress.php', ['p' => $running, 'poll' => true, 'tz' => $tz]) ?></div></div>
    <?php endif; ?>
    <?php if ($est !== null): ?><?= view('builds/partials/estimate.php', ['b' => $b, 'est' => $est, 'calls' => $calls, 'blocker' => $blocker, 'may' => $may]) ?><?php endif; ?>
    <?php if ($may['run'] && $all !== null && (int) $all['sections'] > 0): ?>
        <details class="card mb-3" id="build-rebuild-all-card"><summary class="card-body fw-semibold btn-touch d-flex align-items-center">Rebuild everything</summary>
            <form method="post" action="/builds/rebuild-all.php" hx-post="/builds/rebuild-all.php" hx-target="#flash" hx-confirm="Rebuild all <?= (int) $all['sections'] ?> sections — <?= number_format((int) $all['characters']) ?> characters, about <?= (int) $callsAll ?> engine calls?" class="card-body pt-0" id="build-rebuild-all-form">
                <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>"><input type="hidden" name="confirm" value="1">
                <div class="fs-12 text-muted mb-2">Every section is forgotten in the engine and extracted again: <?= (int) $all['sections'] ?> sections, <?= number_format((int) $all['characters']) ?> characters, about <?= (int) $callsAll ?> engine calls<?= empty($all['within_limit']) ? ' — more than this month\'s limit leaves' : '' ?>.</div>
                <button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="build-rebuild-all" <?= $blocker !== null && !str_starts_with($blocker, 'Nothing to build') ? 'disabled' : '' ?>>Rebuild everything</button>
            </form>
        </details>
    <?php endif; ?>
    <div class="card"><div class="card-header"><h5 class="card-title mb-0">Builds</h5></div><div class="table-responsive">
        <table class="table table-hover mb-0" id="builds-table">
            <thead><tr><th>Started</th><th>Kind</th><th>By</th><th>Status</th><th>Done · failed · total</th><th>Characters</th><th>Took</th><th>Note</th></tr></thead>
            <tbody>
                <?php if ($builds === []): ?><tr><td colspan="8" class="fs-12 text-muted" id="builds-empty">No build yet.</td></tr><?php endif; ?>
                <?php foreach ($builds as $x): ?><?= view('builds/partials/build-row.php', ['x' => $x, 'tz' => $tz]) ?><?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>
