<?php
/**
 * Helpers for the slice 1 proofs (docs/build-specs/bases-core.md "Proof"). Run through tests/phase3/slice1/run.sh: a fresh SCRATCH database
 * (kn_dev1), the application on :8501, a fake kernel (:8502), a fake MaluDB + MaluMail (:8506). Everything a proof makes is named "SMOKE …".
 * Builds on tests/phase2/lib.php. The cast (bin/dev_directory.json): the Owner (1, super-admin), Avery (2, admin, Engineering), Priya (26, Member,
 * Design), Marco (27, Curator, Design), Bea (28, Member, Accounting), Reid (32, Reader, Engineering), Ann (29, external Reader = a guest), Seamus
 * (40, an agent, Member), the Watcher (41, an agent with no grant). Departments: Accounting 3, IT 5, Design 7, Engineering 8.
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

const JSONH = ['Accept: application/json'];
const OWNER = 1, AVERY = 2, PRIYA = 26, MARCO = 27, BEA = 28, ANN = 29, REID = 32, SEAMUS = 40, WATCHER = 41;
const ACCOUNTING = 3, IT = 5, DESIGN = 7, ENGINEERING = 8;
const APP_DIR_STORAGE = __DIR__ . '/../../../storage';

/** A signed-on jar for a fixture member (cached per proof). */
function as_member(int $m): string
{
    static $jars = [];
    if (isset($jars[$m])) { return $jars[$m]; }
    [$j, $r] = sign_on($m);
    if ($r['code'] !== 302) { fwrite(STDERR, "sign-on of $m failed: {$r['code']}\n"); }
    return $jars[$m] = $j;
}
/** A write as a signed-on person, answered as JSON: [status, decoded body, raw]. The CSRF token is fetched from the shell once per jar. */
function act(string $jar, string $path, array $form, array $extraHeaders = []): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'headers' => array_merge(JSONH, $extraHeaders), 'form' => $form + ['csrf_token' => $tok[$jar]]]);
    return [$r['code'], json_decode($r['body'], true) ?? [], $r];
}
/** The same write as a browser does it (no JSON): the raw response. */
function act_html(string $jar, string $path, array $form, array $extraHeaders = []): array
{
    $tok = csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    return req('POST', $path, ['jar' => $jar, 'headers' => $extraHeaders, 'form' => $form + ['csrf_token' => $tok]]);
}
/** A screen as JSON (data only): [status, data, raw]. */
function screen(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar, 'headers' => JSONH]); return [$r['code'], json_decode($r['body'], true)['data'] ?? [], $r]; }
function msg(array $body): string { return (string) ($body['error']['message'] ?? ''); }
function fields(array $body): array { return (array) ($body['error']['fields'] ?? []); }
/** A write under an action token (no session, no CSRF): [status, body, raw]. */
function act_token(string $path, array $form, array $headers): array { $r = req('POST', $path, ['headers' => array_merge(JSONH, $headers), 'form' => $form]); return [(int) $r['code'], json_decode($r['body'], true) ?? [], $r]; }
/** The base with a key (live or not), or null. */
function base_by_key(string $key): ?int { $v = one('SELECT id FROM knowledge_bases WHERE key = :k ORDER BY id DESC LIMIT 1', ['k' => $key]); return $v === false || $v === null ? null : (int) $v; }
function base_row_of(int $id): array { return q('SELECT * FROM knowledge_bases WHERE id = :id', ['id' => $id])[0] ?? []; }
/** mcp_bases as a member sees it: the base ids, sorted. */
function visible_to(int $member): array
{
    pdo()->exec("SELECT set_config('app.member_id', '$member', false)");
    $ids = array_map('intval', array_column(q('SELECT base_id FROM mcp_bases'), 'base_id'));
    pdo()->exec("SELECT set_config('app.member_id', '', false)");
    sort($ids);
    return $ids;
}
/** A base made through the handler as $member: its id. */
function make_base(int $member, string $name, array $more = []): int
{
    [$code, $b] = act(as_member($member), '/bases/save.php', ['name' => $name] + $more);
    if ($code !== 200) { fwrite(STDERR, "make_base $name: $code " . json_encode($b) . "\n"); return 0; }
    return (int) $b['record_id'];
}
/** A source row written by the test (slice 2 brings the screens): a live source in the base. */
function mksource(int $base, string $title = 'SMOKE source', int $by = PRIYA): int
{
    return (int) one("INSERT INTO sources (base_id, kind, title, origin, added_by, status) VALUES (:b, 'text', :t, 'paste', :by, 'ready') RETURNING id", ['b' => $base, 't' => $title, 'by' => $by]);
}
/** A section of a source, optionally holding an engine document id (as if built). */
function mksection(int $source, ?string $doc = null): int
{
    $base = (int) one('SELECT base_id FROM sources WHERE id = :s', ['s' => $source]);
    return (int) one("INSERT INTO sections (base_id, source_id, position, heading, heading_path, text, char_from, char_to, maludb_document_id, status, extracted_version)
                      VALUES (:b, :s, (SELECT COALESCE(max(position), 0) + 1 FROM sections WHERE source_id = :s), 'One', 'One', 'Some synthetic words.', 0, 21, :d, :st, :v) RETURNING id",
        ['b' => $base, 's' => $source, 'd' => $doc, 'st' => $doc === null ? 'queued' : 'extracted', 'v' => $doc === null ? null : 1]);
}
function notifications_of(int $member, string $kind = 'shared'): array { return q('SELECT * FROM notifications WHERE member_id = :m AND kind = :k ORDER BY id', ['m' => $member, 'k' => $kind]); }
