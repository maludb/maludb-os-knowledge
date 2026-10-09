<?php /** Add or change a guideline, with Preview (screens `guideline-add`, `guideline-edit`). Data: g, b, draft, sections, section, preview, stale */
$bid = (int) $b['base_id']; $gid = $g['guideline_id'] ?? null; $screen = $g === null ? 'guideline-add' : 'guideline-edit';
$back = ['/bases/' . $bid . '/guidelines', 'the guidelines']; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $g === null ? 'Add a guideline' : 'Change a guideline', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Guidelines', '/bases/' . $bid . '/guidelines'], [$g === null ? 'New' : ($g['title'] !== '' ? $g['title'] : 'Guideline'), null]],
    'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <div class="row g-3">
        <div class="col-12 col-xl-8 order-1">
            <form method="post" action="/guidelines/save.php" hx-post="/guidelines/save.php" hx-target="#flash" id="guideline-form">
                <?= csrf_field() ?><input type="hidden" name="base" value="<?= $bid ?>"><?php if ($gid !== null): ?><input type="hidden" name="guideline" value="<?= (int) $gid ?>"><?php endif; ?>
                <div class="card mb-3"><div class="card-body">
                    <div class="row g-2">
                        <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="guideline-form-field-kind">Kind</label>
                            <select name="kind" id="guideline-form-field-kind" class="form-select btn-touch"><?php foreach (GUIDELINE_KINDS as $k => [$label]): ?><option value="<?= e($k) ?>" <?= $draft['kind'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="guideline-form-field-title">Title</label>
                            <input type="text" name="title" id="guideline-form-field-title" class="form-control btn-touch" maxlength="200" value="<?= e($draft['title']) ?>"></div>
                    </div>
                    <label class="form-label fs-12 text-muted mt-2" for="guideline-form-field-body">The guideline</label>
                    <textarea name="body" id="guideline-form-field-body" class="form-control" rows="10" maxlength="20000" required><?= e($draft['body']) ?></textarea>
                    <div class="row g-2 mt-1">
                        <div class="col-6"><input type="hidden" name="is_active" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="guideline-form-field-is_active"><input type="checkbox" class="form-check-input mt-0" name="is_active" value="yes" id="guideline-form-field-is_active" <?= $draft['is_active'] ? 'checked' : '' ?>>Active</label></div>
                        <div class="col-6"><label class="visually-hidden" for="guideline-form-field-position">Position</label><input type="number" name="position" id="guideline-form-field-position" class="form-control btn-touch" min="0" max="100000" placeholder="Position" value="<?= $draft['position'] !== null ? (int) $draft['position'] : '' ?>"></div>
                    </div>
                </div></div>
                <div class="card mb-3"><div class="card-body">
                    <label class="form-label fs-12 text-muted" for="preview-section">Preview on a section</label>
                    <select name="section" id="preview-section" class="form-select btn-touch mb-2">
                        <?php if ($sections === []): ?><option value="">(no section yet — add documents)</option><?php endif; ?>
                        <?php foreach ($sections as $x): ?><option value="<?= (int) $x['section_id'] ?>" <?= (int) $section === (int) $x['section_id'] ? 'selected' : '' ?>><?= e(mb_substr($x['heading_path'], 0, 120)) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" formaction="/guidelines/preview.php" hx-post="/guidelines/preview.php" hx-target="#preview" hx-swap="innerHTML" class="btn btn-light btn-touch w-100" id="preview-btn"><i class="feather-eye me-1"></i>Preview</button>
                </div></div>
                <div id="preview"><?= view('guidelines/partials/preview.php', ['p' => $preview]) ?></div>
                <?php if (in_array($draft['kind'], GUIDELINE_SHAPING, true) && $stale > 0): ?><div class="fs-12 text-muted mb-2" id="guideline-form-shaping">Saving marks <?= (int) $stale ?> section<?= $stale === 1 ? '' : 's' ?> for a rebuild.</div><?php endif; ?>
                <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="guideline-form-save-btn">Save</button>
                <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="guideline-form-cancel"') ?>
            </form>
        </div>
        <div class="col-12 col-xl-4 order-2"><?= view('guidelines/partials/examples.php', ['kind' => $draft['kind']]) ?></div>
    </div>
</div>
