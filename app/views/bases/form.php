<?php /** Create (the first run: a name and a template, Done) or change a base (screens `base-add`, `base-edit`). Data: cur, templates, chosen, hasSource */
$id = $cur['base_id'] ?? null;
$screen = $cur === null ? 'base-add' : 'base-edit';
$back = $cur === null ? ['/bases', 'Bases'] : ['/bases/' . (int) $id, $cur['name']]; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Create a knowledge base' : 'Change ' . $cur['name'],
    'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$cur === null ? 'New' : $cur['name'], $cur === null ? null : '/bases/' . (int) $id]] + ($cur === null ? [] : [3 => ['Edit', null]]),
    'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/bases/save.php" hx-post="/bases/save.php" hx-target="#flash" id="<?= e($screen) ?>-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="base" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="base-form-field-name">Name</label>
            <input type="text" name="name" id="base-form-field-name" class="form-control btn-touch mb-3" maxlength="200" required value="<?= e($cur['name'] ?? '') ?>"<?= $cur === null ? ' autofocus' : '' ?> placeholder="Field manuals, HR policies, the product docs…">
            <?php if ($cur === null): ?>
                <div class="fs-12 text-muted mb-2" id="base-form-template-label">Template — the guidelines it starts with; you can change them after</div>
                <div class="row g-2 mb-3" id="base-form-field-template" role="radiogroup" aria-labelledby="base-form-template-label">
                    <?php foreach ($templates as $t): ?><div class="col-12 col-md-6"><?= view('bases/partials/template-card.php', ['t' => $t, 'radio' => true, 'checked' => $t['template_key'] === $chosen]) ?></div><?php endforeach; ?>
                </div>
                <details class="mb-3" id="base-form-more">
                    <summary class="fs-12 fw-semibold btn-touch d-flex align-items-center" id="base-form-more-summary">More: a description and a key</summary>
                    <div class="pt-2">
            <?php endif; ?>
            <label class="form-label fs-12 text-muted" for="base-form-field-description">Description</label>
            <textarea name="description" id="base-form-field-description" class="form-control mb-3" rows="3" maxlength="4000"><?= e($cur['description'] ?? '') ?></textarea>
            <label class="form-label fs-12 text-muted" for="base-form-field-key">Key — the base's name in a URL and the API</label>
            <input type="text" name="key" id="base-form-field-key" class="form-control btn-touch" maxlength="60" pattern="[a-z0-9][a-z0-9_\-]{0,59}" value="<?= e($cur['key'] ?? '') ?>" placeholder="<?= $cur === null ? 'blank gives kb-' . '&lt;number&gt;' : '' ?>" autocapitalize="none" spellcheck="false">
            <div class="fs-11 text-muted mt-1 mb-3">Lower-case letters, digits, dashes and underscores, starting with a letter or digit.</div>
            <?php if ($cur === null): ?>
                    </div>
                </details>
            <?php else: ?>
                <label class="form-label fs-12 text-muted" for="base-form-field-template">Template</label>
                <select name="template" id="base-form-field-template" class="form-select btn-touch" <?= $hasSource ? 'disabled aria-describedby="base-form-template-locked"' : '' ?>>
                    <?php foreach ($templates as $t): ?><option value="<?= e($t['template_key']) ?>" <?= $t['template_key'] === $chosen ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
                </select>
                <?php if ($hasSource): ?>
                    <div class="fs-11 text-muted mt-1" id="base-form-template-locked">A base switches template only while it is empty.</div>
                <?php else: ?>
                    <div class="fs-11 text-muted mt-1">Switching replaces the shipped guidelines nobody has changed; ones a person wrote or edited stay.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="base-form-save-btn"><?= $cur === null ? 'Done' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="base-form-cancel-link"') ?>
    </form>
</div>
