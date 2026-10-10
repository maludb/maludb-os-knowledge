<?php
/** Slice 7 proof — minting, changing and listing keys (api-keys-k7.md "Proof", Mint). */
require __DIR__ . '/lib.php';

echo "1. Mint\n";
$w = keys_world();
$m = $w['manuals'];
$ct = $w['contracts'];
[$c, $b] = act(as_member(CARA), '/keys/mint.php', ['base' => $m, 'label' => 'Support site']);
$id = (int) ($b['record_id'] ?? 0);
$raw = (string) ($b['key'] ?? '');
$row = key_row($id);
ok($c === 200 && $id > 0 && preg_match('/^mcp_[0-9a-f]{48}$/', $raw) === 1 && $b['location'] === '/bases/' . $m . '/keys#key-' . $id, 'Cara mints "Support site": ok, the key is mcp_ + 48 hex, once, in the body; location ends in the key id');
ok($row['scope'] === 'api' && (int) $row['base_id'] === $m && (int) $row['member_id'] === CARA && (int) $row['rate_per_minute'] === 30 && (int) $row['rate_per_day'] === 2000 && $row['focus_area_id'] === null,
    'the row: scope api, bound to Manuals, minted by Cara, the defaults 30 a minute and 2,000 a day filled by the trigger, the whole base');
