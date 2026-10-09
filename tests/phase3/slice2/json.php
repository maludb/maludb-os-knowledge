<?php
/** Slice 2 proof — JSON mode and agents (sources-sections.md "Proof", bullet 8). */
require __DIR__ . '/lib.php';

echo "8. JSON mode and agents\n";
$bid = world()['base'];
$person = ['X-Action-Token: ' . person_token(PRIYA)];
$shape = static fn (array $b): bool => ($b['ok'] ?? false) === true && isset($b['did'], $b['record_id'], $b['location']) && array_key_exists('refresh', $b);
[$c, $b] = act_token('/sources/add.php', ['base' => $bid, 'kind' => 'text', 'title' => 'SMOKE By token', 'text' => "# Token\n\nWords by token."], $person);
$sid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b) && $b['records'] === [$sid] && $b['location'] === '/sources/' . $sid, 'source_add under an action token: {ok, did, record_id, location, refresh, records}');
[$c, $b] = act_token('/sources/update.php', ['source' => $sid, 'title' => 'SMOKE By token, renamed', 'language' => 'fr'], $person);
ok($c === 200 && $shape($b) && source_of($sid)['title'] === 'SMOKE By token, renamed', 'source_update answers the shape');
[$c, $b] = act_token('/sources/update.php', ['source' => $sid, '_partial' => '1', 'title' => 'SMOKE Partial'], $person);
ok($c === 200 && source_of($sid)['title'] === 'SMOKE Partial' && source_of($sid)['language'] === 'fr', '_partial=1 on source_update keeps the untouched language');
$u = activity('source.update');
ok(array_keys(json_decode(end($u)['after'], true)) === ['title'] && (int) end($u)['base_id'] === $bid, 'source.update logs only what changed, with base_id');
[$c, $b] = act_token('/sources/update.php', ['source' => $sid, 'language' => 'not a language!'], $person);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['language']), '422 {error: {code: invalid, fields}} — the CHECK on its field');
worker();
[$c, $b] = act_token('/sources/replace.php', ['source' => $sid, 'kind' => 'text', 'text' => "# Token\n\nNew words."], $person);
$new = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $shape($b) && $new > $sid, 'source_replace answers the shape, record_id the new source');
[$c, $b] = act_token('/sources/remove.php', ['source' => $new], $person);
ok($c === 200 && $shape($b) && (int) $b['record_id'] === $new, 'source_remove answers the shape');
pdo()->exec("INSERT INTO sources (base_id, kind, title, origin, added_by, status, error) VALUES ($bid, 'text', 'SMOKE Failed', 'paste', 26, 'failed', 'extract_error')");
$failed = (int) one("SELECT max(id) FROM sources WHERE title = 'SMOKE Failed'");
pdo()->exec("INSERT INTO source_text (source_id, text, extractor) VALUES ($failed, '# Failed' || chr(10) || chr(10) || 'Words.', 'paste')");
[$c, $b] = act_token('/sources/retry.php', ['source' => $failed], $person);
ok($c === 200 && $shape($b) && source_of($failed)['status'] === 'uploaded', 'source_retry answers the shape');
[$c, $d] = screen(as_member(PRIYA), '/bases/' . $bid . '/sources');
ok($c === 200 && isset($d['sources'][0]['source_id'], $d['sources'][0]['status']) && !isset($d['sources'][0]['text']) && array_key_exists('pending', $d), 'the sources answer JSON through the presenter (no text)');
$sec = (int) one("SELECT id FROM sections WHERE base_id = :b AND status <> 'forgotten' ORDER BY id LIMIT 1", ['b' => $bid]);
[$c, $d] = screen(as_member(REID), '/sections/' . $sec);
ok($c === 200 && isset($d['section']['text'], $d['neighbours']) && !isset($d['section']['error']), 'a section answers JSON with its words (a Reader: no curator fields)');
$live = (int) one("SELECT id FROM sources WHERE base_id = :b AND status = 'ready' ORDER BY id LIMIT 1", ['b' => $bid]);
[$c, $d] = screen(as_member(PRIYA), '/sources/' . $live);
ok($c === 200 && (int) ($d['source']['source_id'] ?? 0) === $live && is_array($d['outline'] ?? null) && isset($d['may']), 'a source answers JSON: the source, its outline, may');

echo "   An agent\n";
act(as_member(PRIYA), '/bases/members/add.php', ['base' => $bid, 'member' => SEAMUS, 'level' => 'curate']);
$tok = run_token(SEAMUS, 5151);
$since = last_activity_id();
[$c, $b] = act_token('/sources/add.php', ['base' => $bid, 'kind' => 'text', 'title' => 'SMOKE Seamus text', 'text' => "# Agent\n\nWords from an agent."], as_agent($tok));
ok($c === 200 && $shape($b), 'Seamus (a curate share, run token + relay) adds a text source');
[$c, $b] = act_token('/sources/add.php', ['base' => $bid, 'kind' => 'url', 'url' => WEB . '/page.html?agent=1'], as_agent($tok));
ok($c === 200 && $shape($b), '…and an address');
$adds = activity('source.add', $since);
ok(count($adds) === 2 && $adds[0]['source'] === 'agent' && (int) $adds[0]['agent_run_id'] === 5151, 'logged with source agent and the run id');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['source_remove']['approval'] ?? null) === 'deletion' && ($reg['actions']['source_add']['approval'] ?? null) === null && ($reg['actions']['source_replace']['approval'] ?? null) === null,
    'the registry lists source_remove as deletion; source_add and source_replace free');
$built = array_filter(['source_add', 'source_replace', 'source_remove', 'source_retry', 'source_update'], static fn ($a) => !empty($reg['actions'][$a]['built']));
$screens = array_filter(['sources', 'source-add', 'source-view', 'source-edit', 'source-replace', 'section-view'], static fn ($s) => !empty($reg['screens'][$s]['built']));
ok(count($built) === 5 && count($screens) === 6, 'the registry reads 6 screens and 5 actions of this slice built (' . count($screens) . ', ' . count($built) . ')');
finish();
