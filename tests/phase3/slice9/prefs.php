<?php
/** Slice 9 proof — preferences, notifications, tokens (home-admin.md "Proof": prefs.php). */
require __DIR__ . '/lib.php';
require_once APP_ROOT . '/app/features/settings/queries.php';        // NOTICE_KINDS

$w = home_world();
$hb = $w['handbook']; $pol = $w['policies'];
$priya = as_member(PRIYA);
$cara = as_member(CARA);

echo "1. How I am told\n";
$def = (string) one("SELECT pg_get_constraintdef(c.oid) FROM pg_constraint c WHERE c.conrelid = 'notifications'::regclass AND c.contype = 'c' AND pg_get_constraintdef(c.oid) LIKE '%monday_note%'");
preg_match_all("/'([a-z_]+)'/", $def, $m);
$check = array_values(array_unique($m[1]));
sort($check);
$h = page($priya, '/settings/')['body'];
preg_match_all('/id="prefs-field-kind-([a-z_]+)"/', $h, $mk);
preg_match_all('/id="prefs-field-text-kind-([a-z_]+)"/', $h, $mt);
$shown = $mk[1]; sort($shown);
ok(count($check) === 9 && $shown === $check && count($mt[1]) === 9, 'the kinds list is exactly the CHECK\'s nine, in both columns: ' . implode(', ', $check));
[$c, $d] = screen($priya, '/settings/');
$jk = array_keys($d['kinds']); sort($jk);
ok($c === 200 && $jk === $check && isset($d['notify']['email_enabled'], $d['notify']['text_enabled']) && $d['timezone'] === 'UTC', 'JSON: the nine kinds with their sentences, the choices, the time zone');
ok(str_contains($h, 'id="settings-timezone-value"') && str_contains($h, 'change it there') && !str_contains($h, 'name="timezone"'), 'the time zone is shown and read-only here (changed in the OS)');
foreach (NOTICE_KINDS as $k => $words) { if (!str_contains($h, htmlspecialchars($words, ENT_QUOTES))) { ok(false, "$k has its sentence"); } }
ok(true, 'each kind carries its sentence of when it comes');
$since = last_activity_id();
[$c, $b] = act($priya, '/settings/prefs.php', ['email_enabled' => 'no', 'text_enabled' => 'yes', 'kinds' => ['build_done', 'shared'], 'text_kinds' => ['build_failed']]);
$p = q('SELECT * FROM notification_prefs WHERE member_id = :m', ['m' => PRIYA])[0];
ok($c === 200 && $b['email_enabled'] === false && $b['text_enabled'] === true && $p['kinds'] === '{build_done,shared}' && $p['text_kinds'] === '{build_failed}', 'prefs_save: email off, texts on, the kinds chosen');
ok(count(activity('prefs.save', $since)) === 1, 'prefs.save logged');
[$c, $b] = act($priya, '/settings/prefs.php', ['kinds' => ['build_done', 'not_a_kind']]);
ok($c === 422, 'a kind the CHECK does not know → 422');
[$c, $b] = act($priya, '/settings/prefs.php', ['text_enabled' => 'no', 'email_enabled' => 'yes', 'kinds' => 'build_done,shared,review_due', 'text_kinds' => '']);
$p = q('SELECT * FROM notification_prefs WHERE member_id = :m', ['m' => PRIYA])[0];
ok($c === 200 && $p['kinds'] === '{build_done,shared,review_due}' && $p['text_kinds'] === '{}', 'a comma-separated list works for an agent; an empty one clears');
// the last refusal of a text, in words
pdo()->exec("INSERT INTO notification_outbox (channel, member_id, kind, subject, body, status, detail) VALUES ('text', " . PRIYA . ", 'build_done', NULL, 'x', 'skipped', 'no_verified_phone')");
$h = page($priya, '/settings/')['body'];
ok(str_contains($h, 'id="prefs-text-note"') && str_contains($h, 'Texts need a phone number verified in the operating system'), 'the kernel\'s last refusal of a text is shown in words (no verified phone)');
pdo()->exec("INSERT INTO notification_outbox (channel, member_id, kind, subject, body, status, detail) VALUES ('text', " . PRIYA . ", 'build_done', NULL, 'x', 'skipped', 'opted_out')");
ok(str_contains(page($priya, '/settings/')['body'], 'You turned texts off'), '…opted_out, in words');
pdo()->exec("INSERT INTO notification_outbox (channel, member_id, kind, subject, body, status, detail, provider_ref) VALUES ('text', " . PRIYA . ", 'build_done', NULL, 'x', 'sent', NULL, 'kernel:1')");
ok(!str_contains(page($priya, '/settings/')['body'], 'id="prefs-text-note"') && str_contains(page($priya, '/settings/')['body'], 'id="prefs-text-hint"'), '…and gone once a text went through');