ok(one('SELECT token_hash FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) === hash('sha256', $raw), 'the row stores only the sha256 of the key');
$mint = activity('key.mint');
$ma = json_decode(end($mint)['after'], true);
ok(count($mint) === 1 && (int) end($mint)['entity_id'] === $id && (int) end($mint)['base_id'] === $m && $ma['label'] === 'Support site' && $ma['key_id'] === $id && array_key_exists('rate_per_minute', $ma),
    'key.mint logged: the key id as the entity, base_id, the label, the area and the limits');
ok(!in_log($raw) && !in_log(hash('sha256', $raw)) && !str_contains((string) one("SELECT string_agg(route, ' ') FROM activity_log"), $raw), 'the raw key and its hash are in no activity row');
// the browser's way: the copy-once box
$jar = as_member(CARA);
$r = act_html($jar, '/keys/mint.php', ['base' => $m, 'label' => 'Browser key']);
ok($r['code'] === 302 && preg_match('~^/bases/' . $m . '/keys#key-\d+$~', $r['location']) === 1, 'a browser is redirected to /bases/{id}/keys#key-{id}: ' . $r['location']);
ok(!str_contains($r['location'], 'mcp_') && !str_contains($r['headers'], 'mcp_'), 'the key is in no URL and no header');
$p1 = page($jar, '/bases/' . $m . '/keys');
preg_match('/id="key-minted-value"[^>]*value="(mcp_[0-9a-f]{48})"/', $p1['body'], $mm);
ok(isset($mm[1]) && str_contains($p1['body'], 'Shown once') && str_contains($p1['body'], 'id="key-minted-copy"') && str_contains($p1['headers'], 'no-store'), 'the page shows the key once in the copy-once box with Copy (no-store)');
$p2 = page($jar, '/bases/' . $m . '/keys');
ok(!str_contains($p2['body'], 'key-minted') && !str_contains($p2['body'], $mm[1] ?? 'zzz'), 'a reload does not show it again');
$jj = screen($jar, '/bases/' . $m . '/keys')[1];
ok(!str_contains(json_encode($jj), 'mcp_') && !str_contains(json_encode($jj), 'token_hash') && count($jj['keys']) >= 2, 'the screen as JSON lists the keys with no value and no hash');
// refusals
$arch = make_base(CARA, 'SMOKE Archived Keys', ['key' => 'smoke-archived-keys']);
act(as_member(CARA), '/bases/archive.php', ['base' => $arch]);
$n0 = (int) one("SELECT count(*) FROM mcp_access_tokens WHERE scope = 'api'");
[$c1, $b1] = act(as_member(CARA), '/keys/mint.php', ['base' => $arch, 'label' => 'x']);
ok($c1 === 422 && msg($b1) === 'An archived base gets no keys.', 'an archived base is refused: ' . msg($b1));
$other = (int) one("INSERT INTO focus_areas (base_id, name, slug, status) VALUES (:b, 'Other', 'other', 'active') RETURNING id", ['b' => $ct]);
[$c2, $b2] = act(as_member(CARA), '/keys/mint.php', ['base' => $m, 'label' => 'Wrong area', 'focus_area' => $other]);
ok($c2 === 422 && isset(fields($b2)['focus_area']) && str_contains(fields($b2)['focus_area'], 'area of its base'), 'an area of base 2 → the trigger\'s words on the field: ' . (fields($b2)['focus_area'] ?? ''));
[$c3, $b3] = act(as_member(CARA), '/keys/mint.php', ['base' => $m, 'label' => 'Fast', 'rate_per_minute' => 100]);
ok($c3 === 422 && fields($b3)['rate_per_minute'] === 'A key\'s limit is at most the default (30 a minute) unless an admin raises it.', 'a per-minute limit of 100 by Cara → 422: ' . (fields($b3)['rate_per_minute'] ?? ''));
[$c4, $b4] = act(as_member(CARA), '/keys/mint.php', ['base' => $m, 'label' => 'Busy', 'rate_per_day' => 5000]);
ok($c4 === 422 && isset(fields($b4)['rate_per_day']), 'a per-day limit above 2,000 by Cara → 422');
[$c5, $b5] = act(as_member(OWNER), '/keys/mint.php', ['base' => $m, 'label' => 'Fast by the admin', 'rate_per_minute' => 100, 'rate_per_day' => 5000, 'focus_area' => $w['warranty']]);
$ar = key_row((int) $b5['record_id']);
ok($c5 === 200 && (int) $ar['rate_per_minute'] === 100 && (int) $ar['rate_per_day'] === 5000 && (int) $ar['focus_area_id'] === $w['warranty'] && (int) $ar['member_id'] === OWNER, 'by the admin: saved with 100 / 5,000 and bound to Warranty');
[$c6] = act(as_member(PRIYA), '/keys/mint.php', ['base' => $m, 'label' => 'Priya']);
[$c7] = act(as_member(DEE), '/keys/mint.php', ['base' => $ct, 'label' => 'Dee']);
ok($c6 === 403 && $c7 === 404, 'Priya (a Member, reads the base) → 403; Dee on the private base → 404');
[$c8, $b8] = act(as_member(CARA), '/keys/mint.php', ['base' => $m, 'label' => '']);
[$c9, $b9] = act(as_member(CARA), '/keys/mint.php', ['base' => $m, 'label' => str_repeat('x', 81)]);
ok($c8 === 422 && isset(fields($b8)['label']) && $c9 === 422 && isset(fields($b9)['label']), 'a label of 0 or 81 characters → 422 fields.label');
[$c10, $b10] = act(as_member(CARA), '/keys/mint.php', ['base' => $m, 'label' => 'Bad', 'rate_per_minute' => 0]);
ok($c10 === 422 && isset(fields($b10)['rate_per_minute']), 'a limit of 0 → 422');
ok((int) one("SELECT count(*) FROM mcp_access_tokens WHERE scope = 'api'") === $n0 + 1, 'no refused mint left a row (only the admin\'s own mint did)');

echo "2. Change (key_save)\n";
$k = mint(CARA, $m, 'Editable');
[$c, $b] = act(as_member(CARA), '/keys/save.php', ['api_key' => $k['id'], 'label' => 'Renamed', 'focus_area' => $w['returns']]);
$row = key_row($k['id']);
$sv = activity('key.save');
$sa = json_decode(end($sv)['after'], true);
$sb = json_decode(end($sv)['before'], true);
ok($c === 200 && $row['label'] === 'Renamed' && (int) $row['focus_area_id'] === $w['returns'] && $sb['label'] === 'Editable' && $sa['label'] === 'Renamed' && (int) end($sv)['entity_id'] === $k['id'], 'key_save changes the label and the area; key.save logs before and after');
[$c, $b] = act(as_member(CARA), '/keys/save.php', ['api_key' => $k['id'], 'base' => $ct, 'label' => 'Renamed']);
ok($c === 200 && (int) key_row($k['id'])['base_id'] === $m, 'a body naming another base changes nothing: a key is bound to one base for life');
$tok = ['X-Action-Token: ' . person_token(CARA)];
[$c, $b] = act_token('/keys/save.php', ['api_key' => $k['id'], 'rate_per_minute' => 20, '_partial' => 1], $tok);
$row = key_row($k['id']);
ok($c === 200 && $row['label'] === 'Renamed' && (int) $row['rate_per_minute'] === 20 && (int) $row['focus_area_id'] === $w['returns'] && (int) $row['rate_per_day'] === 2000, 'key_save with _partial=1 keeps the label, the area and the day limit when only a limit is sent');
[$c, $b] = act(as_member(CARA), '/keys/save.php', ['api_key' => $k['id'], 'focus_area' => '']);
ok($c === 200 && key_row($k['id'])['focus_area_id'] === null, 'an empty area is the whole base');
[$c, $b] = act(as_member(CARA), '/keys/save.php', ['api_key' => $k['id'], 'rate_per_minute' => 500]);
ok($c === 422 && isset(fields($b)['rate_per_minute']), 'raising a limit above the default is the admin\'s, on an edit too');
// an admin's higher limit survives a curator's rename
[, $hi] = act(as_member(OWNER), '/keys/mint.php', ['base' => $m, 'label' => 'High', 'rate_per_minute' => 200]);
[$c] = act(as_member(CARA), '/keys/save.php', ['api_key' => $hi['record_id'], 'label' => 'High renamed']);
ok($c === 200 && (int) key_row((int) $hi['record_id'])['rate_per_minute'] === 200, 'an admin\'s higher limit survives a curator renaming the key (only a change is refused)');
[$c] = act(as_member(PRIYA), '/keys/save.php', ['api_key' => $k['id'], 'label' => 'Priya was here']);
ok($c === 404 && key_row($k['id'])['label'] === 'Renamed', 'Priya (not a curator) → 404: a key outside her curated bases does not exist to her');
[$c404] = act(as_member(CARA), '/keys/save.php', ['api_key' => 999999, 'label' => 'x']);
ok($c404 === 404, 'key_save with an unknown key id → 404');

echo "3. The screens\n";
[$c, $d] = screen(as_member(CARA), '/bases/' . $m . '/keys');
ok($c === 200 && isset($d['keys'], $d['defaults']) && $d['defaults']['rate_per_minute'] === 30 && $d['api_url'] === 'http://127.0.0.1:8501/api/v1/ask', 'keys: the screen as JSON with the defaults and the API URL');
ok(page(as_member(PRIYA), '/bases/' . $m . '/keys')['code'] === 403 && page(as_member(DEE), '/bases/' . $ct . '/keys')['code'] === 404, 'keys: Priya → 403 in words, Dee on the private base → 404');
$pg = page(as_member(CARA), '/bases/' . $m . '/keys')['body'];
ok(str_contains($pg, 'id="keys-table"') && str_contains($pg, 'id="keys-cards"') && str_contains($pg, 'id="key-' . $k['id'] . '"') && str_contains($pg, 'id="keys-api-curl"') && str_contains($pg, 'Bearer KEY') && str_contains($pg, 'id="base-tab-keys"'),
    'the page: the table, the cards, the row, the curl example with KEY, the Keys tab lit');
ok(page(as_member(CARA), '/keys/new?base=' . $m)['code'] === 200 && page(as_member(CARA), '/keys/' . $k['id'] . '/edit')['code'] === 200 && page(as_member(CARA), '/keys/new?base=' . $arch)['code'] === 422, 'key-add and key-edit answer; the form for an archived base is refused');
$ff = page(as_member(CARA), '/keys/new?base=' . $m)['body'];
ok(str_contains($ff, 'placeholder="30"') && str_contains($ff, 'placeholder="2000"') && str_contains($ff, 'Warranty') && str_contains($ff, 'Returns') && !str_contains($ff, 'Other'), 'the mint form: label, the areas of this base only, the defaults as placeholders');
[$c, $d] = screen(as_member(PRIYA), '/keys/new?base=' . $m);
ok($c === 403, 'the mint form for Priya → 403');
$act = activity('screen.view');
ok(count(array_filter($act, static fn ($r) => in_array(json_decode($r['after'] ?? '{}', true)['base_id'] ?? 0, [$m], true))) >= 1, 'screen.view is logged for the keys screens');
finish();
