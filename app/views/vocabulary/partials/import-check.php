<?php /** What an import would do, row by row (vocabulary-import, after Check). Data: check */
$n = ['new' => 0, 'update' => 0, 'refused' => 0];
foreach ($check as $c) { $n[$c['what']]++; } ?>
<div class="card mb-3" id="import-check"><div class="card-header"><h5 class="card-title mb-0"><?= $n['new'] ?> new · <?= $n['update'] ?> to update · <?= $n['refused'] ?> refused</h5></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Line</th><th>Term</th><th>What</th></tr></thead><tbody>
    <?php foreach ($check as $c): ?>
        <tr id="import-check-line-<?= (int) $c['line'] ?>"><td><?= (int) $c['line'] ?></td><td><?= e((string) ($c['canonical_name'] ?? '')) ?></td>
            <td><?= $c['what'] === 'refused' ? chip(['refused', 'danger']) . ' <span class="fs-12">' . e((string) $c['reason']) . '</span>' : chip([$c['what'], $c['what'] === 'new' ? 'success' : 'info']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
