<?php /** One question (screen `question-view`). Data: q, b, t (ask_turn_data), feedback, curate, tz, notice */
$qid = (int) $q['question_id']; $bid = (int) $q['base_id'];
$usage = json_decode((string) $q['usage'], true) ?: [];
$door = match ((string) $q['door']) { 'api' => '<i class="feather-key me-1"></i>' . e((string) ($q['key_label'] ?? 'a key')), 'share' => '<i class="feather-share-2 me-1"></i>' . e((string) $q['application_key']),
    'mcp' => '<i class="feather-cpu me-1"></i>MCP', 'agent' => chip(['agent', 'light']), default => 'the window' }; ?>
<?= view('shared/header.php', ['id' => 'question-view', 'title' => 'Question', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $bid], ['Question', null]],
    'back' => back_link() ?? ($curate ? ['/bases/' . $bid . '/questions', 'the questions'] : ['/ask?base=' . $bid . '&c=' . rawurlencode((string) $q['conversation_id']), 'the conversation'])]) ?>
<div class="main-content" id="question-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 fs-12" id="question-facts">
        <?= chip(question_chip((string) $q['status']), 'question-status') ?>
        <span><?= e((string) ($q['asker_name'] ?? '—')) ?><?= !empty($q['asker_is_agent']) ? ' ' . chip(['agent', 'light']) : '' ?></span>
        <span class="text-muted">· <?= $door ?> · <?= e(format_ts($q['created_at'], $tz, 'M j, Y g:i A')) ?> · guidelines version <?= (int) $q['guideline_version'] ?></span>
        <?php if ($curate): ?><span class="text-muted" id="question-model">· <?= e((string) ($q['model'] ?? 'no model')) ?> · <?= number_format((int) $q['latency_ms']) ?> ms<?= !empty($usage['dropped']) ? ' · ' . (int) $usage['dropped'] . ' passages of another base or a removed section dropped' : '' ?><?= !empty($usage['system_truncated']) ? ' · the guidelines sent were cut to fit' : '' ?></span><?php endif; ?>
    </div>
    <?= view('ask/partials/turn.php', $t) ?>
    <?php if ($feedback !== []): ?>
        <div class="card mt-3" id="question-feedback"><div class="card-header"><h5 class="card-title mb-0">Feedback</h5></div><div class="list-group list-group-flush">
            <?php foreach ($feedback as $f): ?>
                <div class="list-group-item fs-13" id="feedback-<?= (int) $f['feedback_id'] ?>"><i class="feather-thumbs-<?= $f['rating'] === 'up' ? 'up text-success' : 'down text-danger' ?> me-1"></i><?= e((string) $f['member_name']) ?>
                    <span class="text-muted fs-11">· <?= e(format_ts($f['updated_at'], $tz, 'M j, g:i A')) ?></span><?php if ((string) $f['comment'] !== ''): ?><div class="text-muted"><?= nl2br(e((string) $f['comment'])) ?></div><?php endif; ?></div>
            <?php endforeach; ?>
        </div></div>
    <?php endif; ?>
</div>
