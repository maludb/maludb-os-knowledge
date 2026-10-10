<?php
declare(strict_types=1);

/**
 * What accepting a proposal does (slice 8, agents-feeds.md "The Archivist's proposals" — the table is the contract). A curator's act: the effect and the decision
 * are ONE transaction, so an effect the database refuses (a build already running, the month's limit spent, a name taken) leaves the proposal open and the
 * refusal is the curator's to read; a "lands on" kind is decided and the person is sent to the screen that does the work. A proposal whose subject is gone or
 * no longer needs the work (a source already read, a pin retired) is accepted with no effect — the word says so.
 */

/**
 * Accept a proposed proposal. $form: groups (int[]|null — a merge's ticked groups by number, null = all), area (the fields of an area proposal as edited:
 * name, description, seed_terms, example_questions, position — null = the payload's). ['effect', 'land', 'record_id', 'notice', 'did', 'post' => work for after the commit].
 */
function accept_proposal(PDO $pdo, int $id, array $form, int $by): array
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM archivist_proposals WHERE id = :id FOR UPDATE');
        $st->execute(['id' => $id]);
        $row = $st->fetch() ?: throw new DomainException('Not found.');
        if ($row['status'] !== 'proposed') {
            throw new DomainException('A decided proposal stays ' . $row['status'] . '.');
        }
        $row['payload'] = json_decode((string) $row['payload'], true) ?: [];
        $fx = proposal_effect($pdo, $row, $form, $by);
        $pdo->prepare("UPDATE archivist_proposals SET status = 'accepted', decided_by = :by WHERE id = :id AND status = 'proposed'")->execute(['by' => $by, 'id' => $id]);
        log_activity($pdo, 'archivist.accept', 'archivist_proposal', $id, ['base_id' => (int) $row['base_id'], 'after' => ['proposal_id' => $id, 'kind' => $row['kind'], 'effect' => $fx['effect'],
            'record_id' => $fx['record_id']]]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $fx + ['proposal_id' => $id, 'base_id' => (int) $row['base_id'], 'kind' => $row['kind']];
}

/** The effect of one kind, inside the caller's transaction. ['effect', 'land', 'record_id', 'notice', 'did', 'post']. */
function proposal_effect(PDO $pdo, array $p, array $form, int $by): array
{
    $bid = (int) $p['base_id'];
    $sid = $p['subject_id'] === null ? null : (int) $p['subject_id'];
    $payload = $p['payload'];
    $fx = static fn (string $effect, string $did, ?string $land = null, ?int $record = null, string $notice = 'accepted', array $post = []): array
        => ['effect' => $effect, 'land' => $land, 'record_id' => $record, 'notice' => $notice, 'did' => $did, 'post' => $post];
    switch ($p['kind']) {
        case 'failed_source':
            if (($payload['reason'] ?? '') === 'no_text') {
                return $fx('open_source', 'Accepted — replace or remove the source', '/sources/' . $sid, $sid);
            }
            $src = source_row($pdo, $sid);
            if ($src === null || $src['status'] !== 'failed') {
                return $fx('none', 'Accepted — the source is not failed any more', null, $sid);
            }
            retry_source($pdo, $sid, $by);
            source_log($pdo, 'source.retry', $bid, $sid, ['before' => ['status' => 'failed', 'error' => $src['error']], 'after' => ['status' => 'uploaded', 'via_proposal' => (int) $p['id']]]);
            return $fx('retry_source', 'Accepted — the source goes back in the queue', null, $sid);
        case 'stale_source':
            return proposal_effect_build($pdo, $p, $by, $fx);
        case 'url_changed':
            return $fx('open_replace', 'Accepted — replace the source', '/sources/' . $sid . '/replace', $sid);
        case 'feed_stopped':
            if (one_value($pdo, 'SELECT 1 FROM feeds WHERE id = :id AND base_id = :b', ['id' => $sid, 'b' => $bid]) === null) {
                throw new DomainException('That feed is gone: dismiss the proposal.');
            }
            request_feed_sync($pdo, $sid, $by);
            log_activity($pdo, 'feed.sync', 'feed', $sid, ['base_id' => $bid, 'after' => ['feed_id' => $sid, 'requested' => true, 'via_proposal' => (int) $p['id']]]);
            return $fx('sync_feed', 'Accepted — the feed syncs on the next worker pass', null, $sid);
        case 'limit_near':
            return $fx('open_settings', 'Accepted — the limit is on the base\'s settings', '/bases/' . $bid . '/settings', $bid);
        case 'gap':
            if (($payload['reason'] ?? '') === 'thumbs_down') {
                return $fx('open_question', 'Accepted — the question', '/questions/' . $sid, $sid);
            }
            return $fx('open_add', 'Accepted — add what is missing', '/sources/new?base=' . $bid, $bid);
        case 'unverified':
            return $fx('open_question', 'Accepted — the question', '/questions/' . $sid, $sid);
        case 'review_pin':
            $pin = $pdo->prepare("SELECT status FROM pinned_answers WHERE id = :id AND base_id = :b");
            $pin->execute(['id' => $sid, 'b' => $bid]);
            $status = $pin->fetchColumn();
            if ($status !== 'active') {
                return $fx('none', 'Accepted — the answer is not pinned any more', null, $sid);
            }
            review_pin($pdo, $sid, $by);
            $until = (string) one_value($pdo, 'SELECT review_until FROM pinned_answers WHERE id = :p', ['p' => $sid]);
            log_activity($pdo, 'question.pin', 'pinned_answer', $sid, ['base_id' => $bid, 'after' => ['pinned_answer_id' => $sid, 'review' => true, 'review_until' => $until, 'via_proposal' => (int) $p['id']]]);
            return $fx('verify_pin', 'Verified again — review by ' . date('M j, Y', strtotime($until)), '/pinned', $sid);
        case 'merge':
            return proposal_effect_merge($pdo, $p, $form, $by, $fx);
        case 'unassigned':
            return $p['subject_type'] === 'focus_area'
                ? $fx('open_area', 'Accepted — assign its sources', '/areas/' . $sid, $sid)
                : $fx('open_source', 'Accepted — set its areas', '/sources/' . $sid, $sid);
        case 'area':
            return proposal_effect_area($pdo, $p, $form, $by, $fx);
    }
    throw new DomainException('That kind of proposal is not accepted here.');
}

