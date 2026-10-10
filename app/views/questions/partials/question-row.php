<?php /** A row of the base's log (a table row at 1280, a card at 375 — both rendered, CSS shows one). Data: r, tz, card (bool) */
$qid = (int) $r['question_id'];
$who = $r['key_label'] !== null ? '<i class="feather-key me-1"></i>' . e((string) $r['key_label']) : ($r['application_key'] !== null ? '<i class="feather-share-2 me-1"></i>' . e((string) $r['application_key']) : e((string) ($r['asker_name'] ?? '—')) . ($r['asker_is_agent'] ? ' ' . chip(['agent', 'light']) : ''));
$first = mb_substr(trim((string) strtok((string) $r['question'], "\n")), 0, 160);
$cites = (int) $r['verified_citations'] . ' of ' . (int) $r['citation_count'];
$thumbs = ((int) $r['thumbs_up'] ? '<i class="feather-thumbs-up text-success"></i>' . (int) $r['thumbs_up'] . ' ' : '') . ((int) $r['thumbs_down'] ? '<i class="feather-thumbs-down text-danger"></i>' . (int) $r['thumbs_down'] : '');
if (!empty($card)): ?>
    <a class="card mb-2 text-reset" href="/questions/<?= $qid ?>" id="question-card-<?= $qid ?>"><div class="card-body p-3">
        <div class="d-flex justify-content-between gap-2 fs-12 mb-1"><span class="text-muted"><?= e(format_ts($r['created_at'], $tz, 'M j, g:i A')) ?> · <?= $who ?></span><?= chip(question_chip((string) $r['status'])) ?></div>
        <div class="fw-semibold"><?= e($first) ?></div>
        <div class="fs-12 text-muted mt-1"><?= $cites ?> citations verified <?= $thumbs ?><?= $r['pinned'] ? ' · <i class="feather-bookmark"></i> pinned' : '' ?></div>
    </div></a>
<?php else: ?>
    <tr id="question-row-<?= $qid ?>">
        <td class="fs-12 text-nowrap"><?= hx_link('/questions/' . $qid, e(format_ts($r['created_at'], $tz, 'M j, g:i A'))) ?></td>
        <td class="fs-12"><?= $who ?></td>
        <td class="fs-12"><?= e((string) ($r['area_name'] ?? '')) ?></td>
        <td class="fs-12"><?= hx_link('/questions/' . $qid, e($first)) ?></td>
        <td class="text-nowrap"><?= chip(question_chip((string) $r['status'])) ?></td>
        <td class="fs-12 text-nowrap"><?= $cites ?></td>
        <td class="fs-12 text-nowrap"><?= $thumbs ?></td>
        <td class="fs-12"><?= $r['pinned'] ? '<i class="feather-bookmark" aria-label="pinned"></i>' : '' ?></td>
    </tr>
<?php endif; ?>
