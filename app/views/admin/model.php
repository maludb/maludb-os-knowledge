<?php /** The engine's model (screen `admin-model`; model.manage). Data: st (model_state), base_id, base_label, sections, section_id, test, saved (the last save's sends, once), notice */
$conn = $st['connection']; $cfg = $conn['config']; $ns = $st['namespace']; ?>
<?= view('shared/header.php', ['id' => 'admin-model', 'title' => 'Model', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Model', null]], 'back' => back_link() ?? ['/admin/', 'Admin']]) ?>
<div class="main-content" id="admin-model-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($saved !== null): ?>
        <div class="alert alert-<?= $saved['failed'] === [] ? 'success' : 'warning' ?> mb-3" id="model-saved">Sent to <?= (int) $saved['sent'] ?> base<?= (int) $saved['sent'] === 1 ? '' : 's' ?>.
            <?= (int) $saved['marked'] > 0 ? 'Every base needs a rebuild now — a curator starts it on the base.' : '' ?>
            <?php foreach ($saved['failed'] as $bid => $f): ?><div class="fs-12" id="model-failed-<?= (int) $bid ?>"><?= e($f['name']) ?>: <?= e($f['words']) ?> It is sent again before that base's next build.</div><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-12 col-xl-6">
            <div class="card mb-3" id="model-connection"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">The engine's connection</h5>
                <?= chip($st['health']['ok'] ? ['engine up', 'success'] : ['engine down', 'danger'], 'model-health') ?></div>
                <div class="card-body fs-12">
                <?php if (!$st['health']['ok']): ?><div class="alert alert-danger py-2" id="model-health-words"><?= e($st['health']['message']) ?></div><?php endif; ?>
                <?php if (!$conn['ok']): ?>
                    <div class="alert alert-warning py-2 mb-0" id="model-not-configured"><?= e((string) $conn['error']) ?></div>
                <?php else: ?>
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted fw-normal">Provider</dt><dd class="col-7" id="model-provider"><?= e((string) $cfg['provider_name']) ?> (<?= e((string) $cfg['provider_kind']) ?>)</dd>
                        <dt class="col-5 text-muted fw-normal">Model</dt><dd class="col-7 text-break" id="model-identifier"><?= e((string) $cfg['model_identifier']) ?></dd>
                        <dt class="col-5 text-muted fw-normal">Embedding model</dt><dd class="col-7 text-break" id="model-embedding"><?= e((string) $cfg['embedding_model']) ?></dd>
                        <dt class="col-5 text-muted fw-normal">Base URL</dt><dd class="col-7 text-break" id="model-base-url"><?= e((string) $cfg['base_url']) ?></dd>
                        <dt class="col-5 text-muted fw-normal">Secret</dt><dd class="col-7 text-break" id="model-secret"><?= e((string) $cfg['secret_name']) ?> <span class="text-muted">(the name — the value is never shown)</span></dd>
                    </dl>
                <?php endif; ?>
                <div class="mt-2 text-muted" id="model-recorded">Last chosen here: <?= ($st['recorded']['model_alias'] ?? '') !== '' ? e((string) $st['recorded']['model_alias']) : 'nothing yet' ?><?= ($st['recorded']['embedding_model'] ?? '') !== '' ? ' · embeddings ' . e((string) $st['recorded']['embedding_model']) : '' ?></div>
            </div></div>
            <form method="post" action="/admin/model.php" hx-post="/admin/model.php" hx-target="#flash" hx-confirm="Change the model? Every base is sent the new connection." class="card mb-3" id="model-form">
                <div class="card-header"><h5 class="card-title mb-0">Change the model</h5></div>
                <div class="card-body">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label fs-12 text-muted" for="model-field-model_alias">Model id</label>
                        <input type="text" name="model_alias" id="model-field-model_alias" class="form-control btn-touch" maxlength="200" value="<?= e((string) ($cfg['model_identifier'] ?? '')) ?>" autocapitalize="none" spellcheck="false"></div>
                    <div class="mb-2"><label class="form-label fs-12 text-muted" for="model-field-embedding_model">Embedding model</label>
                        <input type="text" name="embedding_model" id="model-field-embedding_model" class="form-control btn-touch" maxlength="200" value="<?= e((string) ($cfg['embedding_model'] ?? '')) ?>" autocapitalize="none" spellcheck="false"></div>
                    <input type="hidden" name="rebuild_confirmed" value="no">
                    <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-2" for="model-field-rebuild_confirmed"><input type="checkbox" class="form-check-input mt-0" name="rebuild_confirmed" value="yes" id="model-field-rebuild_confirmed">Every base must be rebuilt — the stored passages were embedded with the old model</label>
                    <div class="fs-11 text-muted mb-3">Tick the box only when you change the embedding model. The model must be allowed on Knowledge's K24 credential and priced in the OS's model registry.</div>
                    <button type="submit" class="btn btn-primary btn-touch w-100" id="model-save-btn"<?= $conn['ok'] ? '' : ' disabled' ?>>Save the model</button>
                </div>
            </form>
        </div>
        <div class="col-12 col-xl-6">
            <form method="get" action="/admin/model" class="card mb-3" id="model-test-form">
                <div class="card-header"><h5 class="card-title mb-0">A base's namespace, and a test</h5></div>
                <div class="card-body">
                    <label class="form-label fs-12 text-muted" id="model-base-label">Base</label>
                    <div class="mb-3"><?= picker_field(['id' => 'model-base', 'name' => 'base', 'source' => 'base', 'value' => $base_id, 'label' => $base_label, 'labelledby' => 'model-base-label', 'placeholder' => 'Choose a base', 'title' => 'A knowledge base']) ?></div>
                    <?php if ($base_id !== null): ?>
                        <div class="mb-3"><label class="form-label fs-12 text-muted" for="model-section">Section to try</label>
                            <?php if ($sections === []): ?><div class="fs-12 text-muted" id="model-no-sections">This base has no section yet — add documents first.</div><?php else: ?>
                            <select name="section" id="model-section" class="form-select btn-touch"><?php foreach ($sections as $x): ?><option value="<?= (int) $x['section_id'] ?>" <?= $section_id === (int) $x['section_id'] ? 'selected' : '' ?>><?= e(mb_strimwidth((string) $x['source_title'] . ' › ' . (string) $x['heading_path'], 0, 90, '…')) ?></option><?php endforeach; ?></select>
                            <?php endif; ?></div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-light btn-touch w-100" id="model-test-btn"><?= $base_id === null ? 'Show the namespace' : 'Show the namespace and test' ?></button>
                </div>
            </form>
            <?php if ($ns !== null): ?>
            <div class="card mb-3" id="model-namespace"><div class="card-header"><h5 class="card-title mb-0">Namespace kb:<?= (int) $ns['base_id'] ?></h5></div><div class="card-body fs-12">
                <?php if (!$ns['ok']): ?><div class="alert alert-warning py-2 mb-0" id="model-namespace-error"><?= e((string) $ns['error']) ?></div>
                <?php elseif ($ns['config'] === null): ?><div class="text-muted" id="model-namespace-none">Nothing is configured for this base yet. It is sent the connection before its first build.</div>
                <?php else: $c = $ns['config']; ?>
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted fw-normal">Model</dt><dd class="col-7 text-break" id="model-ns-identifier"><?= e((string) $c['model_identifier']) ?></dd>
                        <dt class="col-5 text-muted fw-normal">Embedding model</dt><dd class="col-7 text-break"><?= e((string) $c['embedding_model']) ?></dd>
                        <dt class="col-5 text-muted fw-normal">Secret</dt><dd class="col-7 text-break"><?= e((string) $c['secret_name']) ?></dd>
                        <dt class="col-5 text-muted fw-normal">Prompt</dt><dd class="col-7 text-break"><?= $c['prompt_chars'] === null ? 'none' : number_format($c['prompt_chars']) . ' characters · ' . e((string) $c['prompt_sha256']) ?></dd>
                    </dl>
                <?php endif; ?>
            </div></div>
            <?php endif; ?>
            <?php if ($test !== null): ?>
            <div class="card mb-3" id="model-test"><div class="card-header"><h5 class="card-title mb-0">The prompt for “<?= e((string) $test['heading_path']) ?>”</h5></div><div class="card-body">
                <div class="fs-11 text-muted mb-2" id="model-test-note">This shows the prompt the model would be sent — MaluDB cannot yet run a dry extraction (M5).</div>
                <pre class="kn-preview-pre" id="model-test-output"><?= e($test['text']) ?></pre>
            </div></div>
            <?php endif; ?>
        </div>
    </div>
</div>
