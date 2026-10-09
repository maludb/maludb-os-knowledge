<?php
/**
 * The slice 2 proof's web server: php -S 127.0.0.1:8505 tests/phase3/slice2/web.php — reached by the worker as docs.example.test through the dev
 * map (KN_URL_FETCH_TEST_MAP), so a public-looking name resolves to a public address while the bytes come from here. Synthetic pages only.
 *   /page.html     an HTML page with a title, headings and the planted phrase; an ETag
 *   /report.pdf    a two-page PDF with text                /image.png   an image (not a document we read)
 *   /to-local      302 → http://127.0.0.1/                 /r/{n}       n redirects, then page.html
 *   /big           11 MB of HTML                           /missing     404
 *   /flaky         500 the first time, then a page          /slow        sleeps 25 s
 */
require dirname(__DIR__, 2) . '/sectioning/fixtures.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$plant = 'ZEBRA-PLINTH-7741';
$state = sys_get_temp_dir() . '/kn-s2-web-flaky';
switch (true) {
    case $path === '/page.html':
        header('Content-Type: text/html; charset=utf-8');
        header('ETag: "smoke-etag-1"');
        echo '<!doctype html><html lang="en"><head><title>SMOKE Field Guide</title></head><body><nav>Menu Home About</nav><main>'
            . '<h1>Field Guide</h1><p>' . $plant . ' ' . fx_para(21, 500) . '</p><h2>Ladders</h2><p>' . fx_para(22, 500) . '</p><h2>Gloves</h2><p>' . fx_para(23, 400) . '</p></main></body></html>';
        break;
    case $path === '/report.pdf':
        header('Content-Type: application/pdf');
        echo fx_pdf([fx_wrap($plant . ' ' . fx_para(24, 600)), fx_wrap(fx_para(25, 600))]);
        break;
    case $path === '/image.png':
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        break;
    case $path === '/to-local':
        header('Location: http://127.0.0.1/', true, 302);
        break;
    case (bool) preg_match('#^/r/(\d+)$#', $path, $m):
        $n = (int) $m[1];
        header('Location: ' . ($n <= 1 ? '/page.html' : '/r/' . ($n - 1)), true, 302);
        break;
    case $path === '/big':
        header('Content-Type: text/html');
        echo '<html><body><p>';
        for ($i = 0; $i < 11 * 1024; $i++) { echo str_repeat('x', 1023), "\n"; }
        echo '</p></body></html>';
        break;
    case $path === '/flaky':
        if (!is_file($state)) {
            touch($state);
            http_response_code(500);
            echo 'try again';
            break;
        }
        @unlink($state);
        header('Content-Type: text/html');
        echo '<html><head><title>SMOKE Flaky Page</title></head><body><h1>Second time lucky</h1><p>' . $plant . ' ' . fx_para(26, 300) . '</p></body></html>';
        break;
    case $path === '/slow':
        sleep(25);
        header('Content-Type: text/html');
        echo '<html><body>late</body></html>';
        break;
    default:
        http_response_code(404);
        header('Content-Type: text/html');
        echo '<html><body>Not here</body></html>';
}
