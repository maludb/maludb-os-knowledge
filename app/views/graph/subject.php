<?php /** One subject of the base (screen `subject-view`). Data: b, r (graph_subject), here, back */
$id = (int) $b['base_id']; $s = $r['subject']; $name = (string) $s['name']; $down = isset($r['errors']['engine']); ?>
<?= view('shared/header.php', ['id' => 'subject-view', 'title' => $name, 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Graph', '/bases/' . $id . '/graph'], [$name, null]],
    'back' => $back ?? ['/bases/' . $id . '/graph', 'the graph']]) ?>
<div class="main-content" id="subject-view-content">
    <div class="card mb-3" id="subject-head"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <h5 class="mb-0 text-break" id="subject-name"><?= e($name) ?></h5>
            <?php if (!empty($s['type'])): ?><span class="badge bg-soft-secondary text-secondary" id="subject-type"><?= e(str_replace('_', ' ', (string) $s['type'])) ?></span><?php endif; ?>
        </div>
        <?php if ($down): ?>
            <?= view('graph/partials/engine-error.php', ['region' => 'subject', 'code' => $r['errors']['engine']]) ?>
        <?php else: ?>
            <?php if (($s['description'] ?? '') !== ''): ?><p class="fs-13 mb-2 kn-prewrap" id="subject-description"><?= e((string) $s['description']) ?></p><?php endif; ?>
            <?php if ($r['aliases'] !== []): ?>
                <div class="d-flex flex-wrap align-items-center gap-1 mb-2" id="subject-aliases"><span class="fs-12 text-muted me-1">Also called</span>
                    <?php foreach ($r['aliases'] as $a): ?><span class="badge bg-light text-dark border"><?= e($a) ?></span><?php endforeach; ?></div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?= hx_link(graph_ask_url($id, $name), '<i class="feather-message-circle me-1"></i>Ask about this', 'btn btn-primary btn-touch', 'id="subject-ask"') ?>
            </div>
            <form method="get" action="/bases/<?= $id ?>/path" hx-get="/bases/<?= $id ?>/path" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="true" class="d-flex flex-wrap gap-2" id="subject-path-form">
                <input type="hidden" name="from" value="<?= e($name) ?>">
                <label class="visually-hidden" for="subject-path-to">Another subject</label>
                <input type="text" name="to" id="subject-path-to" class="form-control btn-touch flex-grow-1" style="min-width:12rem" maxlength="300" required placeholder="Another subject" autocomplete="off">
                <button type="submit" class="btn btn-light btn-touch" id="subject-path-btn">Path to…</button>
            </form>
        <?php endif; ?>
    </div></div>
<?php if (!$down): ?>
    <?= view('graph/partials/statements.php', ['id' => $id, 'r' => $r, 'here' => $here]) ?>
    <?= view('graph/partials/neighbours.php', ['id' => $id, 'r' => $r, 'here' => $here]) ?>
    <?= view('graph/partials/sections-behind.php', ['id' => $id, 'r' => $r, 'here' => $here]) ?>
    <?php if ($r['svg_nodes'] !== []): ?>
        <div class="card mb-3 d-none d-lg-block" id="subject-svg-card"><div class="card-body">
            <h6 class="mb-2">Its neighbourhood</h6>
            <?= graph_svg(['name' => $name], $r['svg_nodes']) ?>
            <div class="fs-11 text-muted mt-1"><i class="feather-circle text-primary me-1"></i>subject <i class="feather-file-text text-secondary ms-2 me-1"></i>section <i class="feather-message-circle text-info ms-2 me-1"></i>statement</div>
        </div></div>
    <?php endif; ?>
<?php endif; ?>
</div>
