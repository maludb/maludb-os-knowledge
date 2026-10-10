<?php
/** Slice 7 proof — JSON mode and agents (api-keys-k7.md "Proof", JSON mode). */
require __DIR__ . '/lib.php';

echo "12. JSON mode\n";
$w = keys_world();
$m = $w['manuals'];
$shape = static fn (array $b): bool => ($b['ok'] ?? false) === true && isset($b['did'], $b['record_id'], $b['location']);
$cara = ['X-Action-Token: ' . person_token(CARA)];
[$c, $b, $raw] = act_token('/keys/mint.php', ['base' => $m, 'label' => 'JSON key', 'rate_per_minute' => 10], $cara);
$id = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b) && $b['location'] === '/bases/' . $m . '/keys#key-' . $id && preg_match('/^mcp_[0-9a-f]{48}$/', (string) ($b['key'] ?? '')) === 1, 'key_mint under a signed action token answers {ok, did, record_id, location, key} — the value once');
ok(!str_contains($raw['headers'], 'mcp_') && !str_contains($b['did'], 'mcp_') && !in_log((string) $b['key']) && api('GET', '/api/v1/bases', (string) $b['key'])['code'] === 200, 'the key is in no header (X-Action-Data included), no sentence and no log row; and it works');
[, $re] = act_token('/keys/mint.php', ['base' => $m, 'label' => 'JSON key'], $cara);
ok(preg_match('/^mcp_[0-9a-f]{48}$/', (string) $re['key']) === 1 && $re['key'] !== $b['key'], 'a second mint is a different key');
[$c, $e] = act_token('/keys/mint.php', ['base' => $m, 'label' => ''], $cara);
ok($c === 422 && $e['error']['code'] === 'invalid' && isset($e['error']['fields']['label']), '422 {error: {code: invalid, fields}} for a missing label');
[$c, $e] = act_token('/keys/mint.php', ['base' => $m, 'label' => 'Too fast', 'rate_per_minute' => 900], $cara);
ok($c === 422 && isset($e['error']['fields']['rate_per_minute']), '422 for a limit above the default');
[$c, $b2] = act_token('/keys/save.php', ['api_key' => $id, 'rate_per_day' => 500, '_partial' => 1], $cara);
$row = key_row($id);
ok($c === 200 && $shape($b2) && $row['label'] === 'JSON key' && (int) $row['rate_per_day'] === 500 && (int) $row['rate_per_minute'] === 10, 'key_save with _partial=1 keeps the label and the minute limit when only the day limit is sent');
[$c, $b3] = act_token('/keys/save.php', ['api_key' => $id, 'label' => 'JSON key renamed'], $cara);
ok($c === 200 && $shape($b3) && key_row($id)['label'] === 'JSON key renamed' && (int) key_row($id)['rate_per_day'] === 500, 'without _partial a field left out is also kept (the label changed, the limits stayed)');
[$c, $b4] = act_token('/keys/rotate.php', ['api_key' => $id], $cara);
$new = (int) ($b4['record_id'] ?? 0);
ok($c === 200 && $shape($b4) && $new !== $id && (int) $b4['old_key_id'] === $id && !empty($b4['old_expires_at']) && preg_match('/^mcp_[0-9a-f]{48}$/', (string) $b4['key']) === 1 && (int) key_row($new)['rotated_from'] === $id, 'key_rotate answers the new key (once) and the old key\'s end');
[$c, $b5] = act_token('/keys/revoke.php', ['api_key' => $new], $cara);
ok($c === 200 && $shape($b5) && $b5['record_id'] === $new && key_row($new)['revoked_at'] !== null, 'key_revoke answers its facts');
[$c, $e] = act_token('/keys/revoke.php', ['api_key' => $new], $cara);
ok($c === 422, 'revoking again → 422');
// who may
$priya = ['X-Action-Token: ' . person_token(PRIYA)];
[$c1] = act_token('/keys/mint.php', ['base' => $m, 'label' => 'Priya'], $priya);
[$c2] = act_token('/keys/rotate.php', ['api_key' => $id], $priya);
ok($c1 === 403 && $c2 === 404, 'Priya under a token: key_mint → 403, key_rotate → 404 (the key is not hers to see)');
$agent = as_agent(run_token(SEAMUS, 7701));
[$c, $e] = act_token('/keys/mint.php', ['base' => $m, 'label' => 'Agent key'], $agent);
ok($c === 403, 'an agent reading the base (not curating it) cannot mint: the same gate (403)');
// the registry
$reg = json_decode(file_get_contents(APP_ROOT . '/mcp/action_registry.json'), true);
$acts = ['key_mint', 'key_save', 'key_rotate', 'key_revoke'];
$screens = ['keys', 'key-add', 'key-edit', 'connections'];
$ba = count(array_filter($acts, static fn ($x) => !empty($reg['actions'][$x]['built'])));
$bs = count(array_filter($screens, static fn ($x) => !empty($reg['screens'][$x]['built'])));
ok($ba === 4 && $bs === 4, "the registry reads the 4 screens and 4 actions of this slice built ($bs, $ba)");
$ap = static fn (string $a) => $reg['actions'][$a]['approval'] ?? null;
ok($ap('key_mint') === 'external_send' && $ap('key_rotate') === 'external_send' && $ap('key_revoke') === 'deletion' && empty($reg['actions']['key_save']['approval']), 'approvals as the manifest says: key_mint and key_rotate external_send, key_revoke deletion, key_save none');
$mf = json_decode(file_get_contents(APP_ROOT . '/maludb-os.json'), true);
$byAction = [];
foreach ($mf['approvals'] as $a) { $byAction[$a['action']] = $a['category']; }
ok(($byAction['key_mint'] ?? '') === 'external_send' && ($byAction['key_rotate'] ?? '') === 'external_send' && ($byAction['key_revoke'] ?? '') === 'deletion' && !isset($byAction['key_save']), 'maludb-os.json approvals[] lists key_mint and key_rotate (external_send) and key_revoke (deletion), not key_save');
ok(str_contains(file_get_contents(APP_ROOT . '/app/partial_update.php'), "'/keys/save.php' => ['mcp_access_tokens', 'api_key', 'mcp_keys', 'key_id']"), 'PARTIAL_UPDATE_TARGETS names /keys/save.php (api_key, mcp_keys)');
// the activity log never carries a key
ok(!str_contains((string) one("SELECT string_agg(coalesce(before::text, '') || coalesce(after::text, ''), ' ') FROM activity_log"), 'mcp_'), 'no activity row anywhere carries an mcp_ value');
finish();
