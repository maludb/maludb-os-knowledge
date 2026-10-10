<?php
/**
 * Helpers for the slice 4 proofs (docs/build-specs/builds.md "Proof"). Run through tests/phase3/slice4/run.sh: a fresh SCRATCH database (kn_dev4),
 * the application on :8501 (php -S, or KN_APP=apache), the fake kernel (:8502), the REAL MaluDB API on a scratch memory database (:8503) with the
 * fake provider (:8504) — tests/phase3/engine.sh —, the fake MaluDB + MaluMail (:8506). The worker is run by the proofs, pass by pass.
 * The world: "SMOKE Field Manuals" (Priya owns it; Marco curates; Reid reads; Seamus the expert curates) with the pump manual, the returns policy
 * and the training notes read and cut, three vocabulary terms (one with aliases), the area "SMOKE Warranty" on two sources. Auto-extract is OFF
 * in the world (auto.php turns it on where it proves it), so a pass builds only what a proof starts.
 */
require dirname(__DIR__) . '/slice3/lib.php';
require_once APP_ROOT . '/app/knowledge/worker/builds.php';
require_once APP_ROOT . '/app/features/builds/present.php';

const MANUAL_WORDS = 'Keep the record with the unit.';         // in every manual section: never in a log row

/** A statement as postgres in a scratch database (the app's by default; 'engine' = the scratch memory database, as its role). */
function as_postgres(string $sql, string $db = ''): string
{
    if ($db === 'engine') {
        $sql = 'SET ROLE kn_engine_p3; SET search_path TO kn_engine_p3, maludb_core, public; ' . $sql;
        $db = 'kn_engine_p3';
    }
    $db = $db ?: need('DB_NAME');
    return trim((string) shell_exec('sudo -n -u postgres psql -v ON_ERROR_STOP=1 -qtA -d ' . escapeshellarg($db) . ' -c ' . escapeshellarg($sql) . ' 2>&1'));
}

/** A base made by Priya with Marco curating and auto-extract off (the build proofs start builds by hand). */
function curated_base(string $name, string $key): int
{
    $id = base_by_key($key);
    if ($id !== null) { return $id; }
    $id = make_base(PRIYA, $name, ['key' => $key]);
    pdo()->exec("UPDATE knowledge_bases SET auto_extract = false WHERE id = $id");
    act(as_member(PRIYA), '/bases/members/add.php', ['base' => $id, 'member' => MARCO, 'level' => 'curate']);
    return $id;
}

/** A pasted source added by Priya (read and cut by the next pass): its id. */
function add_text(int $base, string $title, string $text): int
{
    [$c, $b] = act(as_member(PRIYA), '/sources/add.php', ['base' => $base, 'kind' => 'text', 'title' => $title, 'text' => $text]);
    if ($c !== 200) { fwrite(STDERR, "add_text $title: $c " . json_encode($b) . "\n"); }
    return (int) ($b['record_id'] ?? 0);
}