/** stale_source: a build of the new and stale sections under the monthly limit — the refusals are the build screen's own words, in order. */
function proposal_effect_build(PDO $pdo, array $p, int $by, callable $fx): array
{
    $bid = (int) $p['base_id'];
    $base = $pdo->prepare('SELECT name, archived_at FROM knowledge_bases WHERE id = :b');
    $base->execute(['b' => $bid]);
    $b = $base->fetch() ?: throw new DomainException('Not found.');
    if ($b['archived_at'] !== null) {
        throw new DomainException('Base "' . $b['name'] . '" is archived: restore it first.');
    }
    if (!kn_compile_connection()['ok']) {
        throw new DomainException(BUILD_ERRORS['model_not_configured']);
    }
    $est = build_estimate($pdo, $bid, 'extract') ?? throw new DomainException('You may not start a build.');
    if ($est['running_build_id'] !== null) {
        throw new DomainException('A build is already running.');
    }
    if ((int) $est['sections'] === 0) {
        return $fx('none', 'Accepted — every section is in the graph under version ' . (int) $est['guideline_version'], null, $bid, 'nothing');
    }
    if ((int) $est['remaining_chars'] === 0) {
        throw new DomainException('This month\'s text limit for this base is spent.');
    }
    $sha = kn_compile_prompt($pdo, $bid)['sha256'];
    $r = start_build($pdo, $bid, 'extract', $by);
    log_activity($pdo, 'build.start', 'build', $r['build_id'], ['base_id' => $bid, 'after' => ['build_id' => $r['build_id'], 'kind' => 'extract', 'sections' => $r['sections'], 'characters' => $r['characters'],
        'guideline_version' => (int) $est['guideline_version'], 'prompt_sha256' => $sha, 'within_limit' => (bool) $est['within_limit'], 'auto' => false, 'via_proposal' => (int) $p['id']]]);
    return $fx('start_build', 'Accepted — building ' . $r['sections'] . ' section' . ($r['sections'] === 1 ? '' : 's') . ', ' . number_format($r['characters']) . ' characters', '/builds/' . $r['build_id'], $r['build_id'], 'built');
}

