<?php /** Add documents (screen `source-add`, three tabs) or change a source (screen `source-edit`). Data: s (null when adding), b, tab, fetchOn, maxBytes, results */
$bid = (int) $b['base_id'];
if ($s !== null):
    $sid = (int) $s['source_id']; ?>
<?= view('shared/header.php', ['id' => 'source-edit', 'title' => 'Change ' . $s['title'], 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Sources', '/bases/' . $bid . '/sources'], [$s['title'], '/sources/' . $sid], ['Edit', null]],
    'back' => back_link() ?? ['/sources/' . $sid, $s['title']]]) ?>
<div class="main-content" id="source-edit-content">
    <form method="post" action="/sources/update.php" hx-post="/sources/update.php" hx-target="#flash" id="source-edit-form">
        <?= csrf_field() ?><input type="hidden" name="source" value="<?= $sid ?>">
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="source-form-field-title">Title</label>
            <input type="text" name="title" id="source-form-field-title" class="form-control btn-touch mb-3" maxlength="300" required value="<?= e($s['title']) ?>">
            <label class="form-label fs-12 text-muted" for="source-form-field-language">Language</label>
            <input type="text" name="language" id="source-form-field-language" class="form-control btn-touch" maxlength="12" value="<?= e((string) $s['language']) ?>" autocapitalize="none" spellcheck="false">
            <div class="fs-11 text-muted mt-1">An ISO code: en, fr, de, pt-BR. Blank keeps the base's.</div>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="source-form-save-btn">Save</button>
        <?= hx_link('/sources/' . $sid, 'Cancel', 'btn btn-light btn-touch w-100', 'id="source-form-cancel-link"') ?>
    </form>
</div>
<?php return; endif;
$tabs = ['files' => ['Files', 'feather-upload'], 'text' => ['Text', 'feather-type']] + ($fetchOn ? ['url' => ['Address', 'feather-globe']] : []); ?>
<?= view('shared/header.php', ['id' => 'source-add', 'title' => 'Add documents', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Sources', '/bases/' . $bid . '/sources'], ['Add', null]],
    'back' => back_link() ?? ['/bases/' . $bid . '/sources', 'the sources']]) ?>
<div class="main-content" id="source-add-content">
    <nav class="kn-base-tabs mb-3" id="source-add-tabs" aria-label="How to add"><ul class="nav nav-pills flex-nowrap">
        <?php foreach ($tabs as $k => [$label, $icon]): ?>
            <li class="nav-item"><?= hx_link('/sources/new?base=' . $bid . '&tab=' . $k, '<i class="' . e($icon) . ' me-1"></i>' . e($label), 'nav-link btn-touch' . ($tab === $k ? ' active' : ''), 'id="source-add-tab-' . $k . '"' . ($tab === $k ? ' aria-current="page"' : '')) ?></li>
        <?php endforeach; ?>
    </ul></nav>
    <?php if (!$fetchOn): ?><div class="fs-12 text-muted mb-2" id="source-add-fetch-off">Fetching pages is turned off.</div><?php endif; ?>
    <?php if ($tab === 'files'): ?>
        <form method="post" action="/sources/add.php" enctype="multipart/form-data" id="source-files-form" class="card mb-3" data-base="<?= $bid ?>" data-max-bytes="<?= (int) $maxBytes ?>"><div class="card-body">
            <?= csrf_field() ?><input type="hidden" name="base" value="<?= $bid ?>"><input type="hidden" name="kind" value="file">
            <label class="kn-drop-zone d-flex flex-column align-items-center justify-content-center text-center p-4 rounded" for="source-form-field-files" id="source-drop-zone">
                <i class="feather-upload-cloud fs-2 mb-2"></i>
                <span class="fw-semibold">Drop documents here, or choose them</span>
                <span class="fs-12 text-muted">PDF, Word, PowerPoint, Excel, HTML, Markdown, text, CSV, JSON — up to <?= e(fmt_bytes((int) $maxBytes)) ?> a file, <?= SOURCE_FILES_PER_REQUEST ?> at a time</span>
                <input type="file" name="files[]" id="source-form-field-files" class="form-control btn-touch mt-3" multiple accept="<?= e(SOURCE_FILE_ACCEPT) ?>">
            </label>
            <div id="upload-results" class="mt-3" aria-live="polite">
                <?php if ($results !== null): ?><?php foreach ($results as $n => $r): ?><?= view('sources/partials/upload-result.php', ['n' => $n + 1, 'r' => $r]) ?><?php endforeach; ?><?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-touch w-100 mt-3" id="source-files-btn">Add these documents</button>
        </div></form>
        <script src="/assets/js/kn-upload.js" defer></script>
    <?php elseif ($tab === 'text'): ?>
        <form method="post" action="/sources/add.php" hx-post="/sources/add.php" hx-target="#flash" id="source-text-form" class="card mb-3"><div class="card-body">
            <?= csrf_field() ?><input type="hidden" name="base" value="<?= $bid ?>"><input type="hidden" name="kind" value="text">
            <label class="form-label fs-12 text-muted" for="source-form-field-title">Title</label>
            <input type="text" name="title" id="source-form-field-title" class="form-control btn-touch mb-3" maxlength="300" required>
            <label class="form-label fs-12 text-muted" for="source-form-field-text">The text — Markdown headings (#, ##) become its sections</label>
            <textarea name="text" id="source-form-field-text" class="form-control mb-1" rows="12" required></textarea>
            <div class="fs-11 text-muted mb-3">Up to 2 MB.</div>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="source-text-btn">Add the text</button>
        </div></form>
    <?php else: ?>
        <form method="post" action="/sources/add.php" hx-post="/sources/add.php" hx-target="#flash" id="source-url-form" class="card mb-3"><div class="card-body">
            <?= csrf_field() ?><input type="hidden" name="base" value="<?= $bid ?>"><input type="hidden" name="kind" value="url">
            <label class="form-label fs-12 text-muted" for="source-form-field-url">Address</label>
            <input type="url" name="url" id="source-form-field-url" class="form-control btn-touch mb-1" maxlength="2048" required placeholder="https://…" autocapitalize="none" spellcheck="false">
            <div class="fs-11 text-muted mb-3">One page, fetched from the server; public addresses only.</div>
            <label class="form-label fs-12 text-muted" for="source-form-field-title">Title (optional — the page's own is used)</label>
            <input type="text" name="title" id="source-form-field-title" class="form-control btn-touch mb-3" maxlength="300">
            <button type="submit" class="btn btn-primary btn-touch w-100" id="source-url-btn">Add the page</button>
        </div></form>
    <?php endif; ?>
    <?= hx_link('/bases/' . $bid . '/sources', 'Done', 'btn btn-light btn-touch w-100', 'id="source-add-done"') ?>
</div>
