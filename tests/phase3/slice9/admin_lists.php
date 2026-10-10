<?php
/** Slice 9 proof — every base, every key (home-admin.md "Proof": admin_lists.php). */
require __DIR__ . '/lib.php';

$w = home_world();
$hb = $w['handbook']; $pol = $w['policies']; $pv = $w['private']; $emp = $w['empty'];
$owner = as_member(OWNER);
pdo()->exec('DELETE FROM questions');                       // only this proof's questions are counted
old_question($hb, PRIYA, 'SMOKE list question one', 3600);
old_question($hb, PRIYA, 'SMOKE list question two', 7200);
old_question($hb, PRIYA, 'SMOKE list question old', 40 * 86400);

echo "1. Every base\n";
$since = last_activity_id();
$r = page($owner, '/admin/bases');
$h = $r['body'];
$bad = [];
foreach ([$hb, $pol, $pv, $emp] as $b) { if (!str_contains($h, 'id="admin-base-row-' . $b . '"') || !str_contains($h, 'id="admin-base-card-' . $b . '"')) { $bad[] = $b; } }
ok($r['code'] === 200 && $bad === [], 'a row at 1280 and a card at 375 for every base — the private ones too');
ok(substr_count($h, 'feather-lock') >= 2 && str_contains($h, 'id="admin-base-row-' . $pv . '-visibility"'), 'a private base is marked with the lock');
ok(activity('base.admin_view', $since) === [] && count(activity('screen.view', $since)) === 1, 'the list itself logs nothing but the screen');
[$c, $d] = screen($owner, '/admin/bases');
$by = array_column($d['bases'], null, 'base_id');
ok($c === 200 && count($d['bases']) === 4 && $by[$hb]['questions_30d'] === 2 && $by[$hb]['owner_name'] === 'SMOKE Marco' && $by[$pv]['visibility'] === 'private' && $by[$hb]['source_count'] >= 1, 'JSON: name, owner, visibility, status, sources, sections, questions in 30 days (2 — the old one not counted)');
[$c, $d] = screen($owner, '/admin/bases?visibility=private');
ok(count($d['bases']) === 2 && array_column($d['bases'], 'visibility') === ['private', 'private'], 'filter by visibility: private → 2');
[$c, $d] = screen($owner, '/admin/bases?status=empty');
ok($d['bases'] !== [] && count(array_unique(array_column($d['bases'], 'status'))) === 1 && $d['bases'][0]['status'] === 'empty', 'filter by status');
pdo()->exec("UPDATE knowledge_bases SET archived_at = now() WHERE id = $emp");
[$c, $d] = screen($owner, '/admin/bases');
$n1 = count($d['bases']);
[$c, $d] = screen($owner, '/admin/bases?archived=1');
$arch = array_column($d['bases'], null, 'base_id');
ok($n1 === 3 && count($d['bases']) === 4 && $arch[$emp]['archived'] === true, 'archived bases appear only with archived=1');
pdo()->exec("UPDATE knowledge_bases SET archived_at = NULL WHERE id = $emp");
$since = last_activity_id();
$r = page($owner, '/bases/' . $pv . '?back=%2Fadmin%2Fbases');
$ev = activity('base.admin_view', $since);
ok($r['code'] === 200 && count($ev) === 1 && (int) $ev[0]['base_id'] === $pv, 'opening the private base from the list logs base.admin_view once (slice 1\'s rule)');
ok(str_contains($h, 'href="/bases/' . $pv . '?back=%2Fadmin%2Fbases"'), '…the name links with ?back= to the list');
$since = last_activity_id();
page(as_member(CARA), '/bases/' . $pv);
ok(activity('base.admin_view', $since) === [], 'its owner opening it logs nothing');
foreach ([CARA => 'a Curator', PRIYA => 'a Member'] as $m => $who) { ok(page(as_member($m), '/admin/bases')['code'] === 403, "$who → 403"); }

