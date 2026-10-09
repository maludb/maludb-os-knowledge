<?php /** One line of an upload's outcome: "Handbook.pdf — added" or "setup.exe — refused: …". Data: n, r ([name, ok, sentence, source_id?]) */ ?>
<div class="d-flex align-items-start gap-2 py-1 fs-12 border-bottom" id="upload-result-<?= (int) $n ?>">
    <i class="<?= $r['ok'] ? 'feather-check-circle text-success' : 'feather-x-circle text-danger' ?> mt-1"></i>
    <span class="min-w-0"><span class="fw-semibold"><?= e($r['name']) ?></span> — <?= $r['ok']
        ? (isset($r['source_id']) ? hx_link('/sources/' . (int) $r['source_id'], 'added') : 'added')
        : 'refused: ' . e($r['sentence']) ?></span>
</div>