/** merge: for each ticked group, the base's term of the chosen canonical name gains the others as aliases (a term is made when there is none) — through save_term(). */
function proposal_effect_merge(PDO $pdo, array $p, array $form, int $by, callable $fx): array
{
    $bid = (int) $p['base_id'];
    $groups = array_values(is_array($p['payload']['groups'] ?? null) ? $p['payload']['groups'] : []);
    $pick = $form['groups'] ?? null;
    $pick = $pick === null ? ($groups === [] ? [] : range(1, count($groups))) : array_values(array_unique(array_map('intval', $pick)));
    if ($pick === []) {
        throw new DomainException('Tick at least one group to merge.');
    }
    $before = guideline_base_state($pdo, $bid);
    $find = $pdo->prepare('SELECT id, canonical_name, aliases, subject_type, description FROM vocabulary_terms WHERE base_id = :b AND lower(canonical_name) = lower(:n)');
    $merged = 0;
    $touched = [];
    foreach ($pick as $n) {
        $g = $groups[$n - 1] ?? throw new DomainException('There is no group ' . $n . ' in this proposal.');
        $names = array_values(array_filter(array_map(static fn ($x): string => trim((string) $x), (array) ($g['names'] ?? [])), static fn (string $x): bool => $x !== ''));
        $canonical = trim((string) ($g['suggested'] ?? ($names[0] ?? '')));
        if ($canonical === '' || count($names) < 2) {
            throw new DomainException('Group ' . $n . ' names fewer than two subjects.');
        }
        $others = array_values(array_filter($names, static fn (string $x): bool => mb_strtolower($x) !== mb_strtolower($canonical)));
        $find->execute(['b' => $bid, 'n' => $canonical]);
        $cur = $find->fetch();
        $find->closeCursor();
        if ($cur === false) {
            $type = (string) ($g['subject_type'] ?? '');
            $fields = ['canonical_name' => $canonical, 'aliases' => clean_aliases($others, $canonical), 'subject_type' => preg_match(TERM_TYPE_PATTERN, $type) === 1 ? $type : null, 'description' => null];
            $created = true;
            $tid = null;
        } else {
            $have = pg_text_array((string) $cur['aliases']);
            $aliases = clean_aliases(array_merge($have, $others), (string) $cur['canonical_name']);
            if (count($aliases) === count($have)) {
                continue;                                               // already folded: nothing to write
            }
            $fields = ['canonical_name' => (string) $cur['canonical_name'], 'aliases' => $aliases, 'subject_type' => $cur['subject_type'], 'description' => $cur['description']];
            $created = false;
            $tid = (int) $cur['id'];
        }
        $reason = term_refusal($fields['canonical_name'], $fields['aliases'], (string) ($fields['subject_type'] ?? ''), (string) ($fields['description'] ?? ''));
        if ($reason !== null) {
            throw new DomainException('Group ' . $n . ': ' . $reason);
        }
        $r = save_term($pdo, $tid, $bid, $fields, $by);
        guideline_log($pdo, 'vocabulary.save', $bid, 'term', $r['term_id'], ['after' => ['term_id' => $r['term_id'], 'canonical_name' => $fields['canonical_name'], 'aliases' => count($fields['aliases']),
            'subject_type' => $fields['subject_type'], 'created' => $created, 'via_proposal' => (int) $p['id']]]);
        $touched[] = $r['term_id'];
        $merged++;
    }
    $after = guideline_base_state($pdo, $bid);
    $move = ['base_version_before' => $before['version'], 'base_version_after' => $after['version'], 'stale_marked' => max(0, $after['stale'] - $before['stale']), 'stale_total' => $after['stale']];
    log_version_move($pdo, $bid, $move);
    if ($merged === 0) {
        return $fx('none', 'Accepted — the vocabulary already folds those subjects together', '/bases/' . $bid . '/vocabulary', null);
    }
    return $fx('merge_terms', 'Merged ' . $merged . ' group' . ($merged === 1 ? '' : 's') . ' — the base is at version ' . $after['version'], '/bases/' . $bid . '/vocabulary', $touched[0], 'accepted', ['move' => $move]);
}

/** area: the area made active with the payload (as the curator edited it) through the slice 3 writer; its engine project is the handler's step after the commit. */
function proposal_effect_area(PDO $pdo, array $p, array $form, int $by, callable $fx): array
{
    $bid = (int) $p['base_id'];
    $a = $form['area'] ?? null;
    if ($a === null) {
        $pl = $p['payload'];
        $a = ['name' => trim((string) ($pl['name'] ?? '')), 'description' => trim((string) ($pl['description'] ?? '')), 'seed_terms' => array_values(array_map('strval', (array) ($pl['seed_terms'] ?? []))),
              'example_questions' => array_values(array_map('strval', (array) ($pl['example_questions'] ?? []))), 'position' => 0];
        if ($a['name'] === '' || mb_strlen($a['name']) > 120) {
            throw new DomainException('The proposal gives the area no usable name: edit it before accepting.');
        }
    }
    $id = save_area($pdo, null, $bid, $a, $by);
    log_activity($pdo, 'focus_area.save', 'focus_area', $id, ['base_id' => $bid, 'after' => ['focus_area_id' => $id, 'name' => $a['name'], 'status' => 'active', 'seed_terms' => count($a['seed_terms']),
        'example_questions' => count($a['example_questions']), 'created' => true, 'via_proposal' => (int) $p['id']]]);
    return $fx('create_area', 'Accepted — the area ' . $a['name'] . ' is made', '/areas/' . $id, $id, 'accepted', ['area_id' => $id]);
}
