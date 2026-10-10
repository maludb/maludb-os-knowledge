<?php
/**
 * Helpers for the slice 8 proofs (docs/build-specs/agents-feeds.md "Proof"). Run through tests/phase3/slice8/run.sh: a fresh SCRATCH database (kn_dev8), the application on
 * :8501, the fake kernel (:8502 — chat in each state, K7 reads from state.reads), THE FAKE ENGINE (:8503, state.config and state.projects_fake switched on here), the proof's web
 * server (:8505, web.php — docs.example.test through the dev map), the fake MaluDB + MaluMail (:8506). The worker is run pass by pass (worker_at()), its clock moved with
 * KN_WORKER_NOW.
 * The cast (the spec's names → the fixture's): Priya (26, Member) reads; Cara → Marco (27, Curator) owns the Manuals and the Policies; Dee → Bea (28, Member); Gus → Ann (29, a guest);
 * Reid (32, Reader); the admin (1); the expert → Seamus (40, an agent, the Curator role on the Manuals so his drafts are his to save); the Archivist → a second agent row, 91 "SMOKE Archivist"
 * (role user, a Reader share on both bases — it proposes, it never curates), both named in kn_settings.
 * The world (agents_world()): "SMOKE Manuals" (internal; Marco's; the Handbook cut into sections, all extracted; a FIRST build just done; template guidelines only; no area) and
 * "SMOKE Policies" (internal; Marco's; two builds done, the area "Warranty" exists). Auto-extract is off in both.
 */
require dirname(__DIR__) . '/slice5/lib.php';
const ARCHIVIST = 91, REID_ = REID;
const WEB = 'http://docs.example.test';