echo "2. Notifications\n";
pdo()->exec('DELETE FROM notifications WHERE member_id IN (' . CARA . ', ' . PRIYA . ')');
$bld = running_build($pol, 1, 0);
finish_build($bld, 'failed');
$src = (int) one('SELECT id FROM sources WHERE base_id = :b ORDER BY id LIMIT 1', ['b' => $hb]);
$qid = old_question($hb, PRIYA, 'SMOKE notified question', 60, 'answered');
$pinq = old_question($hb, PRIYA, 'SMOKE pinned for the note', 60, 'answered');
$pin = (int) one('SELECT kn_pin_answer(:q, :m, :t)', ['q' => $pinq, 'm' => CARA, 't' => 'SMOKE note pin']);
pdo()->exec("INSERT INTO archivist_proposals (base_id, kind, title, reason, proposed_by) VALUES ($hb, 'gap', 'SMOKE note proposal', 'Because.', " . ARCHIVIST . ") ON CONFLICT DO NOTHING");
$prop = (int) one("SELECT id FROM archivist_proposals WHERE base_id = $hb AND kind = 'gap' AND status = 'proposed' ORDER BY id LIMIT 1");
pdo()->exec('DELETE FROM notifications WHERE member_id = ' . CARA);
$ins = static fn (string $kind, ?string $type, ?int $id, string $title): int => (int) one('INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (:m, :k, :t, :i, :ti, :b) RETURNING id', ['m' => CARA, 'k' => $kind, 't' => $type, 'i' => $id, 'ti' => $title, 'b' => 'body of ' . $kind]);
$n = [
    'build_done' => [$ins('build_done', 'build', $bld, 'SMOKE n build_done'), '/builds/' . $bld],
    'build_failed' => [$ins('build_failed', 'build', $bld, 'SMOKE n build_failed'), '/builds/' . $bld],
    'limit_near' => [$ins('limit_near', 'base', $hb, 'SMOKE n limit_near'), '/bases/' . $hb],
    'proposal' => [$ins('proposal', 'archivist_proposal', $prop, 'SMOKE n proposal'), '/bases/' . $hb . '/proposals'],
    'feedback' => [$ins('feedback', 'question', $qid, 'SMOKE n feedback'), '/questions/' . $qid],
    'review_due' => [$ins('review_due', 'pinned_answer', $pin, 'SMOKE n review_due'), '/pinned?due=1'],
    'monday_note' => [$ins('monday_note', 'base', $hb, 'SMOKE n monday_note'), '/bases/' . $hb . '/proposals'],
    'shared' => [$ins('shared', 'base', $pol, 'SMOKE n shared'), '/bases/' . $pol],
    'source_failed' => [$ins('source_failed', 'source', $src, 'SMOKE n source_failed'), '/sources/' . $src],
];
$h = page($cara, '/notifications')['body'];
$bad = [];
foreach ($n as $kind => [$id, $url]) {
    $at = (int) strpos($h, 'id="notification-' . $id . '"');
    $next = strpos($h, 'id="notification-', $at + 10);
    $card = substr($h, $at, $next === false ? null : $next - $at);                      // this notification's card alone
    if (!preg_match('#<a href="' . preg_quote(htmlspecialchars($url, ENT_QUOTES), '#') . '(?:\\?|&amp;)back=%2Fnotifications"#', $card)) { $bad[] = $kind; }
}
ok($bad === [], 'a notification of each of the nine kinds links to its record (build, proposals, question, pinned, base, source)' . ($bad ? ' — not: ' . implode(', ', $bad) : ''));
[$c, $d] = screen($cara, '/notifications');
$urls = array_column($d['notifications'], 'url', 'kind');
ok($c === 200 && count($d['notifications']) === 9 && $urls['proposal'] === '/bases/' . $hb . '/proposals' && $urls['review_due'] === '/pinned?due=1', 'JSON carries each notification\'s url');
[$c, $d] = screen($cara, '/notifications?unread=1');
ok(count($d['notifications']) === 9 && $d['unread_only'] === true, 'the unread filter');
$since = last_activity_id();
[$c, $b] = act($cara, '/settings/notifications/read.php', ['notification' => $n['shared'][0]]);
ok($c === 200 && $b['count'] === 1 && (int) one('SELECT count(*) FROM notifications WHERE member_id = :m AND read_at IS NULL', ['m' => CARA]) === 8, 'mark one read');
[$c, $b] = act($priya, '/settings/notifications/read.php', ['notification' => $n['build_done'][0]]);
ok($c === 200 && $b['count'] === 0 && (int) one('SELECT count(*) FROM notifications WHERE member_id = :m AND read_at IS NULL', ['m' => CARA]) === 8, 'another person\'s notification is not marked (own rows only)');
ok(str_contains(page($cara, '/notifications?count=1')['body'], '8'), 'the bell counts the unread');
[$c, $b] = act($cara, '/settings/notifications/read.php', []);
ok($c === 200 && $b['count'] === 8 && (int) one('SELECT count(*) FROM notifications WHERE member_id = :m AND read_at IS NULL', ['m' => CARA]) === 0, 'mark all read');
$ev = activity('notification.read', $since);
ok(count($ev) === 3 && !in_log('body of build_done') && !in_log('SMOKE n shared'), 'notification.read logged with the counts — never a title or a body');
$h = page($cara, '/notifications')['body'];
ok(!str_contains($h, 'id="notification-' . $n['shared'][0] . '-read-btn"') && str_contains($h, 'id="notifications-read-all-btn"'), 'read ones lose their button');

