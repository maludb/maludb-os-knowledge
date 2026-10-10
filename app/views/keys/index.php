<?php /** The base's API keys (screen `keys`). Data: b, keys, may, tabs, tz, minted (the copy-once value, once), notice, defaults */
$id = (int) $b['base_id'];
$curl = "curl -X POST " . key_api_url('/api/v1/ask') . " \\\n  -H 'Authorization: Bearer KEY' -H 'Content-Type: application/json' \\\n  -d '{\"question\": \"How does carry-over work?\"}'"; ?>
<?= view('shared/header.php', ['id' => 'keys', 'title' => 'Keys', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Keys', null]], 'back' => back_link(),
    'action' => $may['mint'] ? hx_link('/keys/new?base=' . $id, '<i class="feather-plus me-1"></i>Mint a key', 'btn btn-primary btn-touch', 'id="keys-mint-btn"') : '']) ?>
<div class="main-content" id="keys-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'keys']) ?>
    <?php if ($minted !== null): ?><?= view('keys/partials/key-minted.php', ['minted' => $minted, 'tz' => $tz]) ?><?php endif; ?>
    <div class="card mb-3" id="keys-api"><div class="card-body fs-12">
        <p class="mb-2">A key lets an outside system ask <strong><?= e($b['name']) ?></strong> — and this base only — over the API. It gets exactly what the ask window gets: an answer whose every claim cites its section, or a plain "nothing on this". A key is shown once, when it is minted.</p>
        <div class="mb-1"><span class="text-muted">Ask:</span> <code class="text-break" id="keys-api-url"><?= e(key_api_url('/api/v1/ask')) ?></code></div>
        <pre class="kn-preview-pre mb-0" id="keys-api-curl"><?= e($curl) ?></pre>
    </div></div>
    <?php if ($keys === []): ?>
        <div class="card"><div class="card-body text-muted" id="keys-none">No key yet. Mint one for the system that will ask this base.</div></div>
    <?php else: ?>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table table-hover mb-0" id="keys-table">
            <thead><tr><th>Key</th><th>Area</th><th>Per minute</th><th>Per day</th><th>Today</th><th>Last used</th><th>Status</th><th>Minted</th><th></th></tr></thead>
            <tbody><?php foreach ($keys as $k): ?><?= view('keys/partials/key-row.php', ['k' => $k, 'may' => $may, 'tz' => $tz]) ?><?php endforeach; ?></tbody></table></div></div>
        <div class="d-md-none" id="keys-cards"><?php foreach ($keys as $k): ?><?= view('keys/partials/key-row.php', ['k' => $k, 'may' => $may, 'tz' => $tz, 'card' => true]) ?><?php endforeach; ?></div>
    <?php endif; ?>
</div>