/** The worker's pass with its clock at $now (an ISO time; null = the real one) and any other variables. Answers its output line. */
function worker_at(?string $now = null, array $env = []): string
{
    $env = ($now !== null ? ['KN_WORKER_NOW' => $now] : []) + $env;
    $pre = '';
    foreach ($env as $k => $v) { $pre .= escapeshellarg($k . '=' . $v) . ' '; }
    return trim((string) shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && env ' . $pre . 'php bin/worker.php 2>&1'));
}
/** Real time plus $minutes, as the worker's KN_WORKER_NOW. */
function at(int $minutes): string { return gmdate('Y-m-d\TH:i:s\Z', time() + $minutes * 60); }

function su(string $sql): string
{
    return trim((string) shell_exec('sudo -n -u postgres psql -v ON_ERROR_STOP=1 -qtA -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg($sql) . ' 2>&1'));
}

/** Done builds of a base made the way a worker makes them (queued → running → done), so the database's own triggers run. */
function done_build(int $base, string $kind = 'extract'): int
{
    $id = (int) one('INSERT INTO builds (base_id, kind, started_by) VALUES (:b, :k, :m) RETURNING id', ['b' => $base, 'k' => $kind, 'm' => CARA]);
    pdo()->exec("UPDATE builds SET status = 'running' WHERE id = $id");
    pdo()->exec("UPDATE builds SET status = 'done' WHERE id = $id");
    return $id;
}

/** The world (idempotent): ['manuals', 'policies', 'source' (the Handbook), 'carry', 'sick', 'warranty' (the Policies' area)]. */
function agents_world(): array
{
    $m = base_by_key('smoke-manuals');
    if ($m === null) {
        pdo()->exec("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (" . ARCHIVIST . ", 'agent', 'SMOKE Archivist', 'user', 'active', 'write', '{user}') ON CONFLICT (id) DO NOTHING");
        pdo()->exec("UPDATE members SET roles = '{curator,user}' WHERE id = " . SEAMUS);
        pdo()->exec('UPDATE kn_settings SET expert_member_id = ' . SEAMUS . ', archivist_member_id = ' . ARCHIVIST . ' WHERE id = 1');
        $c = as_member(CARA);
        $m = make_base(CARA, 'SMOKE Manuals', ['key' => 'smoke-manuals']);
        $p = make_base(CARA, 'SMOKE Policies', ['key' => 'smoke-policies']);
        pdo()->exec("UPDATE knowledge_bases SET auto_extract = false WHERE id IN ($m, $p)");
        foreach ([$m, $p] as $b) {
            act($c, '/bases/visibility.php', ['base' => $b, 'visibility' => 'internal']);
            act($c, '/bases/members/add.php', ['base' => $b, 'member' => ARCHIVIST, 'level' => 'read']);
            act($c, '/sources/add.php', ['base' => $b, 'kind' => 'text', 'title' => 'Staff handbook', 'text' => handbook_text()]);
        }
        act($c, '/bases/members/add.php', ['base' => $m, 'member' => SEAMUS, 'level' => 'curate']);
        worker_at();
        $doc = 700;
        foreach ([$m, $p] as $b) {   // every section built (the fake engine builds nothing)
            pdo()->exec("UPDATE sections SET status = 'extracted', extracted_version = (SELECT guideline_version FROM knowledge_bases k WHERE k.id = sections.base_id), maludb_document_id = '" . ($doc++) . "' WHERE base_id = $b");
        }
        pdo()->exec("INSERT INTO focus_areas (base_id, name, slug, description, status) VALUES ($p, 'Warranty', 'warranty', 'Guarantee terms.', 'active')");
        done_build($p);
        done_build($p);
        done_build($m);                                                 // the Manuals' FIRST build, just done
    }
    $p = (int) base_by_key('smoke-policies');
    $sec = static fn (string $h, int $b) => (int) one('SELECT id FROM sections WHERE base_id = :b AND heading = :h ORDER BY id LIMIT 1', ['b' => $b, 'h' => $h]);
    return ['manuals' => $m, 'policies' => $p, 'source' => (int) one("SELECT id FROM sources WHERE base_id = :b AND title = 'Staff handbook'", ['b' => $m]), 'carry' => $sec('Carry-over', $m), 'sick' => $sec('Sick days', $m),
            'warranty' => (int) one("SELECT id FROM focus_areas WHERE base_id = :b AND slug = 'warranty'", ['b' => $p])];
}

/** The dispatches of a kind (and base), oldest first. */
function dispatches_of(string $kind, ?int $base = null): array
{
    return q('SELECT * FROM agent_dispatches WHERE kind = :k AND (CAST(:b AS bigint) IS NULL OR base_id = :b) ORDER BY id', ['k' => $kind, 'b' => $base]);
}
function dispatch_row(int $id): array { return q('SELECT * FROM agent_dispatches WHERE id = :id', ['id' => $id])[0] ?? []; }
/** Forget every dispatch of this slice and the fake kernel's chat log (a clean slate for the next block). */
function clear_dispatches(): void
{
    pdo()->exec("DELETE FROM agent_dispatches WHERE kind IN ('propose_areas', 'draft_guidelines')");
    file_put_contents(need('FAKE_KERNEL_STATE') . '.chat', '');
}
function chat_clear_log(): void { file_put_contents(need('FAKE_KERNEL_STATE') . '.chat', ''); }
/** The fake kernel's K7 reads (chat_state()'s sibling): state.reads is replaced by $reads. */
function reads_state(array $reads): void { kernel_state(static function (array $s) use ($reads): array { $s['reads'] = $reads; return $s; }); }
function reads_log(): array { return array_map(static fn ($l) => json_decode($l, true), file(need('FAKE_KERNEL_STATE') . '.reads', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []); }
function reads_clear(): void { file_put_contents(need('FAKE_KERNEL_STATE') . '.reads', ''); }
/** The fake web server's pages: path => mode (ok · changed · 404 · 410 · 503 · same-text · ok-notag). */
function web_state(array $set): void
{
    $f = need('KN_WEB_STATE');
    $s = json_decode((string) @file_get_contents($f), true) ?: [];
    file_put_contents($f, json_encode($set + $s));
}

/** The Archivist's run token (and relay) for a call to a handler: [status, body, raw]. */
function as_archivist(string $path, array $form, int $run = 7801): array { return act_token($path, $form, as_agent(run_token(ARCHIVIST, $run))); }
/** A proposal made by the Archivist: the answer. $payload is encoded. */
function propose(int $base, string $kind, array $more = []): array
{
    $form = ['base' => $base, 'kind' => $kind, 'title' => $more['title'] ?? ('SMOKE ' . $kind), 'reason' => $more['reason'] ?? 'Because the Monday report found it.'] + array_diff_key($more, ['title' => 1, 'reason' => 1, 'payload' => 1]);
    if (isset($more['payload'])) { $form['payload'] = json_encode($more['payload']); }
    return as_archivist('/proposals/save.php', $form);
}
function proposal_row(int $id): array { return q('SELECT * FROM archivist_proposals WHERE id = :id', ['id' => $id])[0] ?? []; }
function proposal_payload(int $id): array { return json_decode((string) (proposal_row($id)['payload'] ?? '{}'), true) ?: []; }
function open_proposals(int $base): int { return (int) one("SELECT count(*) FROM archivist_proposals WHERE base_id = :b AND status = 'proposed'", ['b' => $base]); }
function json_msg(array $b): string { return (string) ($b['error']['message'] ?? ''); }
function logged(string $action, int $since): array { return activity($action, $since); }
function after_of(array $row): array { return json_decode((string) $row['after'], true) ?: []; }
function source_by_key(int $feed, string $key): array { return q('SELECT * FROM sources WHERE feed_id = :f AND feed_item_key = :k ORDER BY id DESC LIMIT 1', ['f' => $feed, 'k' => $key])[0] ?? []; }
function feed_row(int $id): array { return q('SELECT * FROM feeds WHERE id = :id', ['id' => $id])[0] ?? []; }

/** PHP in the application's CLI bootstrap as $member, with the proposals' code loaded ($arg = the decoded JSON argument, $pdo); its stdout. */
function php8(string $code, int $member = OWNER, mixed $arg = null): string
{
    $prog = 'require "app/bootstrap.php"; require_once APP_ROOT . "/app/features/proposals/handler.php"; $_SESSION["member_id"] = ' . $member . '; $pdo = db(); db_apply_context($pdo); $arg = json_decode($argv[1], true); ' . $code;
    return (string) shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && php -r ' . escapeshellarg($prog) . ' ' . escapeshellarg(json_encode($arg)) . ' 2>&1');
}
/** The proposals a member sees through the view. */
function proposals_visible_to(int $member): int
{
    pdo()->exec("SELECT set_config('app.member_id', '$member', false)");
    $n = (int) one('SELECT count(*) FROM mcp_archivist_proposals');
    pdo()->exec("SELECT set_config('app.member_id', '', false)");
    return $n;
}
/** A question of the Manuals asked by Priya (a row, not an ask): its id. */
function mkquestion(int $base, string $words, string $status = 'no_evidence', ?string $answer = null): int
{
    return (int) one("INSERT INTO questions (base_id, asked_by_member_id, door, question, answer_md, status) VALUES (:b, :m, 'web', :q, :a, :s) RETURNING id", ['b' => $base, 'm' => PRIYA, 'q' => $words, 'a' => $answer, 's' => $status]);
}
/** A source row of a base in a state, for the Archivist to be about (a failed one, one with no text, an address). */
function mksrc(int $base, string $title, string $status, string $kind = 'text', ?string $origin = null, ?string $error = null): int
{
    return (int) one("INSERT INTO sources (base_id, kind, title, origin, added_by, status, error) VALUES (:b, :k, :t, :o, :m, :s, :e) RETURNING id",
        ['b' => $base, 'k' => $kind, 't' => $title, 'o' => $origin ?? ($kind === 'text' ? 'paste' : null), 'm' => PRIYA, 's' => $status, 'e' => $error]);
}
/**
 * What the Archivist can be about, in the Manuals (and one question in the Policies): ['failed', 'no_text', 'url', 'plain' (a source in no area), 'q_gap1', 'q_gap2', 'q_down', 'q_unver', 'pin', 'feed',
 * 'leave' (an area with a source), 'empty_area' (an area with none), 'q_other' (a question of the Policies)]. Idempotent.
 */
function proposal_world(): array
{
    $w = agents_world();
    $m = $w['manuals'];
    $id = static fn (string $t) => (int) one("SELECT id FROM sources WHERE base_id = :b AND title = :t ORDER BY id LIMIT 1", ['b' => $m, 't' => $t]);
    if ($id('SMOKE Failed source') === 0) {
        pdo()->exec("INSERT INTO focus_areas (base_id, name, slug, description, status) VALUES ($m, 'Leave', 'leave', 'Leave rules.', 'active') ON CONFLICT DO NOTHING");
        pdo()->exec("INSERT INTO focus_areas (base_id, name, slug, description, status) VALUES ($m, 'Empty', 'empty', 'An area with no source.', 'active') ON CONFLICT DO NOTHING");
        mksrc($m, 'SMOKE Failed source', 'failed', 'text', null, 'extract_error');
        mksrc($m, 'SMOKE Scan', 'no_text');
        mksrc($m, 'SMOKE Field page', 'ready', 'url', WEB . '/page-stable.html');
        $plain = mksrc($m, 'SMOKE Loose notes', 'ready');
        $leave = (int) one("SELECT id FROM focus_areas WHERE base_id = :b AND slug = 'leave'", ['b' => $m]);
        pdo()->exec("INSERT INTO source_focus_areas (source_id, focus_area_id) VALUES ({$w['source']}, $leave) ON CONFLICT DO NOTHING");
        $qa = mkquestion($m, 'How does carry-over work for contractors?');
        $qb = mkquestion($m, 'Do contractors carry over leave?');
        mkquestion($m, 'Is the sick note rule fair?', 'answered', 'It depends.');
        mkquestion($m, 'What about parental leave abroad?', 'unverified');
        $qp = mkquestion($m, 'How many sick days are there?', 'answered', 'Ten paid sick days a year.');
        mkquestion($w['policies'], 'What does the warranty cover?');
        pdo()->prepare('SELECT kn_pin_answer(:q, :m, :t)')->execute(['q' => $qp, 'm' => CARA, 't' => 'SMOKE Sick days']);
        pdo()->exec("INSERT INTO feeds (base_id, provider, tool, arguments, every_minutes, created_by) VALUES ($m, 'spaces', 'pages_index', '{\"space\": \"smoke-stopped\"}', 60, " . CARA . ")");
    }
    $qid = static fn (string $t, int $b) => (int) one('SELECT id FROM questions WHERE base_id = :b AND question = :q ORDER BY id LIMIT 1', ['b' => $b, 'q' => $t]);
    return ['manuals' => $m, 'policies' => $w['policies'], 'source' => $w['source'], 'failed' => $id('SMOKE Failed source'), 'no_text' => $id('SMOKE Scan'), 'url' => $id('SMOKE Field page'), 'plain' => $id('SMOKE Loose notes'),
        'q_gap1' => $qid('How does carry-over work for contractors?', $m), 'q_gap2' => $qid('Do contractors carry over leave?', $m), 'q_down' => $qid('Is the sick note rule fair?', $m),
        'q_unver' => $qid('What about parental leave abroad?', $m), 'pin' => (int) one("SELECT id FROM pinned_answers WHERE base_id = :b AND title = 'SMOKE Sick days'", ['b' => $m]),
        'feed' => (int) one("SELECT id FROM feeds WHERE base_id = :b AND arguments->>'space' = 'smoke-stopped'", ['b' => $m]),
        'leave' => (int) one("SELECT id FROM focus_areas WHERE base_id = :b AND slug = 'leave'", ['b' => $m]), 'empty_area' => (int) one("SELECT id FROM focus_areas WHERE base_id = :b AND slug = 'empty'", ['b' => $m]),
        'q_other' => $qid('What does the warranty cover?', $w['policies'])];
}

/**
 * What the browser proof opens (idempotent enough to be rerun): the open proposals of the Manuals set aside and a fresh set made by the Archivist — a merge of two groups, an area, a
 * build (with stale sections to build), a gap cluster, a failed source —, a failed dispatch and a refused one for the admin's page, the Manuals' feeds as the feeds proof leaves them
 * plus one that failed. ['manuals', 'policies', 'merge', 'area', 'stale', 'gap', 'failed_source', 'dispatch_failed', 'feed'].
 */
function browser_world(): array
{
    $s = proposal_world();
    $m = $s['manuals'];
    pdo()->exec("UPDATE archivist_proposals SET status = 'dismissed', decided_at = now() WHERE base_id = $m AND status = 'proposed'");
    pdo()->exec("DELETE FROM vocabulary_terms WHERE base_id = $m AND canonical_name IN ('Browser Pump', 'Browser Valve')");
    pdo()->exec("INSERT INTO vocabulary_terms (base_id, canonical_name, aliases) VALUES ($m, 'Browser Pump', '{}')");
    // (after the vocabulary: a term shapes the graph and marks every built section stale)
    pdo()->exec("UPDATE sections SET status = 'extracted' WHERE base_id = $m AND status IN ('stale', 'queued')");
    pdo()->exec("UPDATE sections SET status = 'stale' WHERE id IN (SELECT id FROM sections WHERE base_id = $m AND status = 'extracted' ORDER BY id LIMIT 2)");     // some stale (a build to propose), some built (the expert has a graph to read)
    pdo()->exec("UPDATE sources SET status = 'failed', error = 'extract_error' WHERE id = {$s['failed']}");
    $id = static function (array $r): int { return (int) ($r[1]['record_id'] ?? 0); };
    $out = $s + [
        'merge' => $id(propose($m, 'merge', ['title' => 'Browser merge', 'reason' => 'Two pairs of subjects look alike in the graph.', 'payload' => ['groups' => [['names' => ['Browser Pump', 'Browser Pumps'], 'suggested' => 'Browser Pump'], ['names' => ['Browser Valve', 'Browser Valves'], 'suggested' => 'Browser Valve']]]])),
        'area' => $id(propose($m, 'area', ['title' => 'Browser area', 'reason' => 'A cluster of fourteen subjects about claims.', 'payload' => ['name' => 'Browser claims', 'description' => 'How claims are made.', 'seed_terms' => ['claim form', 'receipt'], 'example_questions' => ['How do I claim?']]])),
        'stale' => $id(propose($m, 'stale_source', ['title' => 'Browser build', 'reason' => 'Four sections wait for a build.', 'subject_type' => 'base', 'subject_id' => $m, 'payload' => ['sections' => 4, 'characters' => 1200]])),
        'gap' => $id(propose($m, 'gap', ['title' => 'Browser gap', 'reason' => 'Two people asked about contractors.', 'subject_type' => 'question', 'subject_id' => $s['q_gap2'], 'payload' => ['cluster_key' => 'contractors', 'question_count' => 2, 'question_ids' => [$s['q_gap1'], $s['q_gap2']], 'sample' => 'How does carry-over work for contractors?']])),
        'failed_source' => $id(propose($m, 'failed_source', ['title' => 'Browser failed source', 'reason' => 'It could not be read.', 'subject_type' => 'source', 'subject_id' => $s['failed'], 'payload' => ['reason' => 'failed', 'error' => 'extract_error']])),
    ];
    clear_dispatches();
    pdo()->exec("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, base_id, status, detail, attempts, answered_at) VALUES ('base', $m, " . EXPERT . ", 'propose_areas', 'chat', " . CARA . ", $m, 'failed', 'The kernel is not reachable right now.', 5, now())");
    $out['dispatch_failed'] = (int) one('SELECT max(id) FROM agent_dispatches');
    pdo()->exec("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, base_id, status, detail, attempts, answered_at) VALUES ('base', $m, " . EXPERT . ", 'draft_guidelines', 'chat', " . CARA . ", $m, 'refused', 'This person may not use the expert.', 1, now())");
    pdo()->exec("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, base_id, status, run_id, attempts) VALUES ('base', {$s['policies']}, " . EXPERT . ", 'propose_areas', 'chat', " . CARA . ", {$s['policies']}, 'sent', 9001, 1)");
    pdo()->exec("DELETE FROM feeds WHERE base_id = $m");
    pdo()->exec("INSERT INTO feeds (base_id, provider, tool, arguments, every_minutes, created_by, last_status, last_error, last_sync_at, items) VALUES ($m, 'spaces', 'pages_index', '{\"space\": \"product\"}', 60, " . CARA . ", 'no_connection', 'No approved connection to that tool — a super-admin approves it in the operating system.', now(), 0)");
    $out['feed'] = (int) one('SELECT max(id) FROM feeds');
    return $out;
}