echo "3. Tokens\n";
$base = rtrim(need('APP_URL'), '/');
$h = page($priya, '/settings/tokens/')['body'];
ok(str_contains($h, '>' . $base . '/mcp/records<') && str_contains($h, '>' . $base . '/mcp/activity<') && str_contains($h, 'A token reads what you may read. An API key for a base is minted on that base\'s Keys page.'), 'the two URLs to paste, and the sentence about keys');
$since = last_activity_id();
$r = act_html($priya, '/settings/tokens/mint.php', ['label' => 'SMOKE html token', 'scope' => 'mcp']);
$h = page($priya, '/settings/tokens/')['body'];
preg_match('#id="tokens-minted-value">(mcp_[0-9a-f]{48})<#', $h, $mm);
ok($r['code'] === 302 && ($mm[1] ?? '') !== '', 'minting shows the value once (mcp_ + 48 hex)');
ok(!str_contains(page($priya, '/settings/tokens/')['body'], 'id="tokens-minted"'), '…and never again');
$tok = (int) one("SELECT id FROM mcp_access_tokens WHERE member_id = :m AND label = 'SMOKE html token'", ['m' => PRIYA]);
$ev = activity('token.mint', $since);
ok(count($ev) === 1 && !in_log($mm[1] ?? 'zzzz') && !in_log(hash('sha256', $mm[1] ?? 'zzzz')), 'token.mint logged: the label and scope, never the token or its hash');
ok(str_contains(page($priya, '/settings/tokens/')['body'], 'id="token-row-' . $tok . '"') && !str_contains(page($priya, '/settings/tokens/')['body'], $mm[1] ?? 'zzzz'), 'the list shows label, scope, created, last used — never the value');
[$c, $b] = act($priya, '/settings/tokens/mint.php', ['label' => '']);
ok($c === 422, 'a label is required');
[$c, $b] = act($priya, '/settings/tokens/mint.php', ['label' => 'x', 'scope' => 'api']);
ok($c === 422, 'a person\'s own token has the scope mcp only');
[$c, $b] = act($cara, '/settings/tokens/revoke.php', ['token' => $tok]);
ok($c === 404 && (string) one('SELECT revoked_at FROM mcp_access_tokens WHERE id = ' . $tok) === '', 'another person\'s token cannot be revoked (404)');
$since = last_activity_id();
[$c, $b] = act($priya, '/settings/tokens/revoke.php', ['token' => $tok]);
ok($c === 200 && (string) one('SELECT revoked_at FROM mcp_access_tokens WHERE id = ' . $tok) !== '' && count(activity('token.revoke', $since)) === 1, 'revoke → revoked, token.revoke logged');
$h = page($priya, '/settings/tokens/')['body'];
ok(str_contains($h, 'id="token-row-' . $tok . '"') && str_contains($h, 'revoked') && !str_contains($h, 'id="token-row-' . $tok . '-revoke-btn"'), 'a revoked token is marked and has no Revoke');
[$c, $d] = screen($priya, '/settings/tokens/');
ok($c === 200 && $d['records_url'] === $base . '/mcp/records' && $d['activity_url'] === $base . '/mcp/activity' && !str_contains(json_encode($d), 'token_hash'), 'JSON: the tokens and the two URLs — no hash');
finish();
