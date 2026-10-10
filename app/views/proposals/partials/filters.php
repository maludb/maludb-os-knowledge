<?php /** The status tabs and the kind filter of the proposals screens. Data: here (the screen's path), f (status, kind, base?), counts (per status, a base's own screen only), base_picker (bool), base_label?, extra (query keys kept) */
$extra = $extra ?? [];
$q = static fn (array $over): string => '?' . http_build_query(array_filter($over + $extra + ['status' => $f['status'], 'kind' => $f['kind']], static fn ($v): bool => $v !== '' && $v !== null)); ?>
<ul class="nav nav-pills mb-3 flex-nowrap" id="proposals-status-tabs">
    <?php foreach (PROPOSAL_STATUSES as $s): ?>
        <li class="nav-item"><?= hx_link($here . $q(['status' => $s]), e(ucfirst($s)) . (isset($counts[$s]) ? ' <span class="badge bg-soft-secondary text-secondary ms-1">' . (int) $counts[$s] . '</span>' : ''),
            'nav-link btn-touch' . ($f['status'] === $s ? ' active' : ''), 'id="proposals-tab-' . $s . '"' . ($f['status'] === $s ? ' aria-current="true"' : '')) ?></li>
    <?php endforeach; ?>
</ul>
<form method="get" action="<?= e($here) ?>" class="row g-2 align-items-end mb-3" id="proposals-filter">
    <input type="hidden" name="status" value="<?= e($f['status']) ?>">
    <?php if ($base_picker): ?>
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" id="proposals-filter-base-label">Base</label>
            <?= picker_field(['id' => 'proposals-filter-base', 'name' => 'base', 'source' => 'base', 'value' => $f['base'] ?? null, 'label' => $base_label ?? null, 'labelledby' => 'proposals-filter-base-label', 'placeholder' => 'Every base', 'title' => 'A knowledge base']) ?></div>
    <?php endif; ?>
    <div class="col-8 col-md-4"><label class="form-label fs-12 text-muted" for="proposals-filter-kind">Kind</label>
        <select name="kind" id="proposals-filter-kind" class="form-select btn-touch"><option value="">Every kind</option>
            <?php foreach (PROPOSAL_KIND_CHIPS as $k => [$words]): ?><option value="<?= e($k) ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= e($words) ?></option><?php endforeach; ?></select></div>
    <div class="col-4 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="proposals-filter-go">Show</button></div>
</form>
