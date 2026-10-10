<?php /** One turn (ask-turn): the question, the answer card by status, its citations, the evidence, the expert, the controls. Data: ask_turn_data() — q, qid, citations, evidence, rating, pinned, dispatch, expert, may, expert_named, in_window, area */
$status = (string) $q['status'];
$here = $in_window ? '/ask?base=' . (int) $q['base_id'] . '&c=' . rawurlencode((string) $q['conversation_id']) : '/questions/' . $qid; ?>
<div class="kn-turn mb-4" id="q-<?= $qid ?>" data-status="<?= e($status) ?>">
    <div class="d-flex justify-content-end mb-2"><div class="kn-bubble" id="q-<?= $qid ?>-question"><?= nl2br(e((string) $q['question'])) ?><?php if ($area !== null): ?><div class="kn-bubble-area fs-11 mt-1"><i class="feather-target me-1"></i><?= e((string) $area) ?></div><?php endif; ?></div></div>
    <div class="card kn-answer mb-0" id="answer-<?= $qid ?>">
        <div class="card-body p-3">
            <?= view('ask/partials/answer.php', ['q' => $q, 'qid' => $qid, 'may' => $may, 'in_window' => $in_window]) ?>
            <?= view('ask/partials/citations.php', ['q' => $q, 'qid' => $qid, 'citations' => $citations, 'here' => $here]) ?>
            <?php if ($evidence !== [] && in_array($status, ['answered', 'unverified', 'evidence_only', 'no_evidence'], true)): ?>
                <?= view('ask/partials/evidence.php', ['qid' => $qid, 'evidence' => $evidence, 'open' => in_array($status, ['unverified', 'evidence_only'], true), 'here' => $here]) ?>
            <?php endif; ?>
            <?php if ($status === 'evidence_only'): ?>
                <?= view('ask/partials/expert.php', ['qid' => $qid, 'dispatch' => $dispatch, 'expert' => $expert, 'may' => $may['expert'], 'expert_named' => $expert_named, 'in_window' => $in_window]) ?>
            <?php endif; ?>
        </div>
        <div class="card-footer p-2 d-flex flex-wrap align-items-start gap-2" id="controls-<?= $qid ?>">
            <?php if ($may['rate'] && !in_array($status, ['refused'], true)): ?><?= view('ask/partials/feedback.php', ['qid' => $qid, 'rating' => $rating, 'in_window' => $in_window]) ?><?php endif; ?>
            <?php if ($pinned): ?><span class="fs-12 text-success align-self-center" id="pinned-<?= $qid ?>"><i class="feather-bookmark me-1"></i>Pinned — becomes a source at the next build</span>
            <?php elseif ($may['pin']): ?><?= view('ask/partials/pin-form.php', ['q' => $q, 'qid' => $qid, 'in_window' => $in_window]) ?><?php endif; ?>
            <?= hx_link('/questions/' . $qid . ($in_window ? '?back=' . rawurlencode($here) : ''), '<i class="feather-external-link me-1"></i>Open', 'btn btn-light btn-touch ms-auto', 'id="open-' . $qid . '"') ?>
        </div>
    </div>
</div>
