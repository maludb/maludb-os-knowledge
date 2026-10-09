<?php /** A source's areas, as checkboxes of the base's active areas (source-view, curators). Data: s, areas (active) */
$sid = (int) $s['source_id']; $mine = pg_int_array($s['focus_area_ids'] ?? null); ?>
<form method="post" action="/sources/areas.php" hx-post="/sources/areas.php" hx-target="#flash" class="card mb-3" id="source-areas-form"><div class="card-body">
    <h6 class="fw-bold mb-2">Focus areas</h6>
    <?= csrf_field() ?><input type="hidden" name="source" value="<?= $sid ?>"><input type="hidden" name="areas[]" value="">
    <?php foreach ($areas as $a): $aid = (int) $a['focus_area_id']; ?>
        <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="source-areas-<?= $aid ?>"><input type="checkbox" class="form-check-input mt-0" name="areas[]" value="<?= $aid ?>" id="source-areas-<?= $aid ?>" <?= in_array($aid, $mine, true) ? 'checked' : '' ?>><?= e($a['name']) ?></label>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-light btn-touch w-100" id="source-areas-btn">Save the areas</button>
</div></form>
