<?php
/** Slice 2 proof — an address (sources-sections.md "Proof", bullet 4; "Fetching an address"). The worker reaches docs.example.test through the dev map. */
require __DIR__ . '/lib.php';

echo "4. Address\n";
$bid = world()['base'];
$priya = as_member(PRIYA);
$add = static function (string $url, string $title = '') use ($priya, $bid): array {
    [$c, $b] = act($priya, '/sources/add.php', ['base' => $bid, 'kind' => 'url', 'url' => $url] + ($title !== '' ? ['title' => $title] : []));
    return [$c, $b, (int) ($b['record_id'] ?? 0)];
};
[$c, $b, $page] = $add(WEB . '/page.html');
ok($c === 200 && $page > 0, 'docs.example.test/page.html is added (the dev map resolves it to a public address)');
$adds = activity('source.add');
$a = json_decode(end($adds)['after'], true);
ok($a['kind'] === 'url' && $a['origin'] === WEB . '/page.html', 'source.add carries the address as its origin');
[$c, $b, $pdf] = $add(WEB . '/report.pdf');
[, , $local] = $add(WEB . '/to-local');
[, , $six] = $add(WEB . '/r/6');
[, , $three] = $add(WEB . '/r/3', 'SMOKE Three hops');
[, , $big] = $add(WEB . '/big');
[, , $missing] = $add(WEB . '/missing');
[, , $flaky] = $add(WEB . '/flaky');
[, , $image] = $add(WEB . '/image.png');
@unlink(sys_get_temp_dir() . '/kn-s2-web-flaky');
[$c, $b] = $add('http://127.0.0.1/');
ok($c === 422 && (fields($b)['url'] ?? '') === 'That address is not public, or it does not resolve.', 'http://127.0.0.1/ → the field\'s sentence at add time');
[$c, $b] = $add('http://10.0.0.5/handbook');
ok($c === 422 && isset(fields($b)['url']), 'a private address → the same');
[$c, $b] = $add(WEB . '/page.html');
ok($c === 422 && str_contains((string) (fields($b)['url'] ?? ''), 'already here as'), 'the same address again → refused, naming it');
$t0 = microtime(true);
$line = worker();
ok(str_contains($line, 'sources 9'), 'one pass fetches them (' . $line . ', ' . round(microtime(true) - $t0, 1) . ' s)');
$s = source_of($page);
$att = q('SELECT filename, mime_type, storage_path FROM attachments WHERE id = :a', ['a' => (int) $s['attachment_id']])[0] ?? [];
ok($s['status'] === 'ready' && $s['title'] === 'SMOKE Field Guide', 'the page is ready, with the page\'s title ("' . $s['title'] . '")');
$paths = array_column(sections_of($page), 'heading_path');
ok(in_array('SMOKE Field Guide › Field Guide › Ladders', $paths, true) && in_array('SMOKE Field Guide › Field Guide › Gloves', $paths, true), 'its headings make its sections: ' . implode(' | ', $paths));
ok(($att['mime_type'] ?? '') === 'text/html' && is_file(APP_DIR_STORAGE . '/' . ($att['storage_path'] ?? 'x')), 'the fetched bytes are kept as its attachment (' . ($att['filename'] ?? '') . ')');
ok($s['url_etag'] === '"smoke-etag-1"' && $s['url_checked_at'] !== null, 'url_etag and url_checked_at are set');
$p = source_of($pdf);
ok($p['status'] === 'ready' && (int) one('SELECT count(*) FROM source_pages WHERE source_id = :s', ['s' => $pdf]) === 2, 'a PDF address is read as a PDF (two pages)');
$want = [[$local, 'fetch_refused', 'a redirect to 127.0.0.1'], [$six, 'fetch_redirects', 'six redirects'], [$big, 'fetch_too_large', '11 MB'],
         [$missing, 'fetch_status:404', 'a page answering 404'], [$image, 'fetch_unsupported', 'an image']];
foreach ($want as [$sid, $code, $what]) {
    $x = source_of($sid);
    ok($x['status'] === 'failed' && $x['error'] === $code, "$what → failed / $code ({$x['status']} {$x['error']})");
}
ok(str_contains(page($priya, '/sources/' . $missing)['body'], 'The page answered 404.'), '"The page answered 404." on its page');
ok(source_of($three)['status'] === 'ready' && source_of($three)['title'] === 'SMOKE Three hops', 'three redirects are followed; a typed title is kept');
ok(source_of($flaky)['status'] === 'ready', 'a 500 then a 200 → ready after one in-pass retry');
// a page that sleeps 25 s: 20 s in all
[, , $slow] = $add(WEB . '/slow');
$t0 = microtime(true);
worker(['KN_WORKER_DEADLINE' => '25']);
$x = source_of($slow);
ok($x['status'] === 'failed' && $x['error'] === 'fetch_timeout' && microtime(true) - $t0 < 30, 'a page that sleeps 25 s → fetch_timeout after 20 s (' . round(microtime(true) - $t0, 1) . ' s)');
// fetching turned off: the setting
pdo()->exec('UPDATE kn_settings SET url_fetch_allowed = false');
$form = page($priya, '/sources/new?base=' . $bid . '&tab=url')['body'];
ok(!str_contains($form, 'id="source-add-tab-url"') && str_contains($form, 'Fetching pages is turned off.') && !str_contains($form, 'id="source-url-form"'), 'url_fetch_allowed false → the tab is hidden');
[$c, $b] = $add(WEB . '/page.html?again=1');
ok($c === 422 && (fields($b)['url'] ?? '') === 'Fetching pages is turned off.', '…and a forged POST is refused');
pdo()->exec('UPDATE kn_settings SET url_fetch_allowed = true');
[, , $later] = $add(WEB . '/page.html?env=off');
worker(['KN_URL_FETCH' => 'off']);
ok(source_of($later)['error'] === 'fetch_disabled', 'KN_URL_FETCH=off: the worker refuses to fetch (fetch_disabled)');
// fetching turned off: the environment of the application
$envf = need('KN_DEV_ENV');
file_put_contents($envf, "KN_URL_FETCH=off\n", FILE_APPEND);
shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && tests/phase2/servers.sh start >/dev/null 2>&1');
$form = page($priya, '/sources/new?base=' . $bid . '&tab=url')['body'];
[$c, $b] = $add(WEB . '/page.html?forged=1');
ok(!str_contains($form, 'id="source-add-tab-url"') && $c === 422 && (fields($b)['url'] ?? '') === 'Fetching pages is turned off.', 'KN_URL_FETCH=off in the application: the tab hidden and a forged POST refused');
file_put_contents($envf, preg_replace('/^KN_URL_FETCH=off\n/m', '', (string) file_get_contents($envf)));
shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 3)) . ' && tests/phase2/servers.sh start >/dev/null 2>&1');
[$c, $b, $prod] = $add(WEB . '/page.html?prod=1');
ok($c === 200, 'fetching is back on once the environment says nothing');
worker(['APP_ENV' => 'prod']);
ok(source_of($prod)['error'] === 'fetch_failed', 'with APP_ENV=prod the dev map is ignored: the name does not resolve → fetch_failed');
ok(!plant_in_log(), 'the planted phrase is in no activity_log row');
finish();
