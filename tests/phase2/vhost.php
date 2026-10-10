<?php
/*
 * Proof: the deploy vhost's URL rules (deploy/apache-knowledge.conf). Meaningful under KN_APP=apache tests/phase2/run.sh, where a real
 * Apache serves the template rendered as the installer renders it; under php -S the same checks run against tests/dev_router.php,
 * which mirrors the rewrites. Nothing outside html/ is reachable, /api is blocked but for health (the questions API's names are rewritten
 * to files slice 7 builds — 404 until then), the MCP proxies answer 502 until Phase 4 (under Apache), the attachment door needs a session.
 */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(27);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) {
    ok(req('GET', $path)['code'] === $want, "GET $path → $want (the receivers answer at their extension-less names)");
}
ok(page($j, '/settings/tokens/')['code'] === 200 && page($j, '/settings/tokens')['code'] === 200, '/settings/tokens/ and /settings/tokens both reach settings/tokens/index.php');
ok(page($j, '/settings/')['code'] === 200 && page($j, '/settings')['code'] === 200, '/settings/ and /settings both reach settings/index.php');
ok(page($j, '/bases/')['code'] === 200 && page($j, '/bases')['code'] === 200, '/bases/ and /bases both reach bases/index.php');
ok(page($j, '/ask')['code'] === 200 && page($j, '/pinned')['code'] === 200, '/ask and /pinned reach ask/index.php and pinned/index.php (slice 5)');
ok(page($j, '/admin/')['code'] === 403 && page($j, '/admin')['code'] === 403, '/admin/ and /admin resolve to admin/index.php (403 for a curator, by the right)');
ok(page($j, '/activity')['code'] === 200 && page($j, '/notifications')['code'] === 200, '/activity and /notifications resolve to their own files');
ok(page($j, '/bases/new')['code'] === 200 && page($j, '/bases/1')['code'] === 200 && page($j, '/bases/1/edit')['code'] === 200 && page($j, '/bases/1/share')['code'] === 200,
    '/bases/new, /bases/1, /bases/1/edit and /bases/1/share are rewritten to form.php, view.php and share.php (slice 1)');
ok(str_contains(page($j, '/bases/999999')['body'], 'Knowledge base not found.'), 'a base that does not exist reaches view.php, which says so');
ok(page($j, '/bases/1/sources')['code'] === 200 && page($j, '/bases/1/nosuchpage')['code'] === 404 && page($j, '/sources/7/outline')['code'] === 404,
    'a page of a record is rewritten to its own file: /bases/1/sources (slice 2) answers; a page with no file (/bases/1/nosuchpage, /sources/7/outline) is 404');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
ok(req('GET', '/files/1')['code'] === 401, '/files/1 without a session: 401 (never a redirect — a file URL sits in an <img>)');
ok(page($j, '/files/1')['code'] === 404, '/files/1 with a session: 404 (no attachment yet)');
$h = req('GET', '/api/v1/health');
ok($h['code'] === 200 && json_decode($h['body'], true)['application'] === 'knowledge', '/api/v1/health answers');
ok(req('GET', '/api/v1/health.php')['code'] === 200, '/api/v1/health.php answers too');
ok(req('POST', '/api/v1/ask')['code'] === 404 && req('GET', '/api/v1/bases')['code'] === 404 && req('GET', '/api/v1/questions/1')['code'] === 404, 'the questions API (/api/v1/ask, /bases, /questions/{id}) is rewritten to files slice 7 builds: 404 until then');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404, 'anything else under /api is 404');
if (getenv('KN_APP') === 'apache') {
    ok(in_array(req('GET', '/mcp/records')['code'], [502, 503], true) && in_array(req('GET', '/mcp/activity')['code'], [502, 503], true), 'the MCP proxies are in the vhost and answer 502 until Phase 4 starts the servers');
    $i = req('GET', 'http://127.0.0.1:8507/api/v1/health');
    ok($i['code'] === 200 && json_decode($i['body'], true)['application'] === 'knowledge', 'the internal vhost (the kernel\'s actions server\'s door) answers health on its loopback port');
}
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1 && json_decode($m['body'], true)['name'] === 'Knowledge', '/manifest.webmanifest is served as JSON and names Knowledge');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/app/knowledge/engine.php', '/bin/directory_sync.php', '/mcp/db.py', '/CLAUDE.md', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php', '/deploy/ROOT_STEPS.sh', '/storage/attachments/x'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE'), "$p is not served ({$r['code']})");
}
finish();
