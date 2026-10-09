<?php /** The sources in no area, each with an area to put it in; the areas with no source (areas, curators). Data: unassigned, empty, active, may, base_id */ ?>
<?php if ($unassigned !== []): ?>
<div class="card mb-3" id="areas-unassigned"><div class="card-header"><h5 class="card-title mb-0">Sources in no area (<?= count($unassigned) ?>)</h5></div>
    <div class="list-group list-group-flush">
    <?php foreach ($unassigned as $s): $sid = (int) $s['source_id']; ?>
        <div class="list-group-item d-flex flex-wrap align-items-center gap-2" id="unassigned-source-<?= $sid ?>">
            <span class="me-auto min-w-0 text-truncate"><?= hx_link('/sources/' . $sid, e($s['title'])) ?></span>
            <?php if ($may['manage'] && $active !== []): ?>
                <form method="post" action="/areas/assign.php" hx-post="/areas/assign.php" hx-target="#flash" class="d-flex gap-1 m-0" id="unassigned-source-<?= $sid ?>-form">
                    <?= csrf_field() ?><input type="hidden" name="source" value="<?= $sid ?>"><input type="hidden" name="assigned" value="yes"><input type="hidden" name="return_to" value="/bases/<?= (int) $base_id ?>/areas">
                    <label class="visually-hidden" for="unassigned-source-<?= $sid ?>-area">Area for <?= e($s['title']) ?></label>
                    <select name="focus_area" id="unassigned-source-<?= $sid ?>-area" class="form-select form-select-sm btn-touch kn-level-select"><?php foreach ($active as $a): ?><option value="<?= (int) $a['focus_area_id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select>
                    <button type="submit" class="btn btn-light btn-sm btn-touch" id="unassigned-source-<?= $sid ?>-btn">Put in</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php if ($empty !== []): ?>
<div class="card mb-3" id="areas-empty"><div class="card-body p-3 fs-12"><span class="fw-semibold">Areas with no source:</span>
    <?= implode(', ', array_map(static fn (array $a): string => hx_link('/areas/' . (int) $a['focus_area_id'], e($a['name'])), $empty)) ?></div></div>
<?php endif; ?>
