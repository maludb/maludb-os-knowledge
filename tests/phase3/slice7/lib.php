<?php
/**
 * Helpers for the slice 7 proofs (docs/build-specs/api-keys-k7.md "Proof"). Run through tests/phase3/slice7/run.sh: a fresh SCRATCH database (kn_dev7), the
 * application on :8501 (php -S with the API rewrites, or KN_APP=apache), the fake kernel (:8502), THE FAKE ENGINE (:8503), the fake MaluDB + MaluMail (:8506).
 * Builds on the slice 5 helpers (kb_state, ev, m1, cite, ask_as, php_run, …). The cast (the spec's names → the fixture's): Priya (26, Member), Cara → Marco (27,
 * Curator), Dee → Bea (28, Member), Gus → Ann (29, a guest), Reid (32, Reader), the admin (1), Seamus (40, an agent).
 * The world (keys_world()): "SMOKE Manuals" (internal; Marco's) with the source "Product manual" cut into sections whose two named ones — "… › Warranty › Coverage"
 * (document 701, page 2) and "… › Returns › Window" (document 702, page 5) — are extracted, the areas Warranty (project 71) and Returns (project 72), shared to the
 * application principal `helpdesk` (a reader); "SMOKE Contracts" (private; Marco's) with one section (document 801), shared to nothing.
 */
require dirname(__DIR__) . '/slice5/lib.php';

const KEY_SENTINEL = 'AXOLOTL-SENTINEL-7719';

function manual_text(): string
{
    $pad = str_repeat(' Keep the receipt with the product.', 6);
    return "# Warranty\n\nWhat the warranty covers.$pad\n\n## Coverage\n\nThe warranty covers defects in materials for two years from the day of purchase.$pad\n\n"
         . "# Returns\n\nHow to send a product back.$pad\n\n## Window\n\nA product may be returned within thirty days of purchase, unused and in its box.$pad\n";
}