echo "2. Every key\n";
su("DELETE FROM mcp_access_tokens WHERE scope = 'api'");          // (the application's role cannot delete a token; postgres can)
$k1 = mint(CARA, $hb, 'SMOKE admin key one');
$k2 = mint(CARA, $pol, 'SMOKE admin key two');
$k3 = mint(CARA, $pv, 'SMOKE admin key three');
[$c, $b] = act(as_member(CARA), '/keys/revoke.php', ['api_key' => $k3['id']]);
ok($k1['status'] === 200 && $k2['id'] > 0 && $c === 200, 'three keys minted on three bases; one revoked at its base');
$r = page($owner, '/admin/keys');
$h = $r['body'];
ok($r['code'] === 200 && str_contains($h, 'id="admin-key-row-' . $k1['id'] . '"') && str_contains($h, 'id="admin-key-card-' . $k2['id'] . '"') && str_contains($h, 'id="admin-key-row-' . $k3['id'] . '"'), 'every key of every base, as rows and cards');
ok(!str_contains($h, $k1['raw']) && !str_contains($h, hash('sha256', $k1['raw'])), 'never the key\'s value or hash');
[$c, $d] = screen($owner, '/admin/keys');
$ks = array_column($d['keys'], null, 'key_id');
ok(count($d['keys']) === 3 && $ks[$k1['id']]['base'] === 'SMOKE Handbook' && $ks[$k1['id']]['status'] === 'live' && $ks[$k3['id']]['status'] === 'revoked' && array_key_exists('calls_today', $ks[$k1['id']]) && !array_key_exists('raw', $ks[$k1['id']]), 'JSON: base, label, area, limits, today\'s count, last used, status');
[$c, $d] = screen($owner, '/admin/keys?base=' . $pol);
ok(count($d['keys']) === 1 && $d['keys'][0]['key_id'] === $k2['id'], 'filter by base');
[$c, $d] = screen($owner, '/admin/keys?status=revoked');
ok(count($d['keys']) === 1 && $d['keys'][0]['key_id'] === $k3['id'], 'filter by status');
ok(!str_contains(page($owner, '/admin/keys?status=revoked')['body'], 'id="admin-key-row-' . $k3['id'] . '-revoke"'), 'a revoked key has no Revoke');
$r = api('GET', '/api/v1/bases', $k1['raw']);
ok($r['code'] === 200, 'the key works before (GET /api/v1/bases → 200)');
$r = act_html(as_member(OWNER), '/keys/revoke.php', ['api_key' => $k1['id'], 'return_to' => '/admin/keys']);
ok($r['code'] === 302 && str_starts_with($r['location'], '/admin/keys?notice=revoked#admin-key-row-' . $k1['id']), 'Revoke from the list lands back on the list (' . $r['location'] . ')');
$r = api('GET', '/api/v1/bases', $k1['raw']);
ok($r['code'] === 401, '…and the key answers 401 on its next call');
$h = page($owner, '/admin/keys?notice=revoked')['body'];
ok(str_contains($h, 'Revoked — the key answers 401 from now on.'), 'the notice reads on the list');
$r = page($owner, '/admin/keys?status=live');
ok(str_contains($r['body'], 'id="admin-key-row-' . $k2['id'] . '"') && !str_contains($r['body'], 'id="admin-key-row-' . $k1['id'] . '"'), 'a revoked key leaves the live filter');
[$c, $b] = act($owner, '/keys/revoke.php', ['api_key' => $k2['id'], 'return_to' => '//evil.example/']);
ok($c === 200 && str_starts_with($b['location'], '/bases/' . $pol . '/keys'), 'a return_to that is not a local path is ignored');
foreach ([CARA => 'a Curator', PRIYA => 'a Member'] as $m => $who) { ok(page(as_member($m), '/admin/keys')['code'] === 403, "$who → 403 (keys.all)"); }
ok(page(as_member(AVERY), '/admin/keys')['code'] === 200, 'a second admin may');
finish();
