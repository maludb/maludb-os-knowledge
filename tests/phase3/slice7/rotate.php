<?php
/** Slice 7 proof — rotating and revoking keys (api-keys-k7.md "Proof", Rotate and Revoke). */
require __DIR__ . '/lib.php';

echo "5. Rotate\n";
$w = keys_world();
$m = $w['manuals'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [manual_script($w)]]);
$old = mint(CARA, $m, 'Rotating', ['focus_area' => $w['warranty'], 'rate_per_minute' => 25]);
$asked = api('POST', '/api/v1/ask', $old['raw'], ['question' => 'What does the warranty cover? (before rotating)']);
$oldQ = (int) $asked['body']['question_id'];
$since = last_activity_id();
[$c, $b] = act(as_member(CARA), '/keys/rotate.php', ['api_key' => $old['id']]);
$new = (int) ($b['record_id'] ?? 0);
$newRaw = (string) ($b['key'] ?? '');
$nr = key_row($new);
$or = key_row($old['id']);
ok($c === 200 && $new > 0 && $new !== $old['id'] && preg_match('/^mcp_[0-9a-f]{48}$/', $newRaw) === 1 && $newRaw !== $old['raw'] && (int) $b['old_key_id'] === $old['id'], 'rotate → a new key, shown once in the body, naming the old one');
ok((int) $nr['rotated_from'] === $old['id'] && $nr['label'] === 'Rotating' && (int) $nr['focus_area_id'] === $w['warranty'] && (int) $nr['rate_per_minute'] === 25 && (int) $nr['member_id'] === CARA && $nr['expires_at'] === null && $nr['revoked_at'] === null,
    'the new key: rotated_from set, the same label, area, limits and minter, no end');
$mins = (int) one("SELECT extract(epoch FROM (expires_at - now())) / 60 FROM mcp_access_tokens WHERE id = :i", ['i' => $old['id']]);
ok($or['expires_at'] !== null && $mins >= 24 * 60 - 2 && $mins <= 24 * 60, "the old key's expires_at = now + 24 h ($mins minutes)");
ok(api('GET', '/api/v1/bases', $old['raw'])['code'] === 200 && api('GET', '/api/v1/bases', $newRaw)['code'] === 200, 'both keys answer during the overlap');
$g = api('GET', '/api/v1/questions/' . $oldQ, $newRaw);
ok($g['code'] === 200 && $g['body']['question_id'] === $oldQ, 'the new key reads the old key\'s questions');
$ra = activity('key.rotate', $since);
$rr = json_decode($ra[0]['after'] ?? '{}', true);
ok(count($ra) === 1 && $rr['old_key_id'] === $old['id'] && $rr['new_key_id'] === $new && $rr['overlap_hours'] === 24 && (int) $ra[0]['base_id'] === $m && !in_log($newRaw) && !in_log(hash('sha256', $newRaw)), 'key.rotate logged with both ids and the overlap — never the key or its hash');
// the browser's copy-once box shows the end time
$jar = as_member(CARA);
$x = mint(CARA, $m, 'Browser rotate');
$r = act_html($jar, '/keys/rotate.php', ['api_key' => $x['id']]);
$p1 = page($jar, '/bases/' . $m . '/keys');
ok($r['code'] === 302 && str_contains($p1['body'], 'id="key-minted"') && str_contains($p1['body'], 'The old key stops working at') && preg_match('/id="key-minted-value"[^>]*value="mcp_[0-9a-f]{48}"/', $p1['body']) === 1, 'in the browser: the new key in the copy-once box with "The old key stops working at <time>"');
ok(!str_contains(page($jar, '/bases/' . $m . '/keys')['body'], 'key-minted'), '…and gone on reload');
$pg = $p1['body'];
ok(str_contains($pg, 'rotated from Browser rotate') && str_contains($pg, 'until '), 'the table says "rotated from …" and the old key "expiring until <time>"');
// past the overlap
pdo()->prepare("UPDATE mcp_access_tokens SET expires_at = now() - interval '1 minute' WHERE id = :i")->execute(['i' => $old['id']]);
$gone = api('GET', '/api/v1/bases', $old['raw']);
ok($gone['code'] === 401 && api('GET', '/api/v1/bases', $newRaw)['code'] === 200, 'with the clock past the overlap the old key → 401; the new one answers');
[$c, $b] = act(as_member(CARA), '/keys/rotate.php', ['api_key' => $old['id']]);
ok($c === 422 && str_contains(fields($b)['api_key'] ?? '', 'Only a live key is rotated'), 'rotating an expired key → "Only a live key is rotated": ' . msg($b));
[$c, $b] = act(as_member(PRIYA), '/keys/rotate.php', ['api_key' => $new]);
ok($c === 404, 'Priya cannot rotate a key (404: not hers to see)');

