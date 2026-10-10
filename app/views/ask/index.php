<?php /** The window (screen `ask`). Data: bases, base (mcp_bases row|null), areas, area (row|null), turns (ask_turn_data rows), c, prefill, suggestions, limit, built, curate, picking, notice */
$bid = $base !== null ? (int) $base['base_id'] : 0;
$url = static fn (array $p): string => '/ask?' . http_build_query(array_filter($p, static fn ($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986); ?>
<?= view('shared/header.php', ['id' => 'ask', 'title' => 'Ask', 'crumbs' => [['Home', '/'], ['Ask', null]], 'back' => back_link(),
    'action' => hx_link('/ask/conversations' . ($bid ? '?base=' . $bid : ''), '<i class="feather-message-square me-1"></i>Conversations', 'btn btn-light btn-touch', 'id="ask-conversations-link"')]) ?>
<div class="main-content kn-ask" id="ask-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($base === null || $picking): ?>
        <div class="fs-13 text-muted mb-2">Which base do you want to ask?</div>
        <?php if ($bases === []): ?><div class="card"><div class="card-body text-muted" id="ask-no-bases">No knowledge base is shared with you yet.</div></div><?php endif; ?>
        <div class="row g-3" id="ask-base-list">
            <?php foreach ($bases as $x): ?>
                <div class="col-12 col-md-6 col-xl-4"><a class="card h-100 text-reset kn-base-pick" href="<?= e($url(['base' => (int) $x['base_id']])) ?>" id="ask-pick-<?= (int) $x['base_id'] ?>">
                    <div class="card-body"><div class="fw-semibold"><?= e((string) $x['name']) ?></div>
                        <div class="fs-12 text-muted"><?= (int) $x['built_count'] > 0 ? (int) $x['built_count'] . ' sections to ask' : 'Nothing to ask yet' ?></div></div></a></div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="d-flex align-items-center gap-2 mb-2">
            <a class="btn btn-light btn-touch fw-semibold text-truncate kn-base-name" href="<?= e($url(['pick' => 1, 'base' => $bid])) ?>" id="ask-base-picker" title="Change the base"><?= e((string) $base['name']) ?> <i class="feather-chevron-down ms-1"></i></a>
            <?php if ($turns !== []): ?><?= hx_link($url(['base' => $bid, 'area' => $area['slug'] ?? null]), '<i class="feather-plus me-1"></i>New conversation', 'btn btn-light btn-touch ms-auto', 'id="ask-new-conversation"') ?><?php endif; ?>
        </div>
        <?php if ($areas !== []): ?>
            <nav class="kn-chips mb-3" id="ask-areas" aria-label="Focus areas">
                <a class="btn btn-touch <?= $area === null ? 'btn-primary' : 'btn-light' ?>" href="<?= e($url(['base' => $bid, 'c' => $c])) ?>" id="ask-area-all">Whole base</a>
                <?php foreach ($areas as $a): ?>
                    <a class="btn btn-touch <?= $area !== null && (int) $area['focus_area_id'] === (int) $a['focus_area_id'] ? 'btn-primary' : 'btn-light' ?>" href="<?= e($url(['base' => $bid, 'area' => $a['slug'], 'c' => $c])) ?>" id="ask-area-<?= e((string) $a['slug']) ?>"><?= e((string) $a['name']) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <div id="ask-turns" class="kn-turns" aria-live="polite">
            <?php foreach ($turns as $t): ?><?= view('ask/partials/turn.php', $t) ?><?php endforeach; ?>
        </div>
        <?php if (!$built): ?>
            <div class="card" id="ask-not-built"><div class="card-body">
                <div class="fw-semibold mb-1">Nothing in this base can be asked yet.</div>
                <?php if ($curate): ?><?= hx_link('/sources/new?base=' . $bid, '<i class="feather-upload me-1"></i>Add documents', 'btn btn-primary btn-touch', 'id="ask-add-documents"') ?>
                <?php else: ?><div class="fs-12 text-muted">Its curators have not added documents yet.</div><?php endif; ?>
            </div></div>
        <?php else: ?>
            <?php if ($turns === []): ?><?= view('ask/partials/suggestions.php', ['base_id' => $bid, 'area' => $area, 'suggestions' => $suggestions]) ?><?php endif; ?>
            <?= view('ask/partials/composer.php', ['base_id' => $bid, 'area' => $area, 'c' => $c, 'prefill' => $prefill, 'limit' => $limit]) ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
