<?php /** A template as a card: a radio on base-add ($radio), read-only on /admin/templates ($full: every guideline's words). Data: t, radio?, checked?, full? */
$key = (string) $t['template_key'];
$kinds = array_values(array_unique(array_map(static fn (array $g): string => (string) $g['kind'], $t['guidelines'])));
if (!empty($radio)): ?>
<label class="card mb-0 h-100 kn-choice-card" for="template-card-<?= e($key) ?>-radio" id="template-card-<?= e($key) ?>">
    <div class="card-body d-flex gap-3 align-items-start">
        <input type="radio" class="form-check-input mt-1 flex-shrink-0" name="template" value="<?= e($key) ?>" id="template-card-<?= e($key) ?>-radio" <?= !empty($checked) ? 'checked' : '' ?>>
        <div class="min-w-0">
            <div class="fw-semibold"><?= e($t['name']) ?></div>
            <div class="fs-12 text-muted"><?= e($t['description']) ?></div>
            <?php if ($kinds !== []): ?><div class="fs-11 text-muted mt-1">Ships guidelines for: <?= e(implode(', ', $kinds)) ?></div><?php endif; ?>
        </div>
    </div>
</label>
<?php else: ?>
<div class="card mb-0 h-100" id="template-card-<?= e($key) ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div><div class="fw-semibold fs-14"><?= e($t['name']) ?></div><div class="fs-11 text-muted"><code><?= e($key) ?></code> · version <?= (int) $t['version'] ?><?= empty($t['is_active']) ? ' · not offered' : '' ?></div></div>
        <?= chip([$t['area_rule'] === 'communities' ? 'areas proposed' : 'no area proposals', 'secondary']) ?>
    </div>
    <div class="fs-12 text-muted mt-2"><?= e($t['description']) ?></div>
    <?php foreach ($t['guidelines'] as $i => $g): ?>
        <div class="border rounded p-2 mt-2" id="template-card-<?= e($key) ?>-guideline-<?= (int) $i ?>">
            <div class="fs-12 fw-semibold"><?= chip([(string) $g['kind'], 'info']) ?> <?= e($g['title'] ?? '') ?></div>
            <div class="fs-12 mt-1 kn-prewrap"><?= e($g['body']) ?></div>
        </div>
    <?php endforeach; ?>
    <?php if ($t['subject_types'] !== []): ?><div class="fs-11 text-muted mt-2">Subject types: <?= e(implode(', ', $t['subject_types'])) ?></div><?php endif; ?>
</div></div>
<?php endif; ?>