echo "6. Revoke\n";
$k = mint(CARA, $m, 'To revoke');
ok(api('GET', '/api/v1/bases', $k['raw'])['code'] === 200, 'a live key answers');
$since = last_activity_id();
[$c, $b] = act(as_member(CARA), '/keys/revoke.php', ['api_key' => $k['id']]);
ok($c === 200 && key_row($k['id'])['revoked_at'] !== null && api('GET', '/api/v1/bases', $k['raw'])['code'] === 401, 'revoked → 401 on the next call');
$rv = activity('key.revoke', $since);
ok(count($rv) === 1 && (int) $rv[0]['entity_id'] === $k['id'] && json_decode($rv[0]['after'], true)['label'] === 'To revoke', 'key.revoke logged');
[$c, $b] = act(as_member(CARA), '/keys/revoke.php', ['api_key' => $k['id']]);
ok($c === 422 && msg($b) === 'This key is already revoked.', 'revoking a revoked key is refused: ' . msg($b));
[$c, $b] = act(as_member(CARA), '/keys/rotate.php', ['api_key' => $k['id']]);
ok($c === 422 && str_contains(fields($b)['api_key'] ?? '', 'Only a live key is rotated'), 'rotating a revoked key → the function\'s words');
[$c] = act(as_member(CARA), '/keys/save.php', ['api_key' => $k['id'], 'label' => 'x']);
ok($c === 200, 'a revoked key\'s label can still be changed through the handler (history)');
ok(page(as_member(CARA), '/keys/' . $k['id'] . '/edit')['code'] === 422, '…but its edit form is refused');
// revoking one mid-overlap revokes only it
$o = mint(CARA, $m, 'Overlap');
[, $rb] = act(as_member(CARA), '/keys/rotate.php', ['api_key' => $o['id']]);
act(as_member(CARA), '/keys/revoke.php', ['api_key' => $o['id']]);
ok(api('GET', '/api/v1/bases', $o['raw'])['code'] === 401 && api('GET', '/api/v1/bases', (string) $rb['key'])['code'] === 200, 'revoking a key that is mid-overlap revokes only it: the new one keeps answering');
// a revoked grant shuts the door
$w2 = mint(CARA, $m, 'Minter withdrawn');
$cap = one('SELECT capability FROM members WHERE id = :m', ['m' => CARA]);
pdo()->prepare('UPDATE members SET capability = NULL WHERE id = :m')->execute(['m' => CARA]);
$a = api('GET', '/api/v1/bases', $w2['raw'])['code'];
pdo()->prepare('UPDATE members SET capability = :c WHERE id = :m')->execute(['c' => $cap, 'm' => CARA]);
ok($a === 401 && api('GET', '/api/v1/bases', $w2['raw'])['code'] === 200, 'the minter\'s capability withdrawn in the mirror → the key is 401; restored → it answers again');
$pv = make_base(CARA, 'SMOKE Private Keys', ['key' => 'smoke-private-keys']);
$pk = mint(CARA, $pv, 'Private key');
ok(api('GET', '/api/v1/bases', $pk['raw'])['code'] === 200, 'a key on a private base answers while its minter reaches the base');
pdo()->exec("UPDATE knowledge_bases SET owner_member_id = " . OWNER . " WHERE id = $pv");
pdo()->exec("DELETE FROM base_members WHERE base_id = $pv AND (principal_id = " . CARA . " OR principal_kind = 'member')");
$lost = api('POST', '/api/v1/ask', $pk['raw'], ['question' => 'Anything?']);
ok($lost['code'] === 404 && $lost['body']['error']['message'] === 'Knowledge base not found.' && api('GET', '/api/v1/bases', $pk['raw'])['code'] === 404, 'the minter removed from the private base → 404 on that base (the same sentence as a missing base)');
echo "7. The screen after\n";
$pg = page(as_member(CARA), '/bases/' . $m . '/keys')['body'];
ok(str_contains($pg, '>revoked<') && str_contains($pg, '>live<') && str_contains($pg, '>expiring<'), 'the table shows live, expiring and revoked keys');
ok(!str_contains($pg, 'id="key-' . $k['id'] . '-revoke"'), 'a revoked key has no Revoke or Rotate button');
finish();
