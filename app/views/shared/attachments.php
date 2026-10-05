<?php /** The documents panel of a source (shared). Data: type, id, attachments (mcp_attachments rows), may (attach, delete), here, prefix (ids: {prefix}-documents) */ $p = $prefix ?? 'record'; ?>
<div class="card mb-3" id="<?= e($p) ?>-documents"><div class="card-header"><h5 class="card-title mb-0">Documents</h5></div>
    <div class="card-body">
        <?php if ($attachments === []): ?><div class="text-muted fs-12" id="<?= e($p) ?>-documents-empty">Nothing attached.</div><?php endif; ?>
        <div class="d-flex flex-wrap gap-2" id="<?= e($p) ?>-documents-list">
            <?php foreach ($attachments as $a): $aid = (int) $a['attachment_id']; ?>
                <span class="border rounded px-2 py-1 d-inline-flex align-items-center gap-2 fs-12" id="attachment-chip-<?= $aid ?>">
                    <a href="/files/<?= $aid ?>" target="_blank" rel="noopener" id="attachment-chip-<?= $aid ?>-link"><i class="feather-paperclip me-1"></i><?= e($a['filename']) ?></a><span class="text-muted">· <?= e(fmt_bytes((int) $a['byte_size'])) ?></span>
                    <?php if ($may['delete']): ?><form method="post" action="/files/delete.php" hx-post="/files/delete.php" hx-target="#flash" class="d-inline" hx-confirm="Remove <?= e($a['filename']) ?>?"><?= csrf_field() ?><input type="hidden" name="attachment" value="<?= $aid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-link btn-sm p-0 text-danger" id="attachment-chip-<?= $aid ?>-delete-btn" title="Remove">&times;</button></form><?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
