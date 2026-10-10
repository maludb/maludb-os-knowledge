<?php /** Mint or change a key (screens `key-add`, `key-edit`). Data: k (the key row, or null), b, areas, defaults, may_limits */
$bid = (int) $b['base_id']; $kid = $k['key_id'] ?? null; $screen = $k === null ? 'key-add' : 'key-edit';
$back = ['/bases/' . $bid . '/keys', 'the keys']; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $k === null ? 'Mint a key' : 'Change ' . $k['label'], 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Keys', '/bases/' . $bid . '/keys'], [$k === null ? 'Mint' : $k['label'], null]],
    'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="<?= $k === null ? '/keys/mint.php' : '/keys/save.php' ?>" hx-post="<?= $k === null ? '/keys/mint.php' : '/keys/save.php' ?>" hx-target="#flash" id="key-form">
        <?= csrf_field() ?>
        <?php if ($k === null): ?><input type="hidden" name="base" value="<?= $bid ?>"><?php else: ?><input type="hidden" name="api_key" value="<?= (int) $kid ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="key-form-field-label">Label — what will use this key</label>
            <input type="text" name="label" id="key-form-field-label" class="form-control btn-touch mb-3" maxlength="80" required placeholder="Support site" value="<?= e($k['label'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="key-form-field-focus_area">Area</label>
            <select name="focus_area" id="key-form-field-focus_area" class="form-select btn-touch mb-1">
                <option value="">Whole base</option>
                <?php foreach ($areas as $a): ?><option value="<?= (int) $a['focus_area_id'] ?>" <?= $k !== null && (string) ($k['focus_area_id'] ?? '') === (string) $a['focus_area_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
            </select>
            <div class="fs-11 text-muted mb-3">A key bound to an area asks that area only.</div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="key-form-field-rate_per_minute">Calls per minute</label>
                    <input type="number" name="rate_per_minute" id="key-form-field-rate_per_minute" class="form-control btn-touch" min="1" max="100000" placeholder="<?= (int) $defaults['per_minute'] ?>" value="<?= e((string) ($k['rate_per_minute'] ?? '')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="key-form-field-rate_per_day">Calls per day</label>
                    <input type="number" name="rate_per_day" id="key-form-field-rate_per_day" class="form-control btn-touch" min="1" max="10000000" placeholder="<?= (int) $defaults['per_day'] ?>" value="<?= e((string) ($k['rate_per_day'] ?? '')) ?>"></div>
            </div>
            <div class="fs-11 text-muted mt-1">Blank is the default (<?= number_format((int) $defaults['per_minute']) ?> a minute, <?= number_format((int) $defaults['per_day']) ?> a day).<?= $may_limits ? '' : ' A limit above the default needs an admin.' ?></div>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="key-form-save-btn"><?= $k === null ? 'Mint the key' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="key-form-cancel"') ?>
    </form>
</div>
