<?php
/** Slice 4 proof — the estimate and the start button's reasons (builds.md "Proof", bullet 1). */
require __DIR__ . '/lib.php';

echo "1. The estimate\n";
$w = world4();
$bid = $w['base'];
cancel_live($bid);
$priya = as_member(PRIYA);
[$c, $d] = screen($priya, "/bases/$bid/builds");
$e = $d['estimate'] ?? [];
$want = q("SELECT count(*) AS n, COALESCE(sum(length(text)), 0) AS chars, COALESCE(sum(GREATEST(ceil(length(text) / 1800.0), 1)), 0) AS calls FROM sections WHERE base_id = :b AND status IN ('queued', 'stale', 'failed')", ['b' => $bid])[0];
ok($c === 200 && (int) $e['sections'] === (int) $want['n'] && (int) $want['n'] > 0, "the card's sections equal kn_build_estimate()'s ({$e['sections']})");
ok((int) $e['characters'] === (int) $want['chars'] && (int) $e['calls'] === (int) $want['calls'], "its characters ({$e['characters']}) and \"about {$e['calls']} engine calls\" = SUM(CEIL(n / 1800))");
ok((int) $e['month_used_chars'] === 0 && (int) $e['month_limit_chars'] === 50 * 1048576 && $e['within_limit'] === true, 'this month: 0 used of the 50 MB limit, within it');
ok(array_key_exists('blocker', $d) && $d['blocker'] === null && (int) ($d['rebuild_all']['sections'] ?? 0) === count(sections_by($bid)), 'nothing blocks a build; Rebuild everything counts every live section');
$h = page($priya, "/bases/$bid/builds")['body'];
ok(str_contains($h, 'id="build-estimate"') && preg_match('/id="build-start"\s*>/', $h) === 1 && str_contains($h, 'id="builds-table"') && str_contains($h, 'auto-extract is off'), 'the page: the estimate card, the start button enabled, the builds table, auto-extract as a line');
ok(str_contains($h, 'id="build-estimate-month">0 of 52,428,800'), 'the month line: 0 of 52,428,800 characters');
foreach ([REID => 403, BEA => 404] as $m => $code) {
    ok(page(as_member($m), "/bases/$bid/builds")['code'] === $code, "member $m → $code");
}
[$c, $b] = start(REID, $bid);
ok($c === 403, 'Reid (a reader) may not start a build');

echo "   The start button disabled with each reason\n";
$blocker = static function (int $base) use ($priya): array {
    [, $d] = screen($priya, "/bases/$base/builds");
    $h = page($priya, "/bases/$base/builds")['body'];
    return [(string) ($d['blocker'] ?? ''), preg_match('/id="build-start" disabled/', $h) === 1];
};
pdo()->exec("INSERT INTO builds (base_id, kind) VALUES ($bid, 'extract')");
[$why, $dis] = $blocker($bid);
ok($why === 'A build is already running.' && $dis, 'a build running: "' . $why . '"');
[$c, $b] = start(PRIYA, $bid);
ok($c === 422 && msg($b) === 'A build is already running.', 'and a start is refused in those words');
try { pdo()->exec("INSERT INTO builds (base_id, kind) VALUES ($bid, 'extract')"); $ref = false; } catch (PDOException $x) { $ref = $x->getCode() === '23505'; }
ok($ref, 'the database refuses a second live build (unique_violation)');
cancel_live($bid);
pdo()->exec("INSERT INTO ai_usage (base_id, month, kind, calls, chars) VALUES ($bid, date_trunc('month', now())::date, 'extract', 1, " . (50 * 1048576) . ')');
[$why, $dis] = $blocker($bid);
ok($why === 'This month\'s text limit for this base is spent.' && $dis, 'the month spent: "' . $why . '"');
[$c, $b] = start(PRIYA, $bid);
ok($c === 422 && msg($b) === $why, '…and a start is refused');
pdo()->exec("DELETE FROM ai_usage WHERE base_id = $bid");
as_postgres("UPDATE \"malu\$memory_extraction_config\" SET namespace = 'kb:0-off' WHERE namespace = 'kb:0'", 'engine');
[$why, $dis] = $blocker($bid);
ok($why === BUILD_ERRORS['model_not_configured'] && $dis, 'no kb:0: "' . $why . '"');
[$c, $b] = start(PRIYA, $bid);
ok($c === 422 && msg($b) === $why, '…and a start is refused');
as_postgres("UPDATE \"malu\$memory_extraction_config\" SET namespace = 'kb:0' WHERE namespace = 'kb:0-off'", 'engine');
ok(kn_compile_connection()['ok'], 'kb:0 back');
$empty = curated_base('SMOKE Empty Builds', 'smoke-empty-builds');
[$why, $dis] = $blocker($empty);
ok($why === 'Nothing to build — every section is in the graph under version ' . base_version($empty) . '.' && $dis, 'nothing to build: "' . $why . '"');
[$c, $b] = start(PRIYA, $empty);
ok($c === 422 && msg($b) === $why, '…and a start is refused');
act($priya, '/bases/archive.php', ['base' => $empty]);
[$c, $b] = start(PRIYA, $empty);
ok($c === 422 && str_contains(msg($b), 'is archived'), 'an archived base: "' . msg($b) . '"');
[$why] = $blocker($empty);
ok($why === 'An archived base is not built.', 'its card says so');
finish();
