<?php /** The size region. Data: b, o, tz. The engine's document count is this base's own; the rest are the counts cached after its builds (never a tenant total). */
$id = (int) $b['base_id']; $s = $o['size']; ?>
<div class="card mb-3" id="graph-size"><div class="card-body">
    <h6 class="mb-2">Size</h6>
    <div class="row g-3 text-center">
        <div class="col-6 col-md-3"><div class="fs-4 fw-semibold" id="graph-size-documents"><?= $s['documents'] === null ? '—' : number_format((int) $s['documents']) ?></div><div class="fs-12 text-muted">documents in the graph</div></div>
        <div class="col-6 col-md-3"><div class="fs-4 fw-semibold" id="graph-size-subjects"><?= number_format((int) $s['subjects']) ?></div><div class="fs-12 text-muted">subjects</div></div>
        <div class="col-6 col-md-3"><div class="fs-4 fw-semibold" id="graph-size-statements"><?= number_format((int) $s['statements']) ?></div><div class="fs-12 text-muted">statements</div></div>
        <div class="col-6 col-md-3"><div class="fs-4 fw-semibold" id="graph-size-communities"><?= number_format((int) $s['communities']) ?></div><div class="fs-12 text-muted">communities</div></div>
    </div>
    <div class="fs-12 text-muted mt-2" id="graph-size-built"><?= $s['last_built_at'] === null ? 'Not built yet.' : 'Last built ' . e(format_ts($s['last_built_at'], $tz)) . '. The subject, statement and community counts are as that build left them.' ?></div>
    <?php if (isset($o['errors']['size'])): ?><div class="mt-2"><?= view('graph/partials/engine-error.php', ['region' => 'size', 'code' => $o['errors']['size']]) ?></div><?php endif; ?>
</div></div>
