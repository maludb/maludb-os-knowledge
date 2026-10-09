<?php /** The kind's line and its three examples, beside the guideline form (below it at 375). Data: kind */ ?>
<div class="card mb-3" id="guideline-examples"><div class="card-body p-3">
    <div class="fw-semibold fs-13 mb-1"><?= e(GUIDELINE_KINDS[$kind][0]) ?></div>
    <div class="fs-12 text-muted mb-2"><?= e(GUIDELINE_KINDS[$kind][1]) ?></div>
    <div class="fs-12 fw-semibold mb-1">Examples</div>
    <?php foreach (GUIDELINE_EXAMPLES[$kind] as $i => $ex): ?>
        <div class="fs-12 border rounded p-2 mb-2" id="guideline-example-<?= $i ?>"><?= e($ex) ?></div>
    <?php endforeach; ?>
</div></div>
