<?php /** Add or change a focus area (screens `area-add`, `area-edit`). Data: a, b, terms */
$bid = (int) $b['base_id']; $aid = $a['focus_area_id'] ?? null; $screen = $a === null ? 'area-add' : 'area-edit';
$back = $a === null ? ['/bases/' . $bid . '/areas', 'the areas'] : ['/areas/' . (int) $aid, $a['name']]; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $a === null ? 'New focus area' : 'Change ' . $a['name'], 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Areas', '/bases/' . $bid . '/areas'], [$a === null ? 'New' : $a['name'], null]],
    'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/areas/save.php" hx-post="/areas/save.php" hx-target="#flash" id="area-form">
        <?= csrf_field() ?><input type="hidden" name="base" value="<?= $bid ?>"><?php if ($aid !== null): ?><input type="hidden" name="focus_area" value="<?= (int) $aid ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="area-form-field-name">Name</label>
            <input type="text" name="name" id="area-form-field-name" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($a['name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="area-form-field-description">Description — say what belongs here and what does not</label>
            <textarea name="description" id="area-form-field-description" class="form-control mb-3" rows="4"><?= e($a['description'] ?? '') ?></textarea>
            <label class="form-label fs-12 text-muted" for="area-form-field-seed_terms">Seed terms — one a line (up to 30)</label>
            <textarea name="seed_terms" id="area-form-field-seed_terms" class="form-control mb-1" rows="4" list="area-form-terms"><?= e(implode("\n", $a['seed_terms_list'] ?? [])) ?></textarea>
            <?php if ($terms !== []): ?><div class="fs-11 text-muted mb-3">The base's terms: <?= e(implode(', ', array_slice($terms, 0, 30))) ?></div><?php else: ?><div class="mb-3"></div><?php endif; ?>
            <label class="form-label fs-12 text-muted" for="area-form-field-example_questions">Example questions — one a line (up to 10)</label>
            <textarea name="example_questions" id="area-form-field-example_questions" class="form-control mb-3" rows="3"><?= e(implode("\n", $a['example_questions_list'] ?? [])) ?></textarea>
            <div class="row g-2">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="area-form-field-position">Position</label><input type="number" name="position" id="area-form-field-position" class="form-control btn-touch" min="0" max="100000" value="<?= (int) ($a['position'] ?? 0) ?>"></div>
                <?php if ($a !== null): ?>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="area-form-field-status">Status</label><select name="status" id="area-form-field-status" class="form-select btn-touch">
                    <option value="active" <?= $a['status'] === 'active' ? 'selected' : '' ?>>active</option><option value="archived" <?= $a['status'] === 'archived' ? 'selected' : '' ?>>archived</option></select></div>
                <?php endif; ?>
            </div>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="area-form-save-btn">Save</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="area-form-cancel"') ?>
    </form>
</div>
