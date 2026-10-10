<?php /** Suggested questions: chips that fill the composer (links that prefill it without JavaScript). Data: base_id, area (row|null), suggestions */
if ($suggestions === []) { return; } ?>
<div class="mb-3" id="ask-suggestions">
    <div class="fs-12 text-muted mb-2">You could ask</div>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($suggestions as $i => $s): ?>
            <a class="btn btn-light btn-touch text-start kn-suggestion" id="ask-suggestion-<?= $i ?>" data-kn-fill="<?= e($s['question']) ?>"
               href="<?= e('/ask?base=' . (int) $base_id . ($area !== null ? '&area=' . rawurlencode((string) $area['slug']) : '') . '&q=' . rawurlencode($s['question'])) ?>"><?= e($s['question']) ?></a>
        <?php endforeach; ?>
    </div>
</div>
