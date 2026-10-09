<?php /** What the engine is told with this draft (Preview). Data: p (the preview's facts). Rendered into #preview, or inside the full form without JavaScript. */
if ($p === null) { return; } ?>
<div class="card mb-3" id="preview-result"><div class="card-body p-3">
    <?php if ($p['shaping']): ?>
        <div class="d-flex flex-wrap gap-1 mb-2">
            <?= chip($p['changes'] ? ['this draft changes what the engine is told', 'warning'] : ['no change', 'success'], 'preview-change') ?>
            <?= chip([$p['lines']['extract'] . ' EXTRACT · ' . $p['lines']['term'] . ' TERM · ' . $p['lines']['exclude'] . ' EXCLUDE', 'light']) ?>
        </div>
        <?php foreach ($p['warnings'] as $w): ?><div class="alert alert-warning py-2 fs-12 mb-2"><?= e($w) ?></div><?php endforeach; ?>
        <div class="fs-11 text-muted mb-2">This shows what the model will be told, not what it will extract — a dry run of the model comes with MaluDB M5.</div>
        <pre class="kn-preview-pre" id="preview-prompt"><?= e($p['text']) ?></pre>
        <?php if ($p['engine'] !== null): ?>
            <details class="mt-2" id="preview-engine"><summary class="fs-12 fw-semibold btn-touch d-flex align-items-center">The engine's own generic prompt, for comparison</summary><pre class="kn-preview-pre mt-2"><?= e($p['engine']) ?></pre></details>
        <?php endif; ?>
    <?php else: ?>
        <div class="fs-12 text-muted mb-2">Answering and audience guidelines are told to the model with every question — they apply to the next one.</div>
        <pre class="kn-preview-pre" id="preview-system"><?= e($p['text']) ?></pre>
    <?php endif; ?>
</div></div>
