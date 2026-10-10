<?php /** Ask the expert (evidence_only): the button, the thinking row that polls, the reply. Data: qid, dispatch (mcp_agent_dispatches row|null), expert (status, reply, detail, note)|null, may, expert_named, in_window */
$st = $dispatch['status'] ?? null; ?>
<div class="kn-expert mt-3" id="expert-<?= $qid ?>"<?= $st === 'sent' ? ' hx-get="/questions/' . $qid . '/expert-status?dispatch=' . (int) $dispatch['dispatch_id'] . ($in_window ? '&window=1' : '') . '" hx-trigger="every 3s [document.visibilityState==\'visible\']" hx-swap="outerHTML"' : '' ?> aria-live="polite">
    <?php if ($st === 'sent'): ?>
        <div class="d-flex align-items-center gap-2 fs-13 text-muted" id="expert-thinking-<?= $qid ?>"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span><?= e((string) ($dispatch['agent_name'] ?? 'The Knowledge Expert')) ?> is reading the passages…</div>
    <?php elseif ($st === 'answered'): ?>
        <div class="border-start border-3 ps-3" id="expert-reply-<?= $qid ?>">
            <div class="fs-12 mb-1"><span class="fw-semibold"><?= e((string) $dispatch['agent_name']) ?></span> <?= chip(['agent', 'light']) ?></div>
            <div class="kn-md"><?= kn_markdown_html((string) ($expert['reply'] ?? ''), null) ?></div>
            <?php if (!empty($expert['note'])): ?><div class="fs-11 text-muted"><?= e((string) $expert['note']) ?></div><?php endif; ?>
            <div class="fs-11 text-muted mt-1">The expert's reply is not checked against the sources the way an answer is.</div>
        </div>
    <?php elseif ($st === 'awaiting_approval'): ?>
        <div class="alert alert-warning py-2 fs-13 m-0" id="expert-waiting-<?= $qid ?>">The expert's turn is waiting for a person's approval.</div>
    <?php elseif ($st === 'refused' || $st === 'failed'): ?>
        <div class="alert alert-<?= $st === 'refused' ? 'dark' : 'danger' ?> py-2 fs-13 mb-2" id="expert-detail-<?= $qid ?>"><?= e((string) ($dispatch['detail'] ?? 'The expert could not answer.')) ?></div>
    <?php endif; ?>
    <?php if (($st === null || $st === 'failed') && $may): ?>
        <?php if ($expert_named): ?>
            <form method="post" action="/questions/expert.php" <?= $in_window ? 'hx-post="/questions/expert.php" hx-target="#q-' . $qid . '" hx-swap="outerHTML"' : 'hx-post="/questions/expert.php" hx-target="#flash"' ?> class="m-0">
                <?= csrf_field() ?><input type="hidden" name="question" value="<?= $qid ?>"><input type="hidden" name="partial" value="turn">
                <button type="submit" class="btn btn-primary btn-touch" id="ask-expert-<?= $qid ?>"><i class="feather-user-check me-1"></i>Ask the expert</button>
            </form>
        <?php else: ?><div class="fs-12 text-muted" id="expert-unnamed-<?= $qid ?>">No expert is named yet — an admin names it under Admin › Agents.</div><?php endif; ?>
    <?php endif; ?>
</div>
