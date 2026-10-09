<?php /** Replace a source by a new version (screen `source-replace`). Data: s, b, tab, fetchOn, maxBytes */
$sid = (int) $s['source_id']; $bid = (int) $b['base_id'];
$tabs = ['files' => ['A file', 'feather-upload'], 'text' => ['Text', 'feather-type']] + ($fetchOn ? ['url' => ['An address', 'feather-globe']] : []); ?>
<?= view('shared/header.php', ['id' => 'source-replace', 'title' => 'Replace ' . $s['title'], 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Sources', '/bases/' . $bid . '/sources'], [$s['title'], '/sources/' . $sid], ['Replace', null]],
    'back' => back_link() ?? ['/sources/' . $sid, $s['title']]]) ?>
<div class="main-content" id="source-replace-content">
    <div class="fs-12 text-muted mb-3">The old version's sections are forgotten and the new one is read and cut afresh; its areas are kept.</div>
    <nav class="kn-base-tabs mb-3" id="source-replace-tabs" aria-label="The new version is"><ul class="nav nav-pills flex-nowrap">
        <?php foreach ($tabs as $k => [$label, $icon]): ?>
            <li class="nav-item"><?= hx_link('/sources/' . $sid . '/replace?tab=' . $k, '<i class="' . e($icon) . ' me-1"></i>' . e($label), 'nav-link btn-touch' . ($tab === $k ? ' active' : ''), 'id="source-replace-tab-' . $k . '"') ?></li>
        <?php endforeach; ?>
    </ul></nav>
    <form method="post" action="/sources/replace.php" <?= $tab === 'files' ? 'enctype="multipart/form-data"' : 'hx-post="/sources/replace.php" hx-target="#flash"' ?> id="source-replace-form" class="card mb-3"><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="source" value="<?= $sid ?>"><input type="hidden" name="kind" value="<?= $tab === 'files' ? 'file' : e($tab) ?>">
        <?php if ($tab === 'files'): ?>
            <label class="form-label fs-12 text-muted" for="source-form-field-files">The new version (up to <?= e(fmt_bytes((int) $maxBytes)) ?>)</label>
            <input type="file" name="files[]" id="source-form-field-files" class="form-control btn-touch mb-3" accept="<?= e(SOURCE_FILE_ACCEPT) ?>" required>
        <?php elseif ($tab === 'text'): ?>
            <label class="form-label fs-12 text-muted" for="source-form-field-text">The new text</label>
            <textarea name="text" id="source-form-field-text" class="form-control mb-3" rows="12" required></textarea>
        <?php else: ?>
            <label class="form-label fs-12 text-muted" for="source-form-field-url">The new address</label>
            <input type="url" name="url" id="source-form-field-url" class="form-control btn-touch mb-3" maxlength="2048" required value="<?= $s['kind'] === 'url' ? e((string) $s['origin']) : '' ?>" autocapitalize="none" spellcheck="false">
        <?php endif; ?>
        <label class="form-label fs-12 text-muted" for="source-form-field-title">Title (blank keeps "<?= e($s['title']) ?>")</label>
        <input type="text" name="title" id="source-form-field-title" class="form-control btn-touch mb-3" maxlength="300">
        <button type="submit" class="btn btn-primary btn-touch w-100" id="source-replace-btn">Replace</button>
    </div></form>
    <?= hx_link('/sources/' . $sid, 'Cancel', 'btn btn-light btn-touch w-100', 'id="source-replace-cancel"') ?>
</div>
