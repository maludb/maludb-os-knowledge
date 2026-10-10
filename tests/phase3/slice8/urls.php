<?php
/** Slice 8 proof — the weekly URL change checks (agents-feeds.md "Proof": urls.php), through slice 2's fetch seam: docs.example.test is the proof's web server (web.php). */
require __DIR__ . '/lib.php';

$w = agents_world();
$cara = as_member(CARA);
$pg = base_by_key('smoke-pages');
if ($pg === null) {
    $pg = make_base(CARA, 'SMOKE Pages', ['key' => 'smoke-pages']);
    pdo()->exec("UPDATE knowledge_bases SET auto_extract = false WHERE id = $pg");
}
pdo()->exec("UPDATE sources SET url_checked_at = now() WHERE kind = 'url' AND base_id <> $pg");      // the other proofs' address sources are not under test
web_state(['/page-stable.html' => 'ok', '/page-changed.html' => 'ok', '/page-gone.html' => 'ok', '/page-busy.html' => 'ok', '/page-notag.html' => 'ok-notag']);
$ids = [];
foreach (['stable', 'changed', 'gone', 'busy', 'notag'] as $k) {
    [$c, $b] = act($cara, '/sources/add.php', ['base' => $pg, 'kind' => 'url', 'url' => WEB . '/page-' . $k . '.html']);
    $ids[$k] = (int) ($b['record_id'] ?? 0);
    if ($c !== 200) { fwrite(STDERR, "add $k: $c " . json_encode($b) . "\n"); }
}
$ids['dead'] = mksrc($pg, 'SMOKE Dead page', 'ready', 'url', 'http://dead.example.test/page.html');
pdo()->exec("UPDATE sources SET url_checked_at = now() WHERE id = {$ids['dead']}");      // (a never-checked address would be checked by this very pass)
worker_at();
$src = static fn (string $k): array => q('SELECT * FROM sources WHERE id = :id', ['id' => $ids[$k]])[0] ?? [];
ok($src('stable')['status'] === 'ready' && $src('stable')['url_etag'] === '"v1"' && $src('notag')['status'] === 'ready' && $src('notag')['url_etag'] === null && $src('stable')['text_sha256'] !== null, 'the pages are fetched and read (ready; stable has the ETag "v1", notag has none)');
$checks = static function (?string $now = null, array $env = []): int { return preg_match('/url_checks (\d+)/', worker_at($now, $env), $m) ? (int) $m[1] : -1; };
$back = static fn (string $k, string $interval = '8 days') => pdo()->exec("UPDATE sources SET url_checked_at = now() - interval '$interval' WHERE id = {$ids[$k]}");

