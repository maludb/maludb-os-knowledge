<?php
/** Slice 7 proof — /admin/connections (api-keys-k7.md "Proof", Connections). */
require __DIR__ . '/lib.php';

echo "11. Connections\n";
$w = keys_world();
$m = $w['manuals'];
// the probe of share.php may or may not have run in this database: make the rows this screen reads
kb_state(['answer_route' => true, 'answer_mode' => 'ok', 'scripts' => [manual_script($w)]]);
share_call('kn_share_ask', 'helpdesk', ['base' => 'smoke-manuals', 'question' => 'What does the warranty cover? (connections)']);
share_call('kn_share_ask', 'projects', ['base' => 'smoke-manuals', 'question' => 'warranty?']);
$pg = page(as_member(OWNER), '/admin/connections');
$b = $pg['body'];
ok($pg['code'] === 200, 'the admin opens /admin/connections');
ok(str_contains($b, 'id="share-ask"') && str_contains($b, 'id="share-search"') && str_contains($b, 'id="share-bases_index"') && str_contains($b, 'os.knowledge-answer/1') && str_contains($b, 'os.knowledge-evidence/1') && str_contains($b, 'os.knowledge-bases/1'), 'the three shares this application declares, each with its document');
ok(str_contains($b, 'id="principal-helpdesk"') && str_contains($b, 'SMOKE Manuals') && str_contains($b, 'href="/bases/' . $m . '/share"') && !str_contains($b, 'id="principal-projects"'), 'helpdesk with Manuals (and since when), linked to the base\'s Share screen');
ok(str_contains($b, 'id="read-spaces-pages_index"') && str_contains($b, 'id="read-spaces-page_markdown"') && str_contains($b, 'id="read-helpdesk-articles_published"') && substr_count($b, 'no feed uses it') === 3, 'the three reads it declares, each "no feed uses it" (no feed yet)');
ok(str_contains($b, 'id="share-reads-table"') && substr_count($b, 'id="share-read-') >= 2 && str_contains($b, 'not shared') && str_contains($b, '>ok<'), 'the last share.read rows: when, application, tool, base, outcome, ms');
ok(!str_contains($b, 'warranty?') && !str_contains($b, 'What does the warranty cover'), 'no question text anywhere on the page');
ok(str_contains($b, 'id="connections-os-link"') && str_contains($b, 'https://os.example.invalid/applications') && str_contains($b, 'a super-admin\'s decision in the OS'), 'a note that approving a connection is a super-admin\'s decision in the OS, linking to the OS (app. → os.)');
share_call('kn_share_ask', null, ['base' => 'smoke-manuals', 'question' => 'x']);
$b2 = page(as_member(OWNER), '/admin/connections')['body'];
ok(str_contains($b2, 'did not say which application was asking') && str_contains($b2, 'X-OS-Consumer'), 'the K26 notice appears when a call arrived without the consumer');
ok(page(as_member(CARA), '/admin/connections')['code'] === 403 && page(as_member(PRIYA), '/admin/connections')['code'] === 403, 'Cara (a curator) and Priya → 403 in words');
$words = page(as_member(CARA), '/admin/connections')['body'];
ok(str_contains($words, 'You may not change the application settings.'), '…in words: "You may not change the application settings."');
[$c, $d] = screen(as_member(OWNER), '/admin/connections');
ok($c === 200 && count($d['shares']) === 3 && $d['applications'][0]['application'] === 'helpdesk' && count($d['reads']) === 3 && $d['reads'][0]['feeds'] === null && count($d['share_reads']) >= 2 && $d['consumer_unknown'] >= 1, 'the screen as JSON');
ok(count(activity('screen.view')) > 0 && page(as_member(OWNER), '/admin/settings')['body'] !== '' && str_contains(page(as_member(OWNER), '/admin/settings')['body'], 'id="admin-settings-connections-link"'), 'Admin › Settings links to Connections');
finish();