/** The world (idempotent; world4 — slice 3's world() is another): ['base', 'manual', 'returns', 'training', 'area']. */
function world4(): array
{
    ensure_reference();
    pdo()->exec("UPDATE members SET roles = '{curator,user}' WHERE id = " . SEAMUS . " AND NOT ('curator' = ANY (roles))");
    $id = base_by_key('smoke-field-manuals');
    if ($id === null) {
        $id = curated_base('SMOKE Field Manuals', 'smoke-field-manuals');
        $p = as_member(PRIYA);
        act($p, '/bases/members/add.php', ['base' => $id, 'member' => REID, 'level' => 'read']);
        act($p, '/bases/members/add.php', ['base' => $id, 'member' => SEAMUS, 'level' => 'curate']);
        add_text($id, 'SMOKE Pump Service Manual', manual_text());
        add_text($id, 'SMOKE Returns Policy', "# Returns\n\nReturns are accepted within 30 days of delivery. Zorblat Pump returns need the serial number.");
        add_text($id, 'SMOKE Training Notes', "# Training\n\nTraining Office runs the pump course.");
        worker();
        act($p, '/vocabulary/save.php', ['base' => $id, 'canonical_name' => 'Zorblat Pump', 'aliases' => 'ZP-9, the pump', 'subject_type' => 'product', 'description' => 'The pump line.']);
        act($p, '/vocabulary/save.php', ['base' => $id, 'canonical_name' => 'Merrow Filter', 'subject_type' => 'part']);
        act($p, '/vocabulary/save.php', ['base' => $id, 'canonical_name' => 'Quillon Valve', 'subject_type' => 'part']);
        [, $ab] = act($p, '/areas/save.php', ['base' => $id, 'name' => 'SMOKE Warranty', 'description' => 'Warranty and returns.', 'seed_terms' => 'Zorblat Pump']);
        $area = (int) ($ab['record_id'] ?? 0);
        foreach (['SMOKE Pump Service Manual', 'SMOKE Returns Policy'] as $t) {
            act($p, '/areas/assign.php', ['focus_area' => $area, 'source' => source_of($id, $t), 'assigned' => 'yes']);
        }
    }
    return ['base' => $id, 'manual' => source_of($id, 'SMOKE Pump Service Manual'), 'returns' => source_of($id, 'SMOKE Returns Policy'),
            'training' => source_of($id, 'SMOKE Training Notes'), 'area' => (int) one("SELECT id FROM focus_areas WHERE base_id = :b AND name = 'SMOKE Warranty'", ['b' => $id])];
}
function source_of(int $base, string $title): int { return (int) one('SELECT id FROM sources WHERE base_id = :b AND title = :t ORDER BY id DESC LIMIT 1', ['b' => $base, 't' => $title]); }
function build_row(int $id): array { return q('SELECT * FROM builds WHERE id = :id', ['id' => $id])[0] ?? []; }
function live_build(int $base): ?int { $v = one("SELECT id FROM builds WHERE base_id = :b AND status IN ('queued', 'running')", ['b' => $base]); return $v === false || $v === null ? null : (int) $v; }
function sections_by(int $base, ?string $status = null): array
{
    return q('SELECT * FROM sections WHERE base_id = :b' . ($status ? ' AND status = :s' : " AND status <> 'forgotten'") . ' ORDER BY source_id, position', ['b' => $base] + ($status ? ['s' => $status] : []));
}
function usage_month(int $base, string $month = 'now'): array
{
    return q("SELECT calls, chars FROM ai_usage WHERE base_id = :b AND kind = 'extract' AND month = date_trunc('month', :m::timestamptz)::date", ['b' => $base, 'm' => $month === 'now' ? date('c') : $month])[0] ?? ['calls' => 0, 'chars' => 0];
}
/** Start a build as a member through the handler: [status, body]. */
function start(int $member, int $base, string $kind = 'extract', array $more = []): array
{
    [$c, $b] = act(as_member($member), $kind === 'rebuild_all' ? '/builds/rebuild-all.php' : '/builds/start.php', ['base' => $base] + $more);
    return [$c, $b];
}
/** Passes of the worker until the build is finished (at most $max): the number of passes. */
function run_build(int $build, int $max = 12, array $env = []): int
{
    for ($i = 1; $i <= $max; $i++) {
        worker($env);
        if (!in_array(build_row($build)['status'] ?? 'done', ['queued', 'running'], true)) { return $i; }
    }
    return $max + 1;
}
/** Cancel any live build of the base (a proof's tidy-up) through SQL. */
function cancel_live(int $base): void { pdo()->exec("UPDATE builds SET status = 'cancelled' WHERE base_id = $base AND status IN ('queued', 'running')"); }
/** The worker in the background (a proof acts while it waits on the engine): the process handle; wait_worker() collects it. */
function worker_bg(array $env = [])
{
    $pre = '';
    foreach ($env as $k => $v) { $pre .= escapeshellarg($k . '=' . $v) . ' '; }
    return proc_open('cd ' . escapeshellarg(APP_ROOT) . ' && exec env ' . $pre . 'php bin/worker.php', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes) ?: null;
}
/** A worker step on its own (tests/phase3/slice4/call.php): its JSON answer decoded. */
function call_step(string ...$args): mixed
{
    return json_decode((string) shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && php tests/phase3/slice4/call.php ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1'), true);
}
function wait_worker($proc): void { if ($proc) { proc_close($proc); } }
/** The documents of a namespace (ids), from the engine. */
function engine_docs(int $base): array
{
    $r = kn_engine_documents('kb:' . $base, 200);
    return array_map(static fn ($d) => (int) $d['id'], $r['data']['documents'] ?? []);
}
/** Notifications of a kind for a member about a base's builds or the base. */
function told(int $member, string $kind): int { return (int) one('SELECT count(*) FROM notifications WHERE member_id = :m AND kind = :k', ['m' => $member, 'k' => $kind]); }
/** No build row of the log (build.*, vocabulary.sync, the worker's cron rows) holds any of these words (a term's own save names it — that row is the vocabulary's). */
function log_clean(array $words): bool
{
    foreach ($words as $w) {
        $n = (int) one("SELECT count(*) FROM activity_log WHERE (action LIKE 'build.%' OR action = 'vocabulary.sync' OR source = 'cron' OR entity_type = 'build')
                         AND coalesce(before::text, '') || coalesce(after::text, '') LIKE :w", ['w' => '%' . $w . '%']);
        if ($n > 0) { return false; }
    }
    return words_in_log(MANUAL_WORDS) === false;
}
/** The browser proof's world (tests/phase3/slice4/browser.mjs calls it through php -r): two bases with sources read and cut, no build. */
function browser_world(): array
{
    world4();
    $a = curated_base('SMOKE Browser Builds', 'smoke-browser-builds');
    if (count(sections_by($a)) === 0) { add_text($a, 'SMOKE Browser Manual', manual_text()); }
    $b = curated_base('SMOKE Browser NoJS', 'smoke-browser-nojs');
    if (count(sections_by($b)) === 0) { add_text($b, 'SMOKE Browser Note', "# Note\n\nThe Merrow Filter is cleaned weekly." . str_repeat(' Keep it dry.', 20)); }
    worker();
    cancel_live($a); cancel_live($b);
    $failed = (int) one("SELECT build_id FROM build_items WHERE status = 'failed' ORDER BY id LIMIT 1");
    return ['builds' => $a, 'nojs' => $b, 'failed_build' => $failed];
}
