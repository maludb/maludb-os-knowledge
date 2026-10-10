<?php /** Add or change a term (screens `term-add`, `term-edit`). Data: t, b, types, prefill (the graph's Merge: canonical_name, aliases_list) */
$bid = (int) $b['base_id']; $tid = $t['term_id'] ?? null; $screen = $t === null ? 'term-add' : 'term-edit'; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $t === null ? 'New term' : 'Change ' . $t['canonical_name'], 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Vocabulary', '/bases/' . $bid . '/vocabulary'], [$t === null ? 'New' : $t['canonical_name'], null]],
    'back' => back_link() ?? ['/bases/' . $bid . '/vocabulary', 'the vocabulary']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/vocabulary/save.php" hx-post="/vocabulary/save.php" hx-target="#flash" id="term-form">
        <?= csrf_field() ?><input type="hidden" name="base" value="<?= $bid ?>"><?php if ($tid !== null): ?><input type="hidden" name="term" value="<?= (int) $tid ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="term-form-field-canonical_name">The term</label>
            <input type="text" name="canonical_name" id="term-form-field-canonical_name" class="form-control btn-touch mb-3" maxlength="200" required value="<?= e($t['canonical_name'] ?? $prefill['canonical_name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="term-form-field-aliases">Other names — one a line, or separated by commas (up to 20)</label>
            <textarea name="aliases" id="term-form-field-aliases" class="form-control mb-3" rows="3"><?= e(implode("\n", $t['aliases_list'] ?? $prefill['aliases_list'] ?? [])) ?></textarea>
            <label class="form-label fs-12 text-muted" for="term-form-field-subject_type">Type</label>
            <input type="text" name="subject_type" id="term-form-field-subject_type" class="form-control btn-touch" list="term-form-types" maxlength="40" value="<?= e((string) ($t['subject_type'] ?? '')) ?>" autocapitalize="none" spellcheck="false">
            <datalist id="term-form-types"><?php foreach ($types as $ty): ?><option value="<?= e($ty) ?>"><?php endforeach; ?></datalist>
            <div class="fs-11 text-muted mt-1 mb-3">Lower-case letters and underscores — one of the list, or a new one.</div>
            <label class="form-label fs-12 text-muted" for="term-form-field-description">Description</label>
            <textarea name="description" id="term-form-field-description" class="form-control" rows="3" maxlength="2000"><?= e((string) ($t['description'] ?? '')) ?></textarea>
        </div></div>
        <div class="fs-12 text-muted mb-2">Saving a term marks the built sections for a rebuild.</div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="term-form-save-btn">Save</button>
        <?= hx_link('/bases/' . $bid . '/vocabulary', 'Cancel', 'btn btn-light btn-touch w-100', 'id="term-form-cancel"') ?>
    </form>
    <?php if ($tid !== null): ?>
        <form method="post" action="/vocabulary/delete.php" hx-post="/vocabulary/delete.php" hx-target="#flash" hx-confirm="Delete the term <?= e($t['canonical_name']) ?>?" class="mt-3" id="term-delete-form">
            <?= csrf_field() ?><input type="hidden" name="term" value="<?= (int) $tid ?>"><button type="submit" class="btn btn-light btn-touch text-danger w-100" id="term-delete-btn">Delete the term</button>
        </form>
    <?php endif; ?>
</div>