/** The world (idempotent): ['manuals', 'contracts', 'cov', 'win', 'contracts_section', 'warranty' (area id), 'returns' (area id)]. */
function keys_world(): array
{
    $m = base_by_key('smoke-manuals');
    if ($m === null) {
        $c = as_member(CARA);
        $m = make_base(CARA, 'SMOKE Manuals', ['key' => 'smoke-manuals']);
        act($c, '/bases/visibility.php', ['base' => $m, 'visibility' => 'internal']);
        act($c, '/sources/add.php', ['base' => $m, 'kind' => 'text', 'title' => 'Product manual', 'text' => manual_text()]);
        $ct = make_base(CARA, 'SMOKE Contracts', ['key' => 'smoke-contracts']);
        act($c, '/sources/add.php', ['base' => $ct, 'kind' => 'text', 'title' => 'Contract', 'text' => "# Contract\n\nThe terms of the supply contract." . str_repeat(' Confidential.', 20)]);
        pdo()->exec("UPDATE knowledge_bases SET auto_extract = false WHERE id IN ($m, $ct)");
        worker_pass();
        $sec = static fn (string $h, int $b) => (int) one('SELECT id FROM sections WHERE base_id = :b AND heading = :h ORDER BY id LIMIT 1', ['b' => $b, 'h' => $h]);
        $set = static fn (int $id, string $doc, ?int $page) => pdo()->prepare("UPDATE sections SET status = 'extracted', extracted_version = (SELECT guideline_version FROM knowledge_bases k WHERE k.id = sections.base_id),
            maludb_document_id = :d, page_no = COALESCE(:p, page_no) WHERE id = :s")->execute(['d' => $doc, 'p' => $page, 's' => $id]);
        $set($sec('Coverage', $m), '701', 2);
        $set($sec('Window', $m), '702', 5);
        $set((int) one('SELECT id FROM sections WHERE base_id = :b ORDER BY id LIMIT 1', ['b' => $ct]), '801', 1);
        foreach ([['Warranty', 'warranty', '71'], ['Returns', 'returns', '72']] as [$n, $s, $p]) {
            pdo()->prepare("INSERT INTO focus_areas (base_id, name, slug, description, example_questions, maludb_project_id, status) VALUES (:b, :n, :s, :d, '{\"What does the warranty cover?\"}', :p, 'active')")
                ->execute(['b' => $m, 'n' => $n, 's' => $s, 'd' => $n . ' questions.', 'p' => $p]);
        }
        act($c, '/bases/members/add-application.php', ['base' => $m, 'application' => 'helpdesk', 'level' => 'read']);
    }
    $ct = (int) base_by_key('smoke-contracts');
    $sec = static fn (string $h, int $b) => (int) one('SELECT id FROM sections WHERE base_id = :b AND heading = :h ORDER BY id LIMIT 1', ['b' => $b, 'h' => $h]);
    return ['manuals' => $m, 'contracts' => $ct, 'cov' => $sec('Coverage', $m), 'win' => $sec('Window', $m),
            'contracts_section' => (int) one('SELECT id FROM sections WHERE base_id = :b ORDER BY id LIMIT 1', ['b' => $ct]),
            'warranty' => (int) one("SELECT id FROM focus_areas WHERE base_id = :b AND slug = 'warranty'", ['b' => $m]), 'returns' => (int) one("SELECT id FROM focus_areas WHERE base_id = :b AND slug = 'returns'", ['b' => $m])];
}

/** The two-citation answer of the Manuals to "warranty" (sections by metadata), with KEY_SENTINEL in the answer and a passage. */
function manual_script(array $w, string $match = 'warranty'): array
{
    $m = $w['manuals'];
    $evid = [ev(1, 701, 'The warranty covers defects in materials for two years ' . KEY_SENTINEL . '.', ['base' => $m, 'section' => $w['cov'], 'page' => 2]),
             ev(2, 702, 'A product may be returned within thirty days of purchase.', ['base' => $m, 'section' => $w['win'], 'page' => 5], 0.71)];
    return ['match' => $match, 'answer' => m1('answered', $evid, [cite(1, 'The warranty covers defects in materials', 701), cite(2, 'returned within thirty days of purchase', 702)],
        'Defects are covered for two years [1]; returns take thirty days [2]. ' . KEY_SENTINEL)];
}

/** Mint a key through the handler as $member (JSON): ['id', 'raw', status, body]. */
function mint(int $member, int $base, string $label = 'SMOKE key', array $more = []): array
{
    [$c, $b] = act(as_member($member), '/keys/mint.php', ['base' => $base, 'label' => $label] + $more);
    return ['status' => $c, 'body' => $b, 'id' => (int) ($b['record_id'] ?? 0), 'raw' => (string) ($b['key'] ?? '')];
}
/** A call of the token API with a bearer key (or none): ['code', 'body' (decoded), 'headers', 'raw']. $body: an array sent as JSON. */
function api(string $method, string $path, ?string $bearer, ?array $body = null, array $extra = []): array
{
    $h = array_merge(['Accept: application/json'], $bearer !== null ? ['Authorization: Bearer ' . $bearer] : [], $extra);
    $o = ['headers' => $h];
    if ($body !== null) { $o['json'] = $body; }
    $r = req($method, $path, $o);
    return ['code' => (int) $r['code'], 'body' => json_decode($r['body'], true) ?? [], 'headers' => $r['headers'], 'raw' => $r['body']];
}
/** The stored key row (never the hash). */
function key_row(int $id): array { return q('SELECT id, member_id, label, scope, base_id, focus_area_id, rate_per_minute, rate_per_day, rotated_from, expires_at, revoked_at FROM mcp_access_tokens WHERE id = :id', ['id' => $id])[0] ?? []; }
function health_cache_clear(): void { @unlink(dirname(__DIR__, 3) . '/storage/cache/health-engine.json'); }
/** PHP in the CLI bootstrap with NO member (the share door has none): its stdout. */
function php_anon(string $code, mixed $arg = null): string
{
    $prog = 'require "app/bootstrap.php"; require_once APP_ROOT . "/app/knowledge/share.php"; $pdo = db(); $arg = json_decode($argv[1], true); ' . $code;
    return (string) shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && php -r ' . escapeshellarg($prog) . ' ' . escapeshellarg(json_encode($arg)) . ' 2>&1');
}
/** A share function called in-process: its document. */
function share_call(string $fn, ?string $consumer, array $args = []): array
{
    $out = php_anon('echo json_encode(' . $fn . '($pdo, $arg["c"]' . ($fn === 'kn_share_bases_index' ? '' : ', $arg["a"]') . '));', ['c' => $consumer, 'a' => $args]);
    return json_decode($out, true) ?? ['php_error' => $out];
}
/** The share.read rows since an activity id, decoded. */
function share_reads_since(int $since): array { return array_map(static fn ($r) => json_decode($r['after'], true) + ['base_id' => $r['base_id'], 'source' => $r['source'], 'actor' => $r['actor_member_id']], activity('share.read', $since)); }
