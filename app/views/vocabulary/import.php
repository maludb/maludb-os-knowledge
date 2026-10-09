<?php /** Import terms from a CSV: Check, then Import (screen `vocabulary-import`). Data: b, check (null before a check), csv */
$id = (int) $b['base_id']; ?>
<?= view('shared/header.php', ['id' => 'vocabulary-import', 'title' => 'Import terms', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Vocabulary', '/bases/' . $id . '/vocabulary'], ['Import', null]],
    'back' => back_link() ?? ['/bases/' . $id . '/vocabulary', 'the vocabulary']]) ?>
<div class="main-content" id="vocabulary-import-content">
    <?php if ($check === null): ?>
        <form method="post" action="/vocabulary/import.php" enctype="multipart/form-data" class="card mb-3" id="vocabulary-import-form"><div class="card-body">
            <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>"><input type="hidden" name="step" value="check">
            <div class="fs-12 text-muted mb-2">A header row names <code>canonical_name</code> (or term, name), <code>aliases</code> (separated by ; or |), <code>subject_type</code> (or type) and <code>description</code>, in any order. Commas, semicolons or tabs. Up to 500 rows.</div>
            <label class="form-label fs-12 text-muted" for="vocabulary-import-file">A CSV file</label>
            <input type="file" name="file" id="vocabulary-import-file" class="form-control btn-touch mb-3" accept=".csv,.txt,.tsv">
            <label class="form-label fs-12 text-muted" for="vocabulary-import-csv">Or paste it</label>
            <textarea name="csv" id="vocabulary-import-csv" class="form-control mb-3" rows="8"></textarea>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="vocabulary-import-check-btn">Check</button>
        </div></form>
    <?php else: ?>
        <?= view('vocabulary/partials/import-check.php', ['check' => $check]) ?>
        <form method="post" action="/vocabulary/import.php" hx-post="/vocabulary/import.php" hx-target="#flash" id="vocabulary-import-apply">
            <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>"><input type="hidden" name="step" value="apply"><input type="hidden" name="csv" value="<?= e($csv) ?>">
            <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="vocabulary-import-apply-btn">Import</button>
        </form>
        <?= hx_link('/bases/' . $id . '/vocabulary-import', 'Start again', 'btn btn-light btn-touch w-100', 'id="vocabulary-import-again"') ?>
    <?php endif; ?>
</div>
