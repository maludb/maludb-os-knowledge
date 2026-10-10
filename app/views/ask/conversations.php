<?php /** My conversations (screen `conversations`). Data: rows, base (row|null), tz */ ?>
<?= view('shared/header.php', ['id' => 'conversations', 'title' => 'Conversations', 'crumbs' => [['Home', '/'], ['Ask', '/ask' . ($base !== null ? '?base=' . (int) $base['base_id'] : '')], ['Conversations', null]], 'back' => back_link()]) ?>
<div class="main-content" id="conversations-content">
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="conversations-empty">No conversation yet<?= $base !== null ? ' with ' . e((string) $base['name']) : '' ?>.</div></div><?php endif; ?>
    <div class="row g-3" id="conversations-list">
        <?php foreach ($rows as $r): ?>
            <div class="col-12 col-lg-6"><a class="card h-100 text-reset" id="conversation-<?= e((string) $r['conversation_id']) ?>" href="<?= e('/ask?base=' . (int) $r['base_id'] . '&c=' . rawurlencode((string) $r['conversation_id'])) ?>">
                <div class="card-body">
                    <div class="fs-12 text-muted mb-1"><?= e((string) $r['base_name']) ?> · <?= (int) $r['turns'] ?> turn<?= (int) $r['turns'] === 1 ? '' : 's' ?> · <?= e(format_ts($r['last_at'], $tz, 'M j, g:i A')) ?></div>
                    <div class="fw-semibold text-truncate"><?= e(strtok((string) $r['first_question'], "\n") ?: '') ?></div>
                </div></a></div>
        <?php endforeach; ?>
    </div>
</div>
