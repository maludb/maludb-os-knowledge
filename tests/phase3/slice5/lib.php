<?php
/**
 * Helpers for the slice 5 proofs (docs/build-specs/ask.md "Proof"). Run through tests/phase3/slice5/run.sh: a fresh SCRATCH database (kn_dev5),
 * the application on :8501, the fake kernel (:8502), THE FAKE ENGINE (:8503, tests/fake_kb_engine.php — $FAKE_KB_STATE drives it and every
 * request it gets is a line of $FAKE_KB_STATE.log), the fake MaluDB + MaluMail (:8506).
 * The cast (the spec's names → the fixture's): Priya (26, Member) asks; Cara → Marco (27, Curator) curates the Handbook and owns the private
 * base; Gus → Ann (29, a guest, Reader on the Handbook only); Dee → Bea (28, Member, no share to the private base); the admin (1); the expert
 * → Seamus (40, an agent) named in kn_settings.expert_member_id.
 * The world (ask_world()): "SMOKE Handbook" (internal; Marco's) with the source "Staff handbook" cut into sections whose two named ones —
 * "… › Leave › Carry-over" (page 3, document 501) and "… › Leave › Sick days" (page 4, document 502) — are extracted; the area "Leave" (active,
 * project 77) with example questions; the term PTO ⇐ "paid time off"; an answering and an audience guideline; "SMOKE Private Notes" (private,
 * Marco's, one section, document 601); "SMOKE Empty" (no section). Auto-extract is off everywhere (the fake engine builds nothing).
 */
require dirname(__DIR__) . '/slice1/lib.php';
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__, 3)); }
const CARA = MARCO, GUS = ANN, DEE = BEA, EXPERT = SEAMUS;
const SENTINEL = 'QUOKKA-SENTINEL-5531';        // placed in answers, quotes and passages: never in a log row

