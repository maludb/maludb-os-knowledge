<?php
/**
 * Proof: rights per role on the shell's screens and placeholders (403 vs 200), the four placeholders' behaviour (a page naming its
 * slice, 501 for JSON and for a POST), the menu per role, no-session answers, the home's JSON and its lists once bases exist (reach
 * decided by the database), the person-own screens as JSON, the command bar through the kernel's chat endpoint (a reply, a refusal
 * in the kernel's words, an approval, a navigate followed, a foreign navigate ignored, too long refused, never the words in the log),
 * and the action-token / run-token gate — an agent kept off the person-only screens (sso-shell.md "Every request", "Handlers").
 * Run after the browser proof through tests/phase2/run.sh (the bases it seeds are idempotent).
 */
require __DIR__ . '/lib.php';
/** The menu items whose screen is still the shell's placeholder — a slice that builds one removes it here (and from NAV_SLICES). */
const NAV_SLICES_LEFT = [];        // ask and pinned: built by slice 5; admin: built by slice 9 — none is left
$json = ['headers' => ['Accept: application/json']];

echo "1. Rights per role — every screen and placeholder answers 200 or 403\n";
// route => the roles that get 200; the roles are admin (the owner 1), curator (Marco 27), member (Priya 26), reader (Reid 32), guest (Ann 29).
$all = 'admin curator member reader guest';
$matrix = [
    '/' => $all, '/ask' => $all, '/bases/' => $all, '/pinned' => $all, '/activity' => $all, '/notifications' => $all, '/settings/' => $all, '/settings/tokens/' => $all,
    '/admin/' => 'admin',
];
[$jAdmin, ] = sign_on(1);
[$jCurator, ] = sign_on(27);
[$jMember, ] = sign_on(26);
[$jReader, ] = sign_on(32);
[$jGuest, ] = sign_on(29);
$who = ['admin' => $jAdmin, 'curator' => $jCurator, 'member' => $jMember, 'reader' => $jReader, 'guest' => $jGuest];
$wrong = [];
$n = 0;
foreach ($matrix as $route => $allowed) {
    foreach ($who as $role => $jarf) {
        $want = in_array($role, explode(' ', $allowed), true) ? 200 : 403;
        $code = page($jarf, $route)['code'];
        $n++;
        if ($code !== $want) { $wrong[] = "$role $route wanted $want got $code"; }
    }
}
ok($wrong === [], "$n role × screen checks (" . count($matrix) . ' screens × 5 roles) answer 200 or 403 as the rights say' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 8)) : ''));
$r = page($jCurator, '/admin/');
ok(str_contains($r['body'], 'You may not change the application settings.') && str_contains($r['body'], 'id="message-text"'), 'a refusal says what the person may not do, in words ("You may not change the application settings.")');
ok(badge_of($jAdmin) === 'Super-admin' && badge_of($jCurator) === 'Curator' && badge_of($jMember) === 'Member' && badge_of($jReader) === 'Reader' && badge_of($jGuest) === 'Reader', 'the header badge reads the highest role held: Super-admin, Curator, Member, Reader, Reader');
[$jAvery, ] = sign_on(2);
ok(badge_of($jAvery) === 'Knowledge admin' && page($jAvery, '/admin/')['code'] === 200, 'a second admin (the admin role, not the super-admin) reads Knowledge admin and opens Admin');

