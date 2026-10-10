<?php /** The base's log (screen `questions`). Data: b, rows, page, more, filters, areas, tabs, tz */
$id = (int) $b['base_id'];
$qs = static fn (array $p): string => '/bases/' . $id . '/questions?' . http_build_query(array_filter($p + $filters, static fn ($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986); ?>
<?= view('shared/header.php', ['id' => 'questions', 'title' => 'Questions', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Questions', null]], 'back' => back_link(),
    'action' => hx_link('/bases/' . $id . '/gaps', '<i class="feather-help-circle me-1"></i>Gaps', 'btn btn-light btn-touch', 'id="questions-gaps-link"')]) ?>
<div class="main-content" id="questions-content">
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'questions']) ?>
    <form method="get" action="/bases/<?= $id ?>/questions" class="card mb-3" id="questions-filters"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="questions-q">Words</label><input type="search" name="q" id="questions-q" class="form-control btn-touch" maxlength="200" value="<?= e((string) $filters['q']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="questions-status">Status</label><select name="status" id="questions-status" class="form-select btn-touch"><option value="">Any</option>
            <?php foreach (QUESTION_STATUSES as $k => [$w]): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="questions-door">Door</label><select name="door" id="questions-door" class="form-select btn-touch"><option value="">Any</option>
            <?php foreach (['web' => 'the window', 'api' => 'a key', 'mcp' => 'MCP', 'agent' => 'an agent', 'share' => 'an application'] as $k => $w): ?><option value="<?= e($k) ?>" <?= $filters['door'] === $k ? 'selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="questions-area">Area</label><select name="area" id="questions-area" class="form-select btn-touch"><option value="">Any</option>
            <?php foreach ($areas as $a): ?><option value="<?= (int) $a['focus_area_id'] ?>" <?= (string) $filters['area'] === (string) $a['focus_area_id'] ? 'selected' : '' ?>><?= e((string) $a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="questions-apply">Apply</button></div>
    </div></form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="questions-empty">No question matches.</div></div><?php else: ?>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table table-hover mb-0" id="questions-table">
            <thead><tr><th>When</th><th>Asker</th><th>Area</th><th>Question</th><th>Status</th><th>Verified</th><th>Rated</th><th></th></tr></thead>
            <tbody><?php foreach ($rows as $r): ?><?= view('questions/partials/question-row.php', ['r' => $r, 'tz' => $tz]) ?><?php endforeach; ?></tbody></table></div></div>
        <div class="d-md-none" id="questions-cards"><?php foreach ($rows as $r): ?><?= view('questions/partials/question-row.php', ['r' => $r, 'tz' => $tz, 'card' => true]) ?><?php endforeach; ?></div>
        <div class="d-flex gap-2 mt-3">
            <?php if ($page > 1): ?><?= hx_link($qs(['page' => $page - 1]), 'Newer', 'btn btn-light btn-touch', 'id="questions-newer"') ?><?php endif; ?>
            <?php if ($more): ?><?= hx_link($qs(['page' => $page + 1]), 'Older', 'btn btn-light btn-touch ms-auto', 'id="questions-older"') ?><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
