<?php
/**
 * Helpers for the slice 3 proofs (docs/build-specs/guidelines-areas.md "Proof"). Run through tests/phase3/slice3/run.sh: a fresh SCRATCH database
 * (kn_dev3), the application on :8501, the fake kernel (:8502), the REAL MaluDB API 0.4.0 on a scratch memory database (:8503) with the fake
 * provider (:8504), the fake MaluDB + MaluMail (:8506). The world: "SMOKE Field Manuals" (Priya owns it; Marco curates; Reid reads; Ann a guest;
 * Seamus an agent with curate) with a pasted manual cut into twelve or more sections, twelve of them ingested for real and marked extracted at
 * version 1 — so staleness shows. kb:0 (the engine connection) is configured with the fake provider and a dummy secret.
 */
require dirname(__DIR__) . '/slice1/lib.php';
if (!defined('APP_ROOT')) { define('APP_ROOT', dirname(__DIR__, 3)); }
require_once APP_ROOT . '/app/knowledge/compile.php';
require_once APP_ROOT . '/app/knowledge/areas.php';

function worker(array $env = []): string
{
    $pre = '';
    foreach ($env as $k => $v) { $pre .= escapeshellarg($k . '=' . $v) . ' '; }
    return trim((string) shell_exec('cd ' . escapeshellarg(APP_ROOT) . ' && env ' . $pre . 'php bin/worker.php 2>&1'));
}
function engine(string $what): string { return trim((string) shell_exec(escapeshellarg(dirname(__DIR__) . '/engine.sh') . ' ' . escapeshellarg($what) . ' 2>&1')); }
function fake(string $path, ?string $method = null): array { return json_decode((string) @file_get_contents(need('KN_PROOF_FAKE_URL') . $path, false, $method ? stream_context_create(['http' => ['method' => $method]]) : null), true) ?: []; }

/** kb:0 — the business's engine connection — configured with the fake provider (the owner's step); idempotent. */
function ensure_reference(): void
{
    if (kn_compile_connection()['ok']) { return; }
    kn_engine_config_namespace('kb:0', ['provider_name' => 'kb-fake', 'provider_kind' => 'local_http', 'model_alias' => 'kb-extract', 'model_identifier' => 'fake-model',
        'base_url' => need('KN_PROOF_FAKE_URL') . '/v1', 'embedding_model' => 'maludb-local-dev', 'secret_name' => 'knowledge_provider', 'token' => need('KN_PROOF_PROVIDER_SECRET'),
        'prompt_template' => "Reference connection.\n\nText:\n{{chunk}}"]);
}

/** The pasted manual: twelve headed sections, each naming things the vocabulary and the areas will use. */
function manual_text(): string
{
    $parts = ['Calibration' => 'Zorblat Pump requires quarterly calibration by a certified technician.', 'Filters' => 'Merrow Filter traps particles larger than five microns.',
        'Valves' => 'Quillon Valve controls the inlet pressure of the Zorblat Pump.', 'Warranty' => 'Zorblat Pump carries a warranty of 24 months; a claim needs the serial number.',
        'Seals' => 'Pump Housing seals are replaced every 2,000 hours.', 'Safety' => 'Zorblat Pump must be isolated before maintenance.', 'Returns' => 'Returns are accepted within 30 days.',
        'Shipping' => 'Shipping Desk sends parts within two days.', 'Training' => 'Training Office runs a course every quarter.', 'Spares' => 'Spare Kit holds two seals and a gasket.',
        'Noise' => 'Zorblat Pump runs below sixty decibels.', 'Disposal' => 'Merrow Filter is disposed of as general waste.'];
    $out = "# Pump Service Manual\n\nThis manual covers the pump line.\n\n";
    foreach ($parts as $h => $p) { $out .= "## $h\n\n$p " . str_repeat('Keep the record with the unit. ', 8) . "\n\n"; }
    return $out;
}

/** The world (idempotent): the base, its people, the manual read and cut, twelve sections ingested and extracted at v1. ['base', 'source', 'sections' => [ids]] */
function world(): array
{
    ensure_reference();
    // the expert of the spec holds the Curator role (the kernel's grant; the fixture gives Seamus Member only)
    pdo()->exec("UPDATE members SET roles = '{curator,user}' WHERE id = " . SEAMUS . " AND NOT ('curator' = ANY (roles))");
    $id = base_by_key('smoke-field-manuals');
    if ($id === null) {
        $id = make_base(PRIYA, 'SMOKE Field Manuals', ['key' => 'smoke-field-manuals']);
        $p = as_member(PRIYA);
        act($p, '/bases/members/add.php', ['base' => $id, 'member' => MARCO, 'level' => 'curate']);
        act($p, '/bases/members/add.php', ['base' => $id, 'member' => REID, 'level' => 'read']);
        act($p, '/bases/members/add-guest.php', ['base' => $id, 'guest' => ANN]);
        act($p, '/bases/members/add.php', ['base' => $id, 'member' => SEAMUS, 'level' => 'curate']);
        act($p, '/sources/add.php', ['base' => $id, 'kind' => 'text', 'title' => 'SMOKE Pump Service Manual', 'text' => manual_text()]);
        act($p, '/sources/add.php', ['base' => $id, 'kind' => 'text', 'title' => 'SMOKE Returns Policy', 'text' => "# Returns\n\nReturns are accepted within 30 days of delivery."]);
        act($p, '/sources/add.php', ['base' => $id, 'kind' => 'text', 'title' => 'SMOKE Training Notes', 'text' => "# Training\n\nTraining Office runs the pump course."]);
        worker();
        kn_compile_send(pdo(), $id);
        $src = (int) one("SELECT id FROM sources WHERE base_id = :b AND title = 'SMOKE Pump Service Manual'", ['b' => $id]);
        foreach (q('SELECT id, heading_path, text FROM sections WHERE source_id = :s ORDER BY position LIMIT 12', ['s' => $src]) as $x) {
            $r = kn_engine_ingest_section('kb:' . $id, $x['heading_path'], $x['text'], ['base' => $id, 'source' => $src, 'section' => (int) $x['id']]);
            $doc = (int) ($r['data']['document_id'] ?? 0);
            if ($doc > 0) {
                pdo()->exec("UPDATE sections SET maludb_document_id = '$doc', status = 'extracted', extracted_version = 1 WHERE id = " . (int) $x['id']);
            }
        }
    }
    $src = (int) one("SELECT id FROM sources WHERE base_id = :b AND title = 'SMOKE Pump Service Manual'", ['b' => $id]);
    return ['base' => $id, 'source' => $src, 'sections' => array_map('intval', array_column(q("SELECT id FROM sections WHERE source_id = :s AND extracted_version = 1 ORDER BY position", ['s' => $src]), 'id'))];
}
function base_version(int $id): int { return (int) one('SELECT guideline_version FROM knowledge_bases WHERE id = :b', ['b' => $id]); }
function stale_count(int $id): int { return (int) one("SELECT count(*) FROM sections WHERE base_id = :b AND status = 'stale'", ['b' => $id]); }
function guideline_of(int $base, string $kind): ?array { return q('SELECT * FROM guidelines WHERE base_id = :b AND kind = :k ORDER BY position, id LIMIT 1', ['b' => $base, 'k' => $kind])[0] ?? null; }
/** A body's words in any activity row? */
function words_in_log(string $words): bool { return (int) one("SELECT count(*) FROM activity_log WHERE coalesce(before::text, '') || coalesce(after::text, '') LIKE :w", ['w' => '%' . $words . '%']) > 0; }