echo "2. The placeholders — none is left (slice 9 built the Admin index); the mechanism stays for the shell\n";
$stubs = trim((string) shell_exec('grep -rl "render_nav_stub(" ' . escapeshellarg(dirname(__DIR__, 2) . '/html') . ' 2>/dev/null | wc -l'));
ok((int) $stubs === count(NAV_SLICES_LEFT), 'the controllers still calling render_nav_stub are the menu items not built yet (' . implode(', ', array_keys(NAV_SLICES_LEFT)) . "): $stubs");
foreach (NAV_SLICES_LEFT as $id => [$url, $slice, $title]) {
    $b = page($jAdmin, $url)['body'];
    ok(str_contains($b, 'id="' . $id . '-coming"') && str_contains($b, $title . ' is not built yet') && str_contains($b, $slice . ' builds this screen') && str_contains($b, '<title>' . $title . ' · Knowledge</title>'), "$url: the shell, the header and one card saying $slice builds it");
}
$b = page($jAdmin, '/bases/')['body'];
ok(str_contains($b, 'id="base-list"') && !str_contains($b, 'id="bases-coming"'), '/bases/ is built (slice 1): the list, not the placeholder');
$r = page($jAdmin, '/admin/', $json);
ok($r['code'] === 200 && is_array(json_decode($r['body'], true)['data']['pages'] ?? null), 'slice 9 built the Admin index: a JSON caller gets its pages (it was a 501 not_built placeholder)');
$r = page($jAdmin, '/admin/', ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="admin-pages"') && !str_contains($r['body'], '<html') && preg_match('/^HX-Title: Admin/mi', $r['headers']) === 1 && preg_match('/^X-Screen: admin\r?$/mi', $r['headers']) === 1, 'over HTMX the Admin index is the partial with HX-Title and X-Screen, so the tab highlight and the command bar follow');
$v = (int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'admin'");
ok($v >= 1, "opening the Admin index logs screen.view for its screen ($v for admin)");
$built = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/mcp/action_registry.json'), true);
$reg = [];
foreach (($built['screens'] ?? []) as $s) { $reg[$s['screen'] ?? ''] = $s; }
ok(($reg['ask']['built'] ?? null) === true && empty($reg['ask']['stub']) && ($reg['admin']['built'] ?? null) === true && empty($reg['admin']['stub']) && ($reg['home']['built'] ?? null) === true && ($reg['tokens']['built'] ?? null) === true, 'the registry marks admin (slice 9), ask (slice 5), home and tokens built — no stub is left');

echo "3. The menu per role, and no session\n";
$menu = fn (string $j): array => (preg_match_all('/id="nav-([a-z-]+)"/', page($j, '/')['body'], $m) ? array_values(array_filter($m[1], fn ($i) => $i !== 'base-')) : []);
$groups = fn (string $j): array => (preg_match_all('/nxl-caption"><label>([^<]+)</', page($j, '/')['body'], $m) ? $m[1] : []);
[$open, $private] = seed_bases();                                           // the browser proof seeded them already; idempotent
ok($groups($jMember) === ['Your bases', 'Me'] && $menu($jMember) === ['home', 'ask', 'bases', 'pinned', 'my-settings', 'notifications', 'tokens', 'activity'], 'a Member sees Home · Ask · Bases · Pinned, Your bases, then Me (My settings, Notifications, Tokens, My activity): ' . implode(', ', $menu($jMember)));
ok($groups($jGuest) === ['Me'] && $menu($jGuest) === $menu($jMember), 'a guest sees the same menu and no Your bases (reach is a base\'s, decided by the database; nothing is listed until something is shared)');
ok($groups($jAdmin) === ['Your bases', 'Me', 'Admin'] && in_array('admin', $menu($jAdmin), true) && !in_array('admin', $menu($jCurator), true) && $groups($jCurator) === ['Your bases', 'Me'], 'the admin sees the Admin group too; a curator does not');
$tabs = fn (string $j): array => (preg_match_all('/id="tab-([a-z-]+)"/', page($j, '/')['body'], $m) ? $m[1] : []);
ok($tabs($jMember) === ['home', 'ask', 'bases', 'my-settings'] && $tabs($jGuest) === ['home', 'ask', 'bases', 'my-settings'], 'the phone\'s tab bar is Home · Ask · Bases · Me for everyone');
$r = page(jar(), '/bases/');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'no session: a screen goes to the launcher with ?app=knowledge');
$r = req('GET', '/bases/', $json);
ok($r['code'] === 401 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'unauthorized', 'no session, JSON: 401 unauthorized');
$r = req('GET', '/ask');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'no session on a placeholder: the launcher too (the right is checked after the login)');

