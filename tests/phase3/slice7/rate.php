<?php
/** Slice 7 proof — the rate (api-keys-k7.md "Proof", The rate). */
require __DIR__ . '/lib.php';

echo "8. The rate\n";
$w = keys_world();
$m = $w['manuals'];
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [manual_script($w)]]);
$k = mint(CARA, $m, 'Rate key');
while ((int) date('s') > 20) { usleep(500000); }             // 33 calls must fall inside one clock minute
$since = last_activity_id();
$codes = [];
for ($i = 1; $i <= 30; $i++) { $codes[] = api('GET', '/api/v1/bases', $k['raw'])['code']; }
ok($codes === array_fill(0, 30, 200), '30 calls in one minute → 200 each');
$r31 = api('GET', '/api/v1/bases', $k['raw']);
$ra = (int) (preg_match('/^Retry-After: (\d+)/mi', $r31['headers'], $mm) ? $mm[1] : 0);
ok($r31['code'] === 429 && $ra >= 1 && $ra <= 60 && $r31['body']['error']['code'] === 'rate_limited' && $r31['body']['error']['limit'] === 'minute' && $r31['body']['error']['message'] === 'Too many calls (minute).', 'the 31st → 429 with Retry-After ' . $ra . ' and {code rate_limited, limit minute}');
$lim = activity('key.rate_limited', $since);
$la = json_decode($lim[0]['after'] ?? '{}', true);
ok(count($lim) === 1 && (int) $lim[0]['token_id'] === $k['id'] && $la['limit_hit'] === 'minute' && $la['key_label'] === 'Rate key' && $lim[0]['source'] === 'api' && (int) $lim[0]['base_id'] === $m, 'key.rate_limited logged once (limit_hit, key_label, the token)');
$day0 = (int) one("SELECT calls FROM key_usage WHERE token_id = :t AND bucket_kind = 'day' ORDER BY bucket_start DESC LIMIT 1", ['t' => $k['id']]);
api('GET', '/api/v1/bases', $k['raw']);
$r33 = api('GET', '/api/v1/bases', $k['raw']);
$day1 = (int) one("SELECT calls FROM key_usage WHERE token_id = :t AND bucket_kind = 'day' ORDER BY bucket_start DESC LIMIT 1", ['t' => $k['id']]);
$ref = (int) one("SELECT refused FROM key_usage WHERE token_id = :t AND bucket_kind = 'minute' ORDER BY bucket_start DESC LIMIT 1", ['t' => $k['id']]);
ok($r33['code'] === 429 && count(activity('key.rate_limited', $since)) === 1, 'the 32nd and 33rd → 429 and no further log row (a flood writes one row a minute)');
ok($day0 === 30 && $day1 === 30 && $ref === 3, "the day bucket is unchanged by the refused calls ($day0 → $day1 of 30), the minute bucket counts 3 refused ($ref)");
ok(api('POST', '/api/v1/ask', $k['raw'], ['question' => 'warranty?'])['code'] === 429 && api('GET', '/api/v1/questions/1', $k['raw'])['code'] === 429, 'every route of the API is counted (ask and questions/{id} refused too)');
ok(api('GET', '/api/v1/health', null)['code'] === 200, 'health is not counted');
// the day
$d = mint(CARA, $m, 'Day key');
pdo()->prepare('UPDATE mcp_access_tokens SET rate_per_day = 3 WHERE id = :i')->execute(['i' => $d['id']]);
$since = last_activity_id();
$cs = [];
for ($i = 1; $i <= 4; $i++) { $x = api('GET', '/api/v1/bases', $d['raw']); $cs[] = $x['code']; }
ok($cs === [200, 200, 200, 429] && $x['body']['error']['limit'] === 'day' && (int) preg_match('/^Retry-After: (\d+)/mi', $x['headers'], $mm) === 1 && (int) $mm[1] >= 1, 'a key with rate_per_day = 3 → the 4th call is 429 with limit day: ' . implode(' ', $cs));
$dl = activity('key.rate_limited', $since);
ok(count($dl) === 1 && json_decode($dl[0]['after'], true)['limit_hit'] === 'day', 'key.rate_limited logged with limit_hit day');
// kn_rate_ok with a fixed clock
$f = mint(CARA, $m, 'Clock key');
pdo()->prepare('UPDATE mcp_access_tokens SET rate_per_minute = 2 WHERE id = :i')->execute(['i' => $f['id']]);
$at = static fn (string $t): array => q('SELECT * FROM kn_rate_ok(:t, CAST(:at AS timestamptz))', ['t' => $f['id'], 'at' => $t])[0];
$a1 = $at('2020-03-01 10:00:05+00'); $a2 = $at('2020-03-01 10:00:20+00'); $a3 = $at('2020-03-01 10:00:30+00'); $a4 = $at('2020-03-01 10:01:00+00');
ok($a1['ok'] && $a2['ok'] && !$a3['ok'] && $a3['limit_hit'] === 'minute' && (int) $a3['retry_after'] === 30 && $a4['ok'], 'kn_rate_ok with a fixed p_at: two calls in the minute, the third refused with retry_after 30, the next minute opens');
ok((int) $a3['day_calls'] === 2 && (int) one("SELECT calls FROM key_usage WHERE token_id = :t AND bucket_kind = 'day'", ['t' => $f['id']]) === 3, 'the day counts the three calls that were let through (2 + the next minute\'s)');
// pruning
$n = (int) one('SELECT count(*) FROM key_usage WHERE bucket_start < now() - interval \'2 days\'');
$out = worker_pass();
$cron = activity('cron.run');
$steps = json_decode(end($cron)['after'], true)['steps'];
ok($n >= 3 && (int) one('SELECT count(*) FROM key_usage WHERE bucket_start < now() - interval \'2 days\'') === 0 && isset($steps['key_usage']) && $steps['key_usage'] >= 3, 'the worker\'s key_usage step prunes the old buckets and cron.run lists it with the count (' . ($steps['key_usage'] ?? '?') . ')');
// from the person's side
$before = (int) one('SELECT count(*) FROM key_usage');
$sumBefore = (int) one('SELECT COALESCE(sum(calls), 0) FROM key_usage');
$codes = [];
for ($i = 0; $i < 40; $i++) { [$cc] = ask_as(PRIYA, ['base' => $m, 'question' => 'warranty ' . $i]); $codes[$cc] = ($codes[$cc] ?? 0) + 1; }
ok($codes === [200 => 40] && (int) one('SELECT count(*) FROM key_usage') === $before && (int) one('SELECT COALESCE(sum(calls), 0) FROM key_usage') === $sumBefore, 'the window, as Priya, 40 asks in a minute → never a 429 and no key_usage row');
$jar = as_member(PRIYA);
$tok = csrf_of(req('GET', '/', ['jar' => $jar])['body']);
$sc = [];
for ($i = 0; $i < 32; $i++) {
    $sr = req('POST', '/api/v1/ask', ['jar' => $jar, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $tok], 'json' => ['base' => 'smoke-manuals', 'question' => 'warranty session ' . $i]]);
    $sc[$sr['code']] = ($sc[$sr['code']] ?? 0) + 1;
}
ok($sc === [200 => 32] && (int) one('SELECT COALESCE(sum(calls), 0) FROM key_usage') === $sumBefore, 'a session caller of /api/v1/ask: 32 calls, none charged and none refused');
// a sibling is never rate-limited here
$internal = getenv('KN_APP') === 'apache' ? 'http://127.0.0.1:8507' : BASE;
$bc = [];
for ($i = 0; $i < 35; $i++) { [$cc, $bb] = bridge(['op' => 'share_bases', 'caller' => ['kind' => 'application', 'application' => 'helpdesk'], 'door' => 'share', 'args' => []], null, null, $internal); $bc[$cc] = ($bc[$cc] ?? 0) + 1; }
ok($bc === [200 => 35] && (int) one('SELECT COALESCE(sum(calls), 0) FROM key_usage') === $sumBefore, 'a sibling application: 35 share calls, never rate-limited and no key_usage row');
finish();
