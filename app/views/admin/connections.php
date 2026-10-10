<?php /** Which applications read what (screen `connections`, read-only). Data: shares, principals, reads, feeds, calls, unnamed, os_url, tz */ ?>
<?= view('shared/header.php', ['id' => 'connections', 'title' => 'Connections', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], ['Settings', '/admin/settings'], ['Connections', null]],
    'back' => back_link() ?? ['/admin/settings', 'Settings']]) ?>
<div class="main-content" id="connections-content">
    <div class="alert alert-<?= $unnamed > 0 ? 'warning' : 'light border' ?> fs-12" role="status" id="connections-k26">
        <?php if ($unnamed > 0): ?>
            <strong><?= (int) $unnamed ?> of the last <?= count($calls) ?> share calls did not say which application was asking.</strong> The kernel names the consumer in the header <code>X-OS-Consumer</code> (K26); a call without it shares nothing.
        <?php else: ?>
            The kernel names the asking application in the header <code>X-OS-Consumer</code> (K26); a call without it shares nothing. A base is read by an application only when it is named on the base's Share screen <em>and</em> a super-admin approved the connection in the OS.
        <?php endif; ?>
        <?php if ($os_url !== null): ?>Approving a connection between applications is a super-admin's decision in the OS — <a href="<?= e($os_url) ?>" class="alert-link" id="connections-os-link">open the OS's applications</a>.<?php else: ?>Approving a connection between applications is a super-admin's decision in the OS.<?php endif; ?>
    </div>

    <div class="card mb-3" id="connections-shares"><div class="card-header"><h5 class="card-title mb-0">What Knowledge shares</h5></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12">
            <thead><tr><th>Tool</th><th>Answers</th><th>Document</th></tr></thead>
            <tbody><?php foreach ($shares as $s): ?><tr id="share-<?= e($s['tool']) ?>"><td class="text-nowrap"><code><?= e($s['tool']) ?></code></td><td><?= e($s['description']) ?></td>
                <td class="text-nowrap"><code><?= e((string) $s['document']) ?></code><?= $s['timeout_seconds'] ? ' <span class="text-muted">· ' . (int) $s['timeout_seconds'] . ' s</span>' : '' ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>

    <div class="card mb-3" id="connections-principals"><div class="card-header"><h5 class="card-title mb-0">Applications named on bases</h5></div>
        <div class="card-body fs-12">
        <?php if ($principals === []): ?><span class="text-muted" id="connections-principals-none">No application is named on a base. Share a base with one on its Share screen.</span><?php endif; ?>
        <?php foreach ($principals as $p): ?>
            <div class="mb-3" id="principal-<?= e($p['application']) ?>"><div class="fw-semibold"><i class="feather-share-2 me-1"></i><?= e($p['application']) ?></div>
                <ul class="list-unstyled mb-0"><?php foreach ($p['bases'] as $b): ?><li><?= hx_link('/bases/' . (int) $b['base_id'] . '/share', e($b['name']), 'kn-row-link') ?> <span class="text-muted">· <?= e($b['level']) ?> since <?= e(format_ts($b['since'], $tz, 'M j, Y')) ?><?= $b['archived'] ? ' · archived' : '' ?></span></li><?php endforeach; ?></ul></div>
        <?php endforeach; ?>
        </div></div>

    <div class="card mb-3" id="connections-reads"><div class="card-header"><h5 class="card-title mb-0">What Knowledge reads from other applications</h5></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12">
            <thead><tr><th>Application</th><th>Tool</th><th>Why</th><th>Feeds</th></tr></thead>
            <tbody><?php foreach ($reads as $r): $f = $feeds[$r['app'] . '/' . $r['tool']] ?? null; ?>
                <tr id="read-<?= e($r['app'] . '-' . $r['tool']) ?>"><td><?= e($r['app']) ?></td><td><code><?= e($r['tool']) ?></code></td><td><?= e($r['why']) ?></td>
                    <td class="text-nowrap"><?= $f === null ? '<span class="text-muted">no feed uses it</span>' : (int) $f['feeds'] . ' · ' . e((string) ($f['last_status'] ?? 'not synced yet')) ?></td></tr>
            <?php endforeach; ?></tbody></table></div></div></div>

    <div class="card mb-3" id="share-reads"><div class="card-header"><h5 class="card-title mb-0">The last share calls</h5></div>
        <div class="card-body p-0">
        <?php if ($calls === []): ?><div class="p-3 text-muted fs-12" id="share-reads-none">No application has read a base yet.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="share-reads-table">
            <thead><tr><th>When</th><th>Application</th><th>Tool</th><th>Base</th><th>Outcome</th><th class="text-end">ms</th></tr></thead>
            <tbody><?php foreach ($calls as $c): ?><?= view('admin/partials/share-read-row.php', ['c' => $c, 'tz' => $tz]) ?><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        </div></div>
</div>