echo "4. The home — JSON, and the lists once bases exist\n";
$r = page($jMember, '/', $json);
$d = json_decode($r['body'], true)['data'] ?? [];
ok($r['code'] === 200 && $d['may']['create'] === true && $d['may']['admin'] === false && count($d['bases']) === 1 && (int) $d['bases'][0]['base_id'] === $open, 'the home answers JSON: may.create, may.admin, bases (a member: the internal base alone)');
$d = json_decode(page($jReader, '/', $json)['body'], true)['data'] ?? [];
ok($d['may']['create'] === false, 'a reader may not create (may.create false)');
$d = json_decode(page($jCurator, '/', $json)['body'], true)['data'] ?? [];
ok(count($d['bases']) === 2 && array_column($d['bases'], 'base_id') == [$open, $private] || array_column($d['bases'], 'base_id') == [$private, $open], 'the owner\'s JSON lists both bases');
ok(($d['bases'][0]['my_level'] ?? '') !== '' && array_key_exists('source_count', $d['bases'][0]) && array_key_exists('status', $d['bases'][0]), 'each with my_level, status and the counts (mcp_bases)');
$h = page($jCurator, '/')['body'];
ok(str_contains($h, 'id="home-bases-list"') && str_contains($h, 'id="home-base-' . $open . '"') && str_contains($h, 'id="home-base-' . $private . '"') && !str_contains($h, 'id="home-first-run"'), 'the owner\'s home lists both bases as cards; the first-run card is gone');
ok(str_contains($h, 'href="/bases/' . $open . '?back=%2F"') && str_contains($h, 'id="home-base-' . $open . '-link"'), 'a base card\'s name is a link carrying ?back= (click-around)');
ok(str_contains($h, 'id="nav-base-' . $open . '"') && str_contains($h, 'id="nav-base-' . $private . '"') && str_contains($h, '<label>Your bases</label>'), 'the sidebar lists Your bases beneath the menu');
$h = page($jMember, '/')['body'];
ok(str_contains($h, 'id="home-base-' . $open . '"') && !str_contains($h, 'id="home-base-' . $private . '"') && str_contains($h, 'id="nav-base-' . $open . '"') && !str_contains($h, 'id="nav-base-' . $private . '"'), 'a member\'s home and sidebar hold the internal base and never the private one');
$h = page($jReader, '/')['body'];
ok(str_contains($h, 'id="home-base-' . $open . '"') && !str_contains($h, 'id="home-create-base-btn"'), 'a reader lists the internal base with no create button');
$h = page($jGuest, '/')['body'];
ok(!str_contains($h, 'id="home-base-') && str_contains($h, 'id="home-nothing-shared"') && !str_contains($h, '<label>Your bases</label>'), 'a guest sees no base and is told nothing has been shared');
$h = page($jAdmin, '/')['body'];
ok(str_contains($h, 'id="home-base-' . $open . '"') && str_contains($h, 'id="home-base-' . $private . '"'), 'the admin sees every base, the private one included');

echo "5. The person's own screens answer JSON\n";
$d = json_decode(page($jMember, '/settings/', $json)['body'], true)['data'] ?? [];
ok(isset($d['kinds']['build_done']) && isset($d['notify']['email_enabled']) && isset($d['timezone']), '/settings/: how I am told (notify), the notification kinds the schema knows, the time zone');
$r = page($jMember, '/settings/tokens/', $json);
ok($r['code'] === 200 && is_array(json_decode($r['body'], true)['data']['tokens'] ?? null), '/settings/tokens/: the tokens list');
$r = page($jMember, '/notifications', $json);
ok($r['code'] === 200 && is_array(json_decode($r['body'], true)['data']['notifications'] ?? null), '/notifications: the list');
$r = page($jMember, '/activity', $json);
ok($r['code'] === 200 && is_array(json_decode($r['body'], true)['data']['rows'] ?? null), '/activity: the rows');

