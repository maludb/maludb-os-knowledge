<?php /** The answer card's body by status (ask_status_words() are the words). Data: q, qid, may, in_window */
$status = (string) $q['status']; $err = $q['error'] ?? null; $bid = (int) $q['base_id'];
$repost = static function (string $label, string $id, bool $withArea, string $cls) use ($q, $bid): string {
    return '<form method="post" action="/ask/send.php" hx-post="/ask/send.php" hx-target="#ask-turns" hx-swap="beforeend" class="d-inline m-0">' . csrf_field()
        . '<input type="hidden" name="base" value="' . $bid . '"><input type="hidden" name="question" value="' . e((string) $q['question']) . '">'
        . '<input type="hidden" name="c" value="' . e((string) $q['conversation_id']) . '" class="kn-c-field">'
        . ($withArea && $q['focus_area_id'] !== null ? '<input type="hidden" name="area" value="' . (int) $q['focus_area_id'] . '">' : '')
        . '<button type="submit" class="btn ' . $cls . ' btn-touch" id="' . e($id) . '">' . e($label) . '</button></form>';
}; ?>
<?php if ($status === 'answered'): ?>
    <div class="kn-md" id="answer-text-<?= $qid ?>"><?= kn_markdown_html((string) $q['answer_md'], $qid) ?></div>
<?php elseif ($status === 'evidence_only'): ?>
    <div class="alert alert-info py-2 fs-13 mb-2" id="answer-note-<?= $qid ?>"><?= e((string) ask_status_words($status, $err)) ?></div>
<?php elseif ($status === 'no_evidence'): ?>
    <div class="text-muted mb-2" id="answer-note-<?= $qid ?>"><div class="fw-semibold">This knowledge base holds nothing on this.</div><div class="fs-12">It has been noted so a curator can add a source.</div></div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($in_window && $q['focus_area_id'] !== null): ?><?= $repost('Ask the whole base', 'ask-whole-' . $qid, false, 'btn-light') ?><?php endif; ?>
        <?php if ($may['curate']): ?><?= hx_link('/sources/new?base=' . $bid, '<i class="feather-plus me-1"></i>Add a source', 'btn btn-light btn-touch', 'id="add-source-' . $qid . '"') ?><?php endif; ?>
    </div>
<?php elseif ($status === 'unverified'): ?>
    <div class="alert alert-warning py-2 fs-13 mb-2" id="answer-note-<?= $qid ?>"><?= e((string) ask_status_words($status, $err)) ?></div>
<?php elseif ($status === 'refused'): ?>
    <div class="alert alert-dark py-2 fs-13 mb-2" id="answer-note-<?= $qid ?>"><?= e((string) ask_status_words($status, $err)) ?></div>
    <?php if ($err === 'base_not_built' && $may['curate']): ?><?= hx_link('/sources/new?base=' . $bid, '<i class="feather-upload me-1"></i>Add documents', 'btn btn-primary btn-touch', 'id="add-docs-' . $qid . '"') ?><?php endif; ?>
<?php else: ?>
    <div class="alert alert-danger py-2 fs-13 mb-2" id="answer-note-<?= $qid ?>"><?= e((string) ask_status_words('failed', $err)) ?></div>
    <?php if ($in_window): ?><?= $repost('Try again', 'try-again-' . $qid, true, 'btn-light') ?><?php endif; ?>
<?php endif; ?>
