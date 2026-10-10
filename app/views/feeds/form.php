<?php /** Follow a feed or change one (screens `feed-add`, `feed-edit`). Data: f (the feed row, or null), b, tools, areas */
$bid = (int) $b['base_id']; $fid = $f['feed_id'] ?? null; $screen = $f === null ? 'feed-add' : 'feed-edit';
$back = ['/bases/' . $bid . '/feeds', 'the feeds'];
$cur = $f === null ? null : $f['provider'] . '.' . $f['tool'];
$args = $f['arguments'] ?? []; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $f === null ? 'Follow a feed' : 'Change the feed', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Feeds', '/bases/' . $bid . '/feeds'], [$f === null ? 'Follow' : 'Change', null]],
    'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <?php if ($tools === []): ?>
        <div class="card"><div class="card-body text-muted" id="feed-form-none">No sibling application is declared as a source of feeds.</div></div>
    <?php else: ?>
    <form method="post" action="/feeds/save.php" hx-post="/feeds/save.php" hx-target="#flash" id="feed-form">
        <?= csrf_field() ?>
        <?php if ($f === null): ?><input type="hidden" name="base" value="<?= $bid ?>"><?php else: ?><input type="hidden" name="feed" value="<?= (int) $fid ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="feed-form-field-source_tool">What to follow</label>
            <select name="source_tool" id="feed-form-field-source_tool" class="form-select btn-touch mb-3">
                <?php foreach ($tools as $key => $t): ?><option value="<?= e($key) ?>" <?= $cur === $key ? 'selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?>
            </select>
            <?php foreach ($tools as $key => $t): foreach ($t['arguments'] as $a): $fid2 = 'feed-form-field-' . $a['name']; ?>
                <div class="mb-3 kn-feed-arg" data-for="<?= e($key) ?>" id="<?= e($fid2) ?>-group">
                    <label class="form-label fs-12 text-muted" for="<?= e($fid2) ?>"><?= e($a['label']) ?><?= !empty($a['required']) ? '' : ' (optional)' ?></label>
                    <input type="text" name="<?= e($a['name']) ?>" id="<?= e($fid2) ?>" class="form-control btn-touch" maxlength="200" value="<?= e((string) ($cur === $key ? ($args[$a['name']] ?? '') : '')) ?>">
                    <div class="fs-11 text-muted mt-1"><?= e((string) ($a['hint'] ?? '')) ?></div>
                </div>
            <?php endforeach; endforeach; ?>
            <label class="form-label fs-12 text-muted" for="feed-form-field-focus_area">Area for what it brings</label>
            <select name="focus_area" id="feed-form-field-focus_area" class="form-select btn-touch mb-3">
                <option value="">Whole base</option>
                <?php foreach ($areas as $a): ?><option value="<?= (int) $a['focus_area_id'] ?>" <?= $f !== null && (string) ($f['focus_area_id'] ?? '') === (string) $a['focus_area_id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label fs-12 text-muted" for="feed-form-field-every_minutes">Read it every (minutes)</label>
            <input type="number" name="every_minutes" id="feed-form-field-every_minutes" class="form-control btn-touch mb-1" min="5" max="10080" value="<?= (int) ($f['every_minutes'] ?? 60) ?>">
            <div class="fs-11 text-muted mb-3">From 5 minutes to 7 days (10,080). A new feed is read at once.</div>
            <input type="hidden" name="enabled" value="0">
            <label class="d-flex align-items-center gap-2 btn-touch" for="feed-form-field-enabled"><input type="checkbox" class="form-check-input mt-0" name="enabled" value="1" id="feed-form-field-enabled" <?= ($f['enabled'] ?? true) ? 'checked' : '' ?>> <span>Enabled</span></label>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="feed-form-save-btn"><?= $f === null ? 'Follow the feed' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="feed-form-cancel"') ?>
    </form>
    <script>
    (function () {
        var sel = document.getElementById('feed-form-field-source_tool');
        if (!sel || sel.dataset.bound) { return; }
        sel.dataset.bound = '1';
        function show() { document.querySelectorAll('.kn-feed-arg').forEach(function (g) { var on = g.dataset.for === sel.value; g.hidden = !on; g.querySelectorAll('input').forEach(function (i) { i.disabled = !on; }); }); }
        sel.addEventListener('change', show);
        show();
    })();
    </script>
    <?php endif; ?>
</div>
