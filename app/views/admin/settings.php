<?php /** The application's settings, limits and retention — three forms, each its own action (screen `admin-settings`). Data: s (mcp_settings), may (settings, limits, retention), notice */ ?>
<?= view('shared/header.php', ['id' => 'admin-settings', 'title' => 'Settings', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], ['Settings', null]],
    'back' => back_link() ?? ['/bases', 'Bases'], 'action' => hx_link('/admin/templates', '<i class="feather-layers me-1"></i>Templates', 'btn btn-light btn-touch', 'id="admin-settings-templates-link"')]) ?>
<div class="main-content" id="admin-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php foreach (KN_SETTINGS_FORMS as $form => [$title, $right, $action, $endpoint]): $editable = $may[$form]; ?>
    <form method="post" action="<?= e($endpoint) ?>" hx-post="<?= e($endpoint) ?>" hx-target="#flash" class="card mb-3" id="admin-settings-<?= e($form) ?>">
        <div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0"><?= e($title) ?></h5><?php if (!$editable): ?><span class="fs-11 text-muted">read-only — <?= e(RIGHT_WORDS[$right] ?? $right) ?> is another role's</span><?php endif; ?></div>
        <div class="card-body">
            <?= csrf_field() ?>
            <fieldset <?= $editable ? '' : 'disabled' ?>>
            <div class="row g-3">
            <?php foreach (kn_settings_fields($form) as $col => $f): $name = $f['field'] ?? $col; $fid = 'settings-field-' . $name; $value = kn_setting_display($col, $s[$col] ?? null); ?>
                <div class="col-12 col-md-6">
                <?php if ($f['type'] === 'bool'): ?>
                    <input type="hidden" name="<?= e($name) ?>" value="no">
                    <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="<?= e($fid) ?>"><input type="checkbox" class="form-check-input mt-0" name="<?= e($name) ?>" value="yes" id="<?= e($fid) ?>" <?= $value === 'yes' ? 'checked' : '' ?>><?= e($f['label']) ?></label>
                <?php else: ?>
                    <label class="form-label fs-12 text-muted" for="<?= e($fid) ?>"><?= e($f['label']) ?></label>
                    <?php if ($f['type'] === 'choice'): ?>
                        <select name="<?= e($name) ?>" id="<?= e($fid) ?>" class="form-select btn-touch"><?php foreach ($f['choices'] as $k => $words): ?><option value="<?= (int) $k ?>" <?= (string) $k === $value ? 'selected' : '' ?>><?= e($words) ?></option><?php endforeach; ?></select>
                    <?php elseif ($f['type'] === 'timezone'): ?>
                        <input type="text" name="<?= e($name) ?>" id="<?= e($fid) ?>" class="form-control btn-touch" list="settings-timezones" value="<?= e($value) ?>" autocapitalize="none" spellcheck="false">
                        <datalist id="settings-timezones"><?php foreach (timezone_identifiers_list() as $tz): ?><option value="<?= e($tz) ?>"><?php endforeach; ?></datalist>
                    <?php elseif ($f['type'] === 'text'): ?>
                        <input type="text" name="<?= e($name) ?>" id="<?= e($fid) ?>" class="form-control btn-touch" maxlength="<?= (int) $f['max'] ?>" value="<?= e($value) ?>">
                    <?php else: ?>
                        <input type="number" name="<?= e($name) ?>" id="<?= e($fid) ?>" class="form-control btn-touch" min="<?= e((string) $f['min']) ?>" max="<?= e((string) $f['max']) ?>" step="<?= e($f['step'] ?? (isset($f['scale']) ? 'any' : '1')) ?>" value="<?= e($value) ?>">
                    <?php endif; ?>
                <?php endif; ?>
                    <div class="fs-11 text-muted mt-1"><?= e(trim(($f['type'] === 'int' || $f['type'] === 'decimal' ? ucfirst(kn_setting_bounds($col)) . '. ' : '') . ($f['hint'] ?? ''))) ?></div>
                </div>
            <?php endforeach; ?>
            </div>
            </fieldset>
            <?php if ($editable): ?><button type="submit" class="btn btn-primary btn-touch w-100 mt-3" id="admin-settings-<?= e($form) ?>-save">Save the <?= e(strtolower($title)) ?></button><?php endif; ?>
        </div>
    </form>
    <?php endforeach; ?>
</div>