echo "6. The command bar through the kernel's chat endpoint\n";
$t = page_csrf($jMember);
$since = last_activity_id();
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'What does the handbook say about leave?', 'screen' => 'home', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="assistant-reply-text"') && str_contains($r['body'], 'Hello from the fake expert'), 'the expert\'s reply is rendered above the bar');
$a = activity('assistant.ask', $since);
$la = $a !== [] ? json_decode((string) end($a)['after'], true) : [];
ok($a !== [] && ($la['length'] ?? 0) === 39 && ($la['run_id'] ?? 0) === 1 && !str_contains((string) end($a)['after'], 'handbook'), 'assistant.ask logs the length and the run id, never the text');
$chat = json_decode((string) file(need('FAKE_KERNEL_STATE') . '.chat')[0], true);
ok($chat['agent'] === 'expert' && (int) $chat['acting'] === 26, 'the kernel saw the call for the expert as the acting person');
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'form' => ['utterance' => 'hi']]);
ok($r['code'] === 403, 'no CSRF token: 403');
kernel_state(function ($s) { $s['chat_status'] = 404; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 404 && str_contains($r['body'], 'id="assistant-reply-error"'), 'the kernel\'s 404 (no expert) is shown as an error in words');
kernel_state(function ($s) { $s['chat_status'] = 409; $s['chat_message'] = 'The expert is busy with another turn.'; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 409 && str_contains($r['body'], 'The expert is busy with another turn.'), 'a refusal is shown in the kernel\'s own words');
kernel_state(function ($s) { unset($s['chat_status'], $s['chat_message']); $s['chat'] = ['run_id' => 2, 'status' => 'awaiting_approval', 'finished' => true, 'reply' => 'I drafted the guideline; saving it waits for approval.', 'actions' => [['tool' => 'guideline_save', 'status' => 'awaiting_approval']], 'approval_request_id' => 12]; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'draft a guideline', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="assistant-reply-approval"') && str_contains($r['body'], 'request #12') && str_contains($r['body'], 'guideline_save'), 'an action awaiting approval is shown as such, with the request number');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 3, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Opening your tokens.', 'actions' => [], 'navigate' => '/settings/tokens/']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'open my tokens', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], '/settings/tokens/') && str_contains($r['body'], 'id="assistant-reply-navigate"'), 'a navigate answer is followed (HX-Location to /settings/tokens/)');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 4, 'status' => 'succeeded', 'finished' => true, 'reply' => 'x', 'actions' => [], 'navigate' => 'https://evil.invalid/x']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'go', 'csrf_token' => $t]]);
ok($r['code'] === 200 && !str_contains($r['headers'], 'HX-Location'), 'a navigate that is not a local path is ignored');
kernel_state(function ($s) { unset($s['chat']); return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => str_repeat('x', 2100), 'csrf_token' => $t]]);
ok($r['code'] === 422 && str_contains($r['body'], 'id="assistant-reply-error"'), 'over 2,000 characters: 422, in words');
$r = req('POST', '/assistant/ask.php', ['jar' => $jMember, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => '', 'csrf_token' => $t]]);
ok($r['code'] === 422, 'nothing said: 422');

echo "7. The action-token and run-token gate\n";
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)]]);
ok($r['code'] === 200 && preg_match('/^Set-Cookie:/mi', $r['headers']) === 0, 'a person\'s action token opens a read for one request, with no cookie set');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26, -5)]]);
ok($r['code'] === 401, 'an expired action token: 401');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . (function (string $t): string { return substr($t, 0, -1) . ($t[-1] === '0' ? '1' : '0'); })(person_token(26))]]);
ok($r['code'] === 401, 'a tampered one: 401');
$r = req('POST', '/settings/prefs.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)], 'form' => ['email_enabled' => 'no', 'kinds' => ['build_done']]]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['did'] ?? '') === 'Saved how you are told' && (json_decode($r['body'], true)['ok'] ?? false) === true, 'a write under an action token answers JSON (prefs_save: ok, did, location): the action token stands in for CSRF');
ok(one('SELECT email_enabled::text FROM notification_prefs WHERE member_id = 26') === 'false', '...and it was written');
$tok = run_token(40, 77);
$r = req('GET', '/activity', ['headers' => as_agent($tok)]);
ok($r['code'] === 200, 'an admitted agent (Seamus, 40) under a run token reads its activity: 200');
$since = last_activity_id();
req('GET', '/activity', ['headers' => array_merge(as_agent($tok), ['X-Screen-View: 1'])]);
$v = activity('screen.view', $since);
ok($v !== [] && (int) end($v)['agent_run_id'] === 77 && end($v)['source'] === 'agent', 'its screen view is logged with the run id and source agent');
ok(req('GET', '/', ['headers' => as_agent($tok)])['code'] === 403, 'an agent may not open the person\'s home: 403 (people only)');
ok(req('POST', '/settings/tokens/mint.php', ['headers' => as_agent($tok), 'form' => ['label' => 'agent']])['code'] === 403, 'nor mint a person\'s token: 403');
ok(req('POST', '/assistant/ask.php', ['headers' => as_agent($tok), 'form' => ['utterance' => 'hi']])['code'] === 403, 'nor use the command bar: 403');
$r = req('GET', '/activity', ['headers' => as_agent(run_token(40, 77, -5))]);
ok($r['code'] === 401, 'an expired run token: 401');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok]]);
ok($r['code'] === 401, 'a run token without its relay signature: 401');
$since = last_activity_id();
$tok2 = run_token(900, 78);
$r = req('GET', '/activity', ['headers' => as_agent($tok2)]);
ok($r['code'] === 401 && (int) one('SELECT count(*) FROM members WHERE id = 900') === 0, 'an id with no mirror row is refused and never created');
$ref = activity('member.refused', $since);
ok($ref !== [] && (int) end($ref)['entity_id'] === 900, 'and logged member.refused with the id');

echo "8. A hand-off keeps what the directory said\n";
pdo()->exec("UPDATE members SET job_title = 'SMOKE Field engineer', phone = '+15550100', timezone = 'America/Chicago' WHERE id = 28");
sign_on(28);
$m = q('SELECT job_title, phone, timezone FROM members WHERE id = 28')[0];
ok($m['job_title'] === 'SMOKE Field engineer' && $m['phone'] === '+15550100' && $m['timezone'] === 'America/Chicago',
    "a sign-on whose claims carry no job title, phone or time zone keeps the feed's (mirror_apply_member — Inventory's Phase 2 found it blanking them): " . json_encode($m));
finish();
