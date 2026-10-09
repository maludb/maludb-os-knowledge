<?php /** A base's settings (screen `base-settings`). Data: b, mayLimit, tabs, notice */
$id = (int) $b['base_id']; $archived = $b['archived_at'] !== null; ?>
<?= view('shared/header.php', ['id' => 'base-settings', 'title' => 'Settings of ' . $b['name'], 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Settings', null]],
    'back' => back_link() ?? ['/bases/' . $id, $b['name']]]) ?>
<div class="main-content" id="base-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'settings']) ?>
    <form method="post" action="/bases/settings-save.php" hx-post="/bases/settings-save.php" hx-target="#flash" id="base-settings-form">
        <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>">
        <fieldset <?= $archived ? 'disabled' : '' ?>>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">How the base reads its sources</h5></div><div class="card-body">
            <input type="hidden" name="auto_extract" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-1 btn-touch" for="base-form-field-auto_extract"><input type="checkbox" class="form-check-input mt-0" name="auto_extract" value="yes" id="base-form-field-auto_extract" <?= !empty($b['auto_extract']) ? 'checked' : '' ?>>Build new sources on their own</label>
            <div class="fs-11 text-muted mb-3">On: a new source's sections are sent to the engine as soon as they are cut, within the monthly limit. Off: a curator starts each build.</div>
            <div class="fs-12 text-muted mb-1">Sensitivity — passed to the engine with every call</div>
            <?php foreach (BASE_SENSITIVITIES as $s => $words): ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="base-form-field-sensitivity-<?= e($s) ?>"><input type="radio" class="form-check-input mt-0" name="sensitivity" value="<?= e($s) ?>" id="base-form-field-sensitivity-<?= e($s) ?>" <?= $b['sensitivity'] === $s ? 'checked' : '' ?>><span class="fs-12"><?= e($words) ?></span></label>
            <?php endforeach; ?>
            <label class="form-label fs-12 text-muted mt-2" for="base-form-field-language">Language of the documents</label>
            <input type="text" name="language" id="base-form-field-language" class="form-control btn-touch" maxlength="12" value="<?= e($b['language']) ?>" autocapitalize="none" spellcheck="false">
            <div class="fs-11 text-muted mt-1">An ISO code: en, fr, de, pt-BR.</div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The month's limit</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="base-form-field-monthly_text_mb_limit">Text sent to the engine per month (MB)</label>
            <?php if ($mayLimit): ?>
                <input type="number" name="monthly_text_mb_limit" id="base-form-field-monthly_text_mb_limit" class="form-control btn-touch" min="1" max="100000" step="1" value="<?= (int) $b['monthly_text_mb_limit'] ?>">
                <div class="fs-11 text-muted mt-1">From 1 to 100,000. A build that would pass it waits for next month or a higher limit.</div>
            <?php else: ?>
                <div class="form-control btn-touch bg-light" id="base-form-field-monthly_text_mb_limit" aria-readonly="true"><?= (int) $b['monthly_text_mb_limit'] ?></div>
                <div class="fs-11 text-muted mt-1">The admin sets it.</div>
            <?php endif; ?>
        </div></div>
        </fieldset>
        <div class="card mb-3"><div class="card-body fs-12">
            <div><span class="text-muted">Namespace in the engine</span> <code id="base-settings-namespace"><?= e($b['namespace']) ?></code> — set once</div>
            <div><span class="text-muted">Guideline version</span> <span id="base-settings-guideline-version"><?= (int) $b['guideline_version'] ?></span> — it rises when a guideline that shapes the graph changes</div>
        </div></div>
        <?php if (!$archived): ?><button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="base-settings-save-btn">Save</button><?php endif; ?>
        <?= hx_link('/bases/' . $id, 'Back to the base', 'btn btn-light btn-touch w-100', 'id="base-settings-cancel-link"') ?>
    </form>
</div>