function kb_state(array $set, bool $replace = false): void
{
    $f = need('FAKE_KB_STATE');
    $s = $replace ? [] : (json_decode((string) @file_get_contents($f), true) ?: []);
    file_put_contents($f, json_encode(array_merge($s, $set), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
/** The fake engine's request log: every line, or those to a path. */
function kb_log(?string $path = null): array
{
    $out = [];
    foreach (file(need('FAKE_KB_STATE') . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $r = json_decode($l, true);
        if ($r !== null && ($path === null || $r['path'] === $path)) { $out[] = $r; }
    }
    return $out;
}
function kb_last_answer(): ?array { $a = kb_log('/v1/memory/answer'); return $a === [] ? null : end($a)['body']; }
function kb_count(string $path): int { return count(kb_log($path)); }

/** An M1 evidence item for a section (its document's metadata names base, section, page). */
function ev(int $n, int $doc, string $text, ?array $meta, float $sim = 0.82, string $subject = 'Leave'): array
{
    return ['n' => $n, 'chunk_id' => 9000 + $n, 'document_id' => $doc, 'statement_id' => 7000 + $n, 'text' => $text, 'similarity' => $sim, 'subject' => $subject, 'verb' => 'covers',
            'document' => ['title' => 'Doc ' . $doc, 'metadata' => $meta ?? []]];
}
/** An M1 reply. */
function m1(string $status, array $evidence, array $citations = [], ?string $answer = null, float $confidence = 0.81, int $calls = 1): array
{
    return ['status' => $status, 'answer_md' => $answer, 'confidence' => $status === 'answered' ? $confidence : 0.0, 'citations' => $citations, 'evidence' => $evidence,
            'subjects_tried' => ['Leave'], 'model' => $calls ? 'fake-answer-model' : null, 'usage' => ['input_tokens' => 120 * $calls, 'output_tokens' => 40 * $calls, 'calls' => $calls]];
}
function cite(int $n, string $quote, int $doc, bool $verified = true): array { return ['n' => $n, 'quote' => $quote, 'chunk_id' => 9000 + $n, 'document_id' => $doc, 'verified' => $verified]; }

function handbook_text(): string
{
    $pad = str_repeat(' Keep a copy with your manager.', 6);
    return "# Leave\n\nThe leave rules for staff.$pad\n\n## Carry-over\n\nUp to five days of unused leave carry over to the next year; the rest lapses on 31 March.$pad\n\n"
         . "## Sick days\n\nStaff have ten paid sick days a year; a doctor's note is needed after three days in a row.$pad\n\n# Pay\n\nPay is monthly, on the last working day.$pad\n";
}

/** The world (idempotent): ['handbook', 'private', 'empty', 'carry', 'sick', 'pay', 'private_section', 'leave' (area id), 'source']. */
function ask_world(): array
{
    $hb = base_by_key('smoke-handbook');
    if ($hb === null) {
        $c = as_member(CARA);
        $hb = make_base(CARA, 'SMOKE Handbook', ['key' => 'smoke-handbook']);
        act($c, '/bases/visibility.php', ['base' => $hb, 'visibility' => 'internal']);
        act($c, '/bases/members/add-guest.php', ['base' => $hb, 'guest' => GUS]);
        act($c, '/sources/add.php', ['base' => $hb, 'kind' => 'text', 'title' => 'Staff handbook', 'text' => handbook_text()]);
        act($c, '/guidelines/save.php', ['base' => $hb, 'kind' => 'answering', 'title' => 'SMOKE Answer briefly', 'body' => 'Answer in two sentences and cite the rule.']);
        act($c, '/guidelines/save.php', ['base' => $hb, 'kind' => 'audience', 'title' => 'SMOKE Staff', 'body' => 'The reader is a member of staff, not HR.']);
        act($c, '/vocabulary/save.php', ['base' => $hb, 'canonical_name' => 'PTO', 'aliases' => 'paid time off', 'subject_type' => 'policy']);
        $pv = make_base(CARA, 'SMOKE Private Notes', ['key' => 'smoke-private-notes']);
        act($c, '/sources/add.php', ['base' => $pv, 'kind' => 'text', 'title' => 'Private', 'text' => "# Private\n\nThe private plan for next year." . str_repeat(' Not for staff.', 20)]);
        $em = make_base(CARA, 'SMOKE Empty', ['key' => 'smoke-empty']);
        pdo()->exec("UPDATE knowledge_bases SET auto_extract = false WHERE id IN ($hb, $pv, $em)");
        worker_pass();
        $sec = static fn (string $h, int $b) => (int) one("SELECT id FROM sections WHERE base_id = :b AND heading = :h ORDER BY id LIMIT 1", ['b' => $b, 'h' => $h]);
        $set = static fn (int $id, string $doc, ?int $page) => pdo()->prepare("UPDATE sections SET status = 'extracted', extracted_version = (SELECT guideline_version FROM knowledge_bases k WHERE k.id = sections.base_id),
            maludb_document_id = :d, page_no = COALESCE(:p, page_no) WHERE id = :s")->execute(['d' => $doc, 'p' => $page, 's' => $id]);
        $set($sec('Carry-over', $hb), '501', 3);
        $set($sec('Sick days', $hb), '502', 4);
        $set($sec('Pay', $hb), '503', 5);
        $set((int) one('SELECT id FROM sections WHERE base_id = :b ORDER BY id LIMIT 1', ['b' => $pv]), '601', 1);
        pdo()->prepare("INSERT INTO focus_areas (base_id, name, slug, description, example_questions, maludb_project_id, status) VALUES (:b, 'Leave', 'leave', 'Leave and carry-over.', '{\"How does carry-over work?\",\"How many sick days do I get?\"}', '77', 'active')")
            ->execute(['b' => $hb]);
        pdo()->exec('UPDATE kn_settings SET expert_member_id = ' . EXPERT . ' WHERE id = 1');
    }
    $pv = (int) base_by_key('smoke-private-notes');
    $sec = static fn (string $h, int $b) => (int) one("SELECT id FROM sections WHERE base_id = :b AND heading = :h ORDER BY id LIMIT 1", ['b' => $b, 'h' => $h]);
    return ['handbook' => $hb, 'private' => $pv, 'empty' => (int) base_by_key('smoke-empty'), 'carry' => $sec('Carry-over', $hb), 'sick' => $sec('Sick days', $hb), 'pay' => $sec('Pay', $hb),
            'private_section' => (int) one('SELECT id FROM sections WHERE base_id = :b ORDER BY id LIMIT 1', ['b' => $pv]),
            'leave' => (int) one("SELECT id FROM focus_areas WHERE base_id = :b AND slug = 'leave'", ['b' => $hb]), 'source' => (int) one("SELECT id FROM sources WHERE base_id = :b AND title = 'Staff handbook'", ['b' => $hb])];
}
function worker_pass(): string { return trim((string) shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && php bin/worker.php 2>&1')); }

/** The two-citation answer of the Handbook to "carry-over" (sections by metadata), with SENTINEL in the answer, a quote and a passage. */
function carry_script(array $w, string $match = 'carry'): array
{
    $hb = $w['handbook'];
    $evid = [ev(1, 501, 'Up to five days of unused leave carry over to the next year ' . SENTINEL . '.', ['base' => $hb, 'section' => $w['carry'], 'page' => 3]),
             ev(2, 502, 'Staff have ten paid sick days a year.', ['base' => $hb, 'section' => $w['sick'], 'page' => 4], 0.71)];
    return ['match' => $match, 'answer' => m1('answered', $evid, [cite(1, 'Up to five days of unused leave carry over', 501), cite(2, 'ten paid sick days a year', 502)],
        "Up to five days carry over [1]; sick days are separate [2]. " . SENTINEL)];
}
/** Ask through the window as a member (JSON): [status, body]. */
function ask_as(int $member, array $form): array { return act(as_member($member), '/ask/send.php', $form); }
function question_row(int $id): array { return q('SELECT * FROM questions WHERE id = :id', ['id' => $id])[0] ?? []; }
function citations_of(int $qid): array { return q('SELECT * FROM citations WHERE question_id = :q ORDER BY rank', ['q' => $qid]); }
function questions_count(): int { return (int) one('SELECT count(*) FROM questions'); }
/** A word anywhere in the activity log's payloads. */
function in_log(string $w): bool { return (int) one("SELECT count(*) FROM activity_log WHERE coalesce(before::text, '') || coalesce(after::text, '') LIKE :w", ['w' => '%' . $w . '%']) > 0; }
/** A signed bridge call (on the app's own port: loopback). [status, body]. */
function bridge(array $msg, ?int $ts = null, ?string $sig = null, string $base = BASE): array
{
    $body = json_encode($msg);
    $ts ??= time();
    $sig ??= hash_hmac('sha256', 'kn-bridge:' . $ts . '.' . hash('sha256', $body), need('ACTIONS_RELAY_KEY'));
    $r = req('POST', $base . '/internal/bridge.php', ['headers' => ['Content-Type: application/json', 'X-KN-Bridge: ' . $ts . '.' . $sig], 'raw' => $body]);
    return [(int) $r['code'], json_decode($r['body'], true) ?? [], $r];
}
/** Stop or start the fake engine (the "engine down" checks). */
function kb_stop(): void
{
    $pid = (int) @file_get_contents(need('KN_DEV_STATE') . '/kb.pid');
    if ($pid > 0) { posix_kill($pid, 15); }
    usleep(400000);
}
function kb_start(): void
{
    $pid = trim((string) shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && nohup php -S 127.0.0.1:8503 tests/fake_kb_engine.php >> ' . escapeshellarg(need('KN_DEV_STATE') . '/kb.log') . ' 2>&1 & echo $!'));
    file_put_contents(need('KN_DEV_STATE') . '/kb.pid', $pid);
    for ($i = 0; $i < 30 && @file_get_contents('http://127.0.0.1:8503/health') === false; $i++) { usleep(100000); }
}
function usage_answer(int $base): array { return q("SELECT calls, input_tokens, output_tokens, chars FROM ai_usage WHERE base_id = :b AND kind = 'answer'", ['b' => $base])[0] ?? ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'chars' => 0]; }
/** An evidence-only question of $member on the Handbook (the answer route off, two passages recalled): its id. */
function evidence_only_question(array $w, int $member = PRIYA, string $words = 'How does carry-over work?'): int
{
    kb_state(['answer_route' => false, 'answer_mode' => 'ok', 'titles' => ['501' => 'Carry-over', '502' => 'Sick days'],
        'recall' => [['chunk_id' => 81, 'document_id' => 501, 'statement_id' => 1, 'source_text' => 'Up to five days of unused leave carry over.', 'similarity' => 0.77, 'subject_name' => 'Leave', 'verb_name' => 'covers'],
                     ['chunk_id' => 82, 'document_id' => 502, 'statement_id' => 2, 'source_text' => 'Ten paid sick days a year.', 'similarity' => 0.64, 'subject_name' => 'Sick days', 'verb_name' => 'grants']]]);
    [, $b] = ask_as($member, ['base' => $w['handbook'], 'question' => $words]);
    kb_state(['answer_route' => true]);
    return (int) ($b['question_id'] ?? 0);
}
/** Set the fake kernel's chat answers (replacing the chat keys). */
function chat_state(array $set): void
{
    kernel_state(static function (array $s) use ($set): array {
        unset($s['chat'], $s['chat_status'], $s['chat_message'], $s['chat_http'], $s['chat_run'], $s['chat_run_http']);
        return $set + $s;
    });
}
function chat_log(): array { return array_map(static fn ($l) => json_decode($l, true), file(need('FAKE_KERNEL_STATE') . '.chat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []); }
function dispatch_of(int $qid): array { return q("SELECT * FROM agent_dispatches WHERE record_type = 'question' AND record_id = :q ORDER BY id DESC", ['q' => $qid]); }
function told(int $member, string $kind): int { return (int) one('SELECT count(*) FROM notifications WHERE member_id = :m AND kind = :k', ['m' => $member, 'k' => $kind]); }
/** PHP run in the application's CLI bootstrap as $member ($code sees $arg = the decoded JSON argument and $pdo); its stdout. */
function php_run(string $code, int $member, mixed $arg = null): string
{
    $prog = 'require "app/bootstrap.php"; require_once APP_ROOT . "/app/features/ask/handler.php"; $_SESSION["member_id"] = ' . $member
          . '; $pdo = db(); db_apply_context($pdo); $arg = json_decode($argv[1], true); ' . $code;
    return (string) shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && php -r ' . escapeshellarg($prog) . ' ' . escapeshellarg(json_encode($arg)) . ' 2>&1');
}
/** kn_ask() / kn_search() in-process (the token API of slice 7 is not built; a key's call is made here directly). */
function php_kn(string $fn, array $req, int $member): array
{
    $out = php_run('echo json_encode(' . $fn . '($pdo, $arg));', $member, $req);
    return json_decode($out, true) ?? ['ok' => false, 'http' => 0, 'error' => ['code' => 'php', 'message' => $out]];
}
/** A key bound to a base (and an area), minted for $member: its id. */
function make_key(int $member, int $base, ?int $area = null, string $label = 'SMOKE key'): int
{
    return (int) one("INSERT INTO mcp_access_tokens (member_id, token_hash, label, scope, base_id, focus_area_id) VALUES (:m, :h, :l, 'api', :b, :a) RETURNING id",
        ['m' => $member, 'h' => hash('sha256', 'smoke-' . bin2hex(random_bytes(8))), 'l' => $label, 'b' => $base, 'a' => $area]);
}
