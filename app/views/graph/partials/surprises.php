<?php /** The surprises region. Data: b, o, here */
$id = (int) $b['base_id'];
$end = static function (array $n) use ($id, $here): string {
    [$icon, $colour] = graph_kind($n['kind']);
    $url = $n['kind'] === 'subject' ? graph_subject_url($id, $n['name']) : '/sections/' . (int) $n['id'];
    return hx_link(with_back($url, $here), '<i class="' . $icon . ' text-' . $colour . ' me-1"></i><span class="text-break">' . e($n['name']) . '</span>', 'kn-row-link');
}; ?>
<div class="card mb-3" id="graph-surprises"><div class="card-body">
    <h6 class="mb-2">Surprising links</h6>
    <?php if (isset($o['errors']['surprises'])): ?>
        <?= view('graph/partials/engine-error.php', ['region' => 'surprises', 'code' => $o['errors']['surprises']]) ?>
    <?php elseif ($o['surprises'] === []): ?>
        <div class="text-muted fs-13" id="graph-surprises-empty">No surprising links yet — they appear once MaluDB has communities to compare.</div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($o['surprises'] as $i => $s): ?>
                <div class="list-group-item" id="graph-surprise-<?= $i + 1 ?>">
                    <div class="d-flex flex-wrap align-items-center gap-2"><?= $end($s['from']) ?><span class="fs-12 text-muted">— <?= e($s['verb'] ?? 'linked to') ?> →</span><?= $end($s['to']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($o['surprises_more'])): ?><div class="fs-12 text-muted mt-2" id="graph-surprises-more">and more — only the first subjects were checked against this base.</div><?php endif; ?>
    <?php endif; ?>
</div></div>
