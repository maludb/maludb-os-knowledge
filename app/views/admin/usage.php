<?php /** Engine usage (screen `admin-usage`; costs.read). Data: u (usage_by_base: month, rows, totals), months, base, base_label, warn, statement */
$rows = $u['rows']; $t = $u['totals']; ?>
<?= view('shared/header.php', ['id' => 'admin-usage', 'title' => 'Usage', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Usage', null]], 'back' => back_link() ?? ['/admin/', 'Admin']]) ?>
<div class="main-content" id="admin-usage-content">
    <form method="get" action="/admin/usage" class="row g-2 align-items-end mb-3" id="usage-filter">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="usage-month">Month</label>
            <select name="month" id="usage-month" class="form-select btn-touch"><?php foreach ($months as $m): ?><option value="<?= e($m) ?>" <?= $m === $u['month'] ? 'selected' : '' ?>><?= e(date('F Y', strtotime($m . '-01'))) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-5"><label class="form-label fs-12 text-muted" id="usage-base-label">Base</label>
            <?= picker_field(['id' => 'usage-base', 'name' => 'base', 'source' => 'base', 'value' => $base, 'label' => $base_label, 'labelledby' => 'usage-base-label', 'placeholder' => 'Every base', 'title' => 'A knowledge base']) ?></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="usage-go">Show</button></div>
    </form>
    <div class="fs-12 text-muted mb-3" id="usage-note">Dollars are on the AI statement in the OS<?php if ($statement !== null): ?> — <a href="<?= e($statement) ?>" id="usage-statement-link">open the statement</a><?php endif; ?>. This page counts what Knowledge sent the engine.</div>
    <div class="row g-2 mb-3" id="usage-totals">
        <?php foreach (['extract' => 'Reading text', 'answer' => 'Answers', 'embed' => 'Embeddings'] as $k => $label): ?>
            <div class="col-12 col-md-4"><div class="card mb-0"><div class="card-body py-2"><div class="fs-12 text-muted"><?= e($label) ?></div>
                <div class="fw-semibold" id="usage-total-<?= e($k) ?>"><?= number_format($t[$k]['calls']) ?> calls · <?= number_format($t[$k]['input_tokens'] + $t[$k]['output_tokens']) ?> tokens<?= $k === 'extract' ? ' · ' . number_format($t[$k]['chars']) . ' characters' : '' ?></div></div></div></div>
        <?php endforeach; ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="usage-none">No base to count.</div></div>
    <?php else: ?>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="usage-table">
            <thead><tr><th>Base</th><th class="text-end">Text read</th><th>Against its limit</th><th class="text-end">Read calls</th><th class="text-end">Answer calls</th><th class="text-end">Embed calls</th><th class="text-end">Tokens in</th><th class="text-end">Tokens out</th></tr></thead>
            <tbody><?php foreach ($rows as $r): ?><?= view('admin/partials/usage-row.php', ['r' => $r, 'warn' => $warn]) ?><?php endforeach; ?></tbody></table></div></div>
        <div class="d-md-none" id="usage-cards"><?php foreach ($rows as $r): ?><?= view('admin/partials/usage-row.php', ['r' => $r, 'warn' => $warn, 'card' => true]) ?><?php endforeach; ?></div>
    <?php endif; ?>
</div>
