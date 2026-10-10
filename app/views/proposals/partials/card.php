<?php /** One proposal (screens `proposals`, `admin-proposals`). Data: p, may (decide), est (the build estimate of its base, or null), tz, uniq (ids carry the proposal's number: the admin's list), show_base */
$pid = (int) $p['proposal_id'];
$bid = (int) $p['base_id'];
$pl = $p['payload'];
$kindChip = PROPOSAL_KIND_CHIPS[$p['kind']] ?? [$p['kind'], 'feather-help-circle'];
$open = $p['status'] === 'proposed';
$decide = $open && !empty($may['decide']);
$subject = $p['subject'] ?? ['label' => '', 'href' => null]; ?>
<div class="card h-100 mb-0<?= $open ? ' border-primary' : '' ?>" id="proposal-<?= $pid ?>"><div class="card-body d-flex flex-column">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="badge bg-light text-dark border" id="proposal-<?= $pid ?>-kind"><i class="<?= e($kindChip[1]) ?> me-1"></i><?= e($kindChip[0]) ?></span>
        <?= chip(proposal_status_chip($p['status']), 'proposal-' . $pid . '-status') ?>
        <span class="ms-auto fs-11 text-muted"><?= e(format_ts($p['created_at'], $tz, 'M j, g:i A')) ?></span>
    </div>
    <h6 class="fw-semibold text-break mb-1" id="proposal-<?= $pid ?>-title"><?= e($p['title']) ?></h6>
    <div class="fs-12 kn-prewrap text-break mb-2" id="proposal-<?= $pid ?>-reason"><?= e($p['reason']) ?></div>
    <div class="fs-12 mb-1"><?php if (!empty($show_base)): ?><?= hx_link('/bases/' . $bid . '/proposals', e((string) $p['base_name']), 'fw-semibold', 'id="proposal-' . $pid . '-base"') ?> · <?php endif; ?>
        <span class="text-muted">About</span> <?= $subject['href'] !== null ? hx_link($subject['href'], e($subject['label']), 'kn-row-link text-break', 'id="proposal-' . $pid . '-subject"') : '<span class="text-break" id="proposal-' . $pid . '-subject">' . e($subject['label']) . '</span>' ?></div>
    <div class="fs-11 text-muted mb-2">Proposed by <?= e((string) ($p['proposed_by_name'] ?? 'an agent')) ?> <?= chip(['agent', 'info']) ?></div>

    <?php // the payload, in words, by kind ?>
    <div class="fs-12 mb-2" id="proposal-<?= $pid ?>-payload">
    <?php switch ($p['kind']):
        case 'gap': ?>
            <?php if (($pl['reason'] ?? '') === 'thumbs_down'): ?>
                <span class="text-muted">A thumbs-down answer<?= !empty($pl['has_comment']) ? ' with a comment' : '' ?>.</span>
            <?php else: ?>
                <?php if (!empty($pl['sample'])): ?><div class="fst-italic text-break mb-1">“<?= e(mb_substr((string) $pl['sample'], 0, 120)) ?>”</div><?php endif; ?>
                <div class="text-muted"><?= (int) ($pl['question_count'] ?? count((array) ($pl['question_ids'] ?? []))) ?> question<?= (int) ($pl['question_count'] ?? 0) === 1 ? '' : 's' ?> asked about this and found nothing.</div>
                <?php if (!empty($pl['question_ids'])): ?><div class="d-flex flex-wrap gap-1 mt-1"><?php foreach (array_slice((array) $pl['question_ids'], 0, 12) as $qid): ?><?= hx_link('/questions/' . (int) $qid, '#' . (int) $qid, 'badge bg-soft-secondary text-secondary', 'id="proposal-' . $pid . '-q' . (int) $qid . '"') ?><?php endforeach; ?></div><?php endif; ?>
            <?php endif; ?>
        <?php break;
        case 'failed_source': ?>
            <span class="text-muted"><?= ($pl['reason'] ?? '') === 'no_text' ? 'No text could be read — it may be a scan.' : 'Reading failed' . (!empty($pl['error']) ? ': ' . e(mb_substr((string) $pl['error'], 0, 200)) : '.') ?></span>
        <?php break;
        case 'stale_source': ?>
            <span class="text-muted"><?= (int) ($pl['sections'] ?? 0) ?> section<?= (int) ($pl['sections'] ?? 0) === 1 ? '' : 's' ?>, <?= number_format((int) ($pl['characters'] ?? 0)) ?> characters wait for a build.</span>
        <?php break;
        case 'url_changed': ?>
            <span class="text-muted"><?= !empty($pl['vanished']) ? 'The page is gone' : 'The page changed' ?><?= !empty($pl['changed_at']) ? ' (' . e((string) $pl['changed_at']) . ')' : '' ?>.</span>
        <?php break;
        case 'feed_stopped': ?>
            <span class="text-muted">Last sync: <?= e((string) ($pl['last_status'] ?? 'failed')) ?><?= !empty($pl['since']) ? ' since ' . e((string) $pl['since']) : '' ?>.</span>
        <?php break;
        case 'limit_near': ?>
            <span class="text-muted"><?= number_format((int) ($pl['used_chars'] ?? 0)) ?> of <?= number_format((int) ($pl['limit_chars'] ?? 0)) ?> characters used this month<?= isset($pl['pct']) ? ' (' . (int) $pl['pct'] . ' %)' : '' ?>.</span>
        <?php break;
        case 'review_pin': ?>
            <span class="text-muted">Review was due <?= e((string) ($pl['review_until'] ?? '')) ?>.</span>
        <?php break;
        case 'area': ?>
            <?php if (!empty($pl['description'])): ?><div class="text-break mb-1"><?= e((string) $pl['description']) ?></div><?php endif; ?>
            <?php if (!empty($pl['seed_terms'])): ?><div class="d-flex flex-wrap gap-1 mb-1"><?php foreach ((array) $pl['seed_terms'] as $t): ?><?= chip([(string) $t, 'light']) ?><?php endforeach; ?></div><?php endif; ?>
            <?php if (!empty($pl['example_questions'])): ?><ul class="mb-0 ps-3"><?php foreach ((array) $pl['example_questions'] as $x): ?><li class="text-break"><?= e((string) $x) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php break;
        case 'merge': ?>
            <?php if (!$decide): ?><ul class="mb-0 ps-3"><?php foreach ((array) ($pl['groups'] ?? []) as $g): ?><li class="text-break"><strong><?= e((string) ($g['suggested'] ?? ($g['names'][0] ?? ''))) ?></strong> ← <?= e(implode(', ', array_map('strval', (array) ($g['names'] ?? [])))) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php break;
    endswitch; ?>
    </div>

    <?php if ($decide): ?>
        <form method="post" action="/proposals/accept.php" hx-post="/proposals/accept.php" hx-target="#flash" class="mt-auto" id="proposal-accept-<?= $pid ?>">
            <?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $pid ?>">
            <?php if ($p['kind'] === 'merge'): ?>
                <input type="hidden" name="groups_sent" value="1">
                <fieldset class="mb-2" id="proposal-accept-<?= $pid ?>-groups"><legend class="fs-12 text-muted mb-1">Merge these groups</legend>
                    <?php foreach (array_values((array) ($pl['groups'] ?? [])) as $i => $g): $n = $i + 1; $gid = ($uniq ? 'merge-group-' . $pid . '-' : 'merge-group-') . $n; ?>
                        <label class="d-flex align-items-start gap-2 border rounded p-2 mb-1 fs-12 btn-touch" for="<?= e($gid) ?>"><input type="checkbox" class="form-check-input mt-1 flex-shrink-0" name="groups[]" value="<?= $n ?>" id="<?= e($gid) ?>" checked>
                            <span class="text-break"><strong><?= e((string) ($g['suggested'] ?? ($g['names'][0] ?? ''))) ?></strong> ← <?= e(implode(', ', array_map('strval', array_filter((array) ($g['names'] ?? []), static fn ($x): bool => mb_strtolower((string) $x) !== mb_strtolower((string) ($g['suggested'] ?? ($g['names'][0] ?? ''))))))) ?></span></label>
                    <?php endforeach; ?>
                </fieldset>
            <?php elseif ($p['kind'] === 'area'): ?>
                <div class="mb-2"><label class="form-label fs-12 text-muted" for="proposal-accept-<?= $pid ?>-name">Name</label><input type="text" name="name" id="proposal-accept-<?= $pid ?>-name" class="form-control btn-touch" maxlength="120" required value="<?= e((string) ($pl['name'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label fs-12 text-muted" for="proposal-accept-<?= $pid ?>-description">Description</label><textarea name="description" id="proposal-accept-<?= $pid ?>-description" class="form-control" rows="2"><?= e((string) ($pl['description'] ?? '')) ?></textarea></div>
                <div class="mb-2"><label class="form-label fs-12 text-muted" for="proposal-accept-<?= $pid ?>-seed_terms">Seed terms — one a line</label><textarea name="seed_terms" id="proposal-accept-<?= $pid ?>-seed_terms" class="form-control" rows="3"><?= e(implode("\n", array_map('strval', (array) ($pl['seed_terms'] ?? [])))) ?></textarea></div>
                <div class="mb-2"><label class="form-label fs-12 text-muted" for="proposal-accept-<?= $pid ?>-example_questions">Example questions — one a line</label><textarea name="example_questions" id="proposal-accept-<?= $pid ?>-example_questions" class="form-control" rows="3"><?= e(implode("\n", array_map('strval', (array) ($pl['example_questions'] ?? [])))) ?></textarea></div>
            <?php elseif ($p['kind'] === 'stale_source'): ?>
                <?= view('proposals/partials/accept-build.php', ['est' => $est, 'pid' => $pid]) ?>
            <?php endif; ?>
            <div class="fs-11 text-muted mb-2" id="proposal-accept-<?= $pid ?>-hint"><?= e(proposal_accept_words($p)) ?></div>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="proposal-accept-<?= $pid ?>-btn">Accept</button>
        </form>
        <?= view('proposals/partials/dismiss-form.php', ['pid' => $pid]) ?>
    <?php elseif (!$open): ?>
        <div class="fs-12 text-muted mt-auto" id="proposal-<?= $pid ?>-decision"><?= e(ucfirst($p['status'])) ?> by <?= e((string) ($p['decided_by_name'] ?? '—')) ?>, <?= e(format_ts($p['decided_at'], $tz, 'M j, Y')) ?>.
            <?php if (!empty($pl['decision_note'])): ?><div class="kn-prewrap text-break mt-1" id="proposal-<?= $pid ?>-note">“<?= e((string) $pl['decision_note']) ?>”</div><?php endif; ?></div>
    <?php endif; ?>
</div></div>
