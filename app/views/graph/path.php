<?php /** How two subjects are related (screen `path`). Data: b, p (graph_path), asked, suggest, tabs, back */
$id = (int) $b['base_id']; $here = graph_here(); ?>
<?= view('shared/header.php', ['id' => 'path', 'title' => 'Path', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Graph', '/bases/' . $id . '/graph'], ['Path', null]],
    'back' => $back ?? ['/bases/' . $id . '/graph', 'the graph']]) ?>
<div class="main-content" id="path-content">
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'graph']) ?>
    <div class="card mb-3" id="path-form-card"><div class="card-body">
        <form method="get" action="/bases/<?= $id ?>/path" hx-get="/bases/<?= $id ?>/path" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" id="path-form">
            <label class="form-label fs-12 text-muted" for="path-from">From</label>
            <input type="text" name="from" id="path-from" class="form-control btn-touch mb-3" list="path-suggestions" maxlength="300" required value="<?= e($p['from']) ?>" autocomplete="off">
            <label class="form-label fs-12 text-muted" for="path-to">To</label>
            <input type="text" name="to" id="path-to" class="form-control btn-touch mb-3" list="path-suggestions" maxlength="300" required value="<?= e($p['to']) ?>" autocomplete="off">
            <datalist id="path-suggestions"><?php foreach ($suggest as $sn): ?><option value="<?= e($sn) ?>"><?php endforeach; ?></datalist>
            <div class="fs-11 text-muted mb-2">Names are matched exactly; the suggestions are this base's most connected subjects.</div>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="path-btn">Find the path</button>
        </form>
    </div></div>
<?php if ($asked): ?>
    <div class="card mb-3" id="path-result"><div class="card-body">
        <?php if ($p['error'] !== null): ?>
            <?= view('graph/partials/engine-error.php', ['region' => 'path', 'code' => $p['error']]) ?>
        <?php elseif ($p['path'] === null): ?>
            <div class="text-muted" id="<?= $p['withheld'] ? 'path-withheld' : 'path-none' ?>">There is no path between these inside this base.</div>
        <?php else: ?>
            <ol class="list-unstyled mb-0" id="path-chain">
                <?php foreach ($p['path'] as $i => $n): [$icon, $colour] = graph_kind($n['kind']);
                    $url = $n['kind'] === 'subject' ? graph_subject_url($id, $n['name']) : ((int) $n['id'] > 0 ? '/sections/' . (int) $n['id'] : null); ?>
                    <li id="path-step-<?= $i + 1 ?>">
                        <?php $row = '<i class="' . $icon . ' text-' . $colour . '"></i><span class="text-break">' . e($n['name']) . '</span><span class="badge bg-light text-dark border ms-auto">' . e($n['kind']) . '</span>'; ?>
                        <?php if ($url !== null): ?><?= hx_link(with_back($url, $here), $row, 'd-flex align-items-center gap-2 border rounded px-3 kn-row-link text-reset', 'id="path-node-' . ($i + 1) . '"') ?>
                        <?php else: ?><div class="d-flex align-items-center gap-2 border rounded px-3 kn-row-link" id="path-node-<?= $i + 1 ?>"><?= $row ?></div><?php endif; ?>
                        <?php if ($i < count($p['path']) - 1): ?><div class="text-center text-muted fs-12 py-1" id="path-link-<?= $i + 1 ?>">↓ <?= e($n['verb'] ?? 'linked to') ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div></div>
<?php endif; ?>
</div>