echo "1. A week later\n";
foreach (array_keys($ids) as $k) { $back($k); }
$back('busy', '3 days');
web_state(['/page-changed.html' => 'changed', '/page-gone.html' => '404', '/page-busy.html' => '503', '/page-notag.html' => 'ok-notag']);
file_put_contents(need('KN_WEB_STATE') . '.log', '');
$since = last_activity_id();
$n = $checks();
$log = file(need('KN_WEB_STATE') . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
ok($n === 5, 'the pass checks five (the one checked 3 days ago is skipped): ' . $n);
ok(count(array_filter($log, static fn ($l) => str_contains($l, 'page-busy'))) === 0, 'the page checked 3 days ago was not even fetched');
$s = $src('stable');
ok($s['url_changed_at'] === null && strtotime((string) $s['url_checked_at']) > time() - 60 && (int) $s['url_failed_checks'] === 0, 'an unchanged page (the same ETag) → only url_checked_at');
$s = $src('changed');
ok($s['url_changed_at'] !== null && strtotime((string) $s['url_checked_at']) > time() - 60 && $s['status'] === 'ready', 'a changed body → url_changed_at set, the source untouched otherwise');
$ev = activity('source.url_changed', $since);
$byId = [];
foreach ($ev as $e) { $byId[(int) $e['entity_id']] = after_of($e) + ['source' => $e['source']]; }
ok(isset($byId[$ids['changed']]) && $byId[$ids['changed']]['vanished'] === false && $byId[$ids['changed']]['source'] === 'cron', 'source.url_changed (vanished false), source cron');
$s = $src('gone');
ok($s['url_changed_at'] !== null && isset($byId[$ids['gone']]) && $byId[$ids['gone']]['vanished'] === true && (int) $byId[$ids['gone']]['status'] === 404, 'a 404 → url_changed_at, vanished true, status 404');
$s = $src('notag');
ok($s['url_changed_at'] === null && strtotime((string) $s['url_checked_at']) > time() - 60, 'a page with no ETag and the same words → unchanged (the text\'s hash is the test)');
$s = $src('dead');
ok($s['url_changed_at'] === null && (int) $s['url_failed_checks'] === 1 && strtotime((string) $s['url_checked_at']) > time() - 60 && !isset($byId[$ids['dead']]), 'one fetch_failed → nothing but the check dated (failed once)');
ok(count($ev) === 2 && $src('busy')['url_changed_at'] === null, 'two source.url_changed rows, no others');
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND (coalesce(before::text, '') || coalesce(after::text, '')) ~ '(Field notes|page-changed|docs.example.test)'", ['s' => $since]) === 0, 'no page text and no address in a row of this pass');

echo "2. Another week: a second failure, a 503, the text hash\n";
foreach (array_keys($ids) as $k) { $back($k); }
web_state(['/page-busy.html' => '503', '/page-notag.html' => 'changed', '/page-changed.html' => 'changed', '/page-gone.html' => '404']);
$since = last_activity_id();
$n = $checks();
$ev = activity('source.url_changed', $since);
$byId = [];
foreach ($ev as $e) { $byId[(int) $e['entity_id']] = after_of($e); }
$s = $src('dead');
ok($n === 6 && isset($byId[$ids['dead']]) && $byId[$ids['dead']]['vanished'] === true && $s['url_changed_at'] !== null, 'a second fetch_failed in a row → vanished true');
$s = $src('busy');
ok($s['url_changed_at'] === null && strtotime((string) $s['url_checked_at']) > time() - 60 && !isset($byId[$ids['busy']]), 'a 503 → only url_checked_at (tried again next week)');
ok(isset($byId[$ids['notag']]) && $byId[$ids['notag']]['vanished'] === false, 'a page with no ETag whose words changed → changed (by the text\'s hash)');
ok((int) one("SELECT count(*) FROM sources WHERE base_id = :b AND superseded_by IS NOT NULL", ['b' => $pg]) === 0 && (int) one("SELECT count(*) FROM sources WHERE base_id = :b AND status = 'forgotten'", ['b' => $pg]) === 0 && (int) one('SELECT count(*) FROM sources WHERE base_id = :b', ['b' => $pg]) === 6, 'nothing is replaced or removed automatically');
$n = $checks();
ok($n === 0, 'a pass at once checks nothing: they were all checked just now');

echo "3. Turned off\n";
foreach (array_keys($ids) as $k) { $back($k); }
file_put_contents(need('KN_WEB_STATE') . '.log', '');
$n = $checks(null, ['KN_URL_FETCH' => 'off']);
ok($n === 0 && trim((string) @file_get_contents(need('KN_WEB_STATE') . '.log')) === '', 'KN_URL_FETCH=off → the step does nothing, no request is made');
pdo()->exec('UPDATE kn_settings SET url_fetch_allowed = false');
$n = $checks();
ok($n === 0 && trim((string) @file_get_contents(need('KN_WEB_STATE') . '.log')) === '', 'url_fetch_allowed false → the same');
pdo()->exec('UPDATE kn_settings SET url_fetch_allowed = true');
$n = $checks();
ok($n === 6, 'switched on again → they are checked (' . $n . ')');
echo "4. The Archivist's cue\n";
$h = page($cara, '/sources/' . $ids['changed'])['body'];
ok(page($cara, '/sources/' . $ids['changed'])['code'] === 200 && str_contains($h, 'SMOKE Page page-changed.html') || str_contains($h, 'page-changed'), 'the source page opens (a curator replaces from there)');
finish();
