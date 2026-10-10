<?php
/**
 * The slice 8 proof's web server: php -S 127.0.0.1:8505 tests/phase3/slice8/web.php — reached by the worker as docs.example.test through the dev map (KN_URL_FETCH_TEST_MAP). What each
 * path answers is $KN_WEB_STATE (JSON the proof rewrites between checks): {"/page-x.html": "ok" | "changed" | "404" | "410" | "503"} — a path not named answers "ok". Synthetic pages only.
 *   ok       an HTML page with a title, a heading and an ETag from the path ("v1")          changed   the same page with other words, ETag "v2"          404 · 410 · 503   the status
 *   ok-notag the page without an ETag (the check then compares the text)                     same-text the page with ETag "v9" and the words of "ok"
 */
require dirname(__DIR__, 2) . '/sectioning/fixtures.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state = json_decode((string) @file_get_contents((string) getenv('KN_WEB_STATE')), true) ?: [];
$mode = $state[$path] ?? 'ok';
file_put_contents((string) getenv('KN_WEB_STATE') . '.log', $path . ' ' . $mode . "\n", FILE_APPEND);
if (in_array($mode, ['404', '410', '503'], true)) {
    http_response_code((int) $mode);
    header('Content-Type: text/html');
    echo '<html><body>' . $mode . '</body></html>';
    return;
}
$words = $mode === 'changed' ? fx_para(41, 500) : fx_para(40, 500);
header('Content-Type: text/html; charset=utf-8');
if ($mode === 'ok' || $mode === 'changed' || $mode === 'same-text') {
    header('ETag: "' . ($mode === 'changed' ? 'v2' : ($mode === 'same-text' ? 'v9' : 'v1')) . '"');
}
echo '<!doctype html><html lang="en"><head><title>SMOKE Page ' . htmlspecialchars(basename($path)) . '</title></head><body><main><h1>Field notes</h1><p>' . $words . '</p><h2>Details</h2><p>' . fx_para(42, 300) . '</p></main></body></html>';
