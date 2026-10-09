<?php
/** Slice 1 proof — sharing (bases-core.md "Proof", bullet 3). */
require __DIR__ . '/lib.php';

echo "3. Share\n";
$priya = as_member(PRIYA);
$id = make_base(PRIYA, 'SMOKE Shared Manuals');
$since = last_activity_id();
$notesBefore = ['marco' => count(notifications_of(MARCO)), 'priya' => count(notifications_of(PRIYA)), 'ann' => count(notifications_of(ANN)), 'seamus' => count(notifications_of(SEAMUS, 'shared'))];
[$c1, $b1] = act($priya, '/bases/members/add.php', ['base' => $id, 'member' => MARCO, 'level' => 'curate']);
[$c2, $b2] = act($priya, '/bases/members/add.php', ['base' => $id, 'department' => DESIGN, 'level' => 'read']);
[$c3, $b3] = act($priya, '/bases/members/add.php', ['base' => $id, 'member' => SEAMUS, 'level' => 'read']);
ok($c1 === 200 && $c2 === 200 && $c3 === 200 && (int) $b1['record_id'] > 0, "Priya shares with Marco (curate), Design (read), Seamus (read): $c1 $c2 $c3");
$rows = q('SELECT principal_kind, principal_id, level FROM base_members WHERE base_id = :b ORDER BY id', ['b' => $id]);
ok(array_map(static fn ($r) => $r['principal_kind'] . ':' . $r['principal_id'] . ':' . $r['level'], $rows) === ['member:27:curate', 'department:7:read', 'agent:40:read'], 'the rows: a person, a department, an agent (its kind from the mirror)');
$log = activity('base.share', $since);
ok(count($log) === 3 && (int) $log[0]['base_id'] === $id && json_decode($log[0]['after'], true)['principal_kind'] === 'member' && json_decode($log[0]['after'], true)['changed'] === false, 'base.share logged three times with base_id, the principal and changed false');
[$c, $b] = act(as_member(MARCO), '/bases/save.php', ['base' => $id, 'description' => 'Marco curates this.']);
ok($c === 200, 'Marco (curate share + Curator role) manages it');
[$c, $b] = act(as_member(MARCO), '/bases/members/add.php', ['base' => $id, 'member' => BEA, 'level' => 'curate']);
ok($c === 200, 'Marco shares on (base.share): Bea at curate');
[$c, $b] = act(as_member(BEA), '/bases/save.php', ['base' => $id, 'name' => 'SMOKE Bea renamed']);
ok($c === 403 && msg($b) === 'You may not manage this base.', "Bea with a curate share but only Member may not update: 403 in base.manage's words (" . msg($b) . ')');
[$c, $b] = act(as_member(BEA), '/bases/members/add.php', ['base' => $id, 'member' => REID, 'level' => 'read']);
ok($c === 403 && msg($b) === 'You may not share this base.', "nor share: 403 in base.share's words");
[$c, $b] = act($priya, '/bases/members/add.php', ['base' => $id, 'member' => SEAMUS, 'level' => 'curate']);
ok($c === 200 && one('SELECT level FROM base_members WHERE base_id = :b AND principal_id = :m', ['b' => $id, 'm' => SEAMUS]) === 'curate' && json_decode(array_slice(activity('base.share', $since), -1)[0]['after'], true)['changed'] === true,
    'changing Seamus to curate updates the row (changed true)');
ok((int) one('SELECT count(*) FROM base_members WHERE base_id = :b AND principal_id = :m', ['b' => $id, 'm' => SEAMUS]) === 1, 'still one row for Seamus');
[$c, $b] = act($priya, '/bases/members/add.php', ['base' => $id, 'member' => MARCO, 'level' => 'own']);
ok($c === 200, 'the owner may give own');
[$c, $b] = act(as_member(MARCO), '/bases/members/add.php', ['base' => $id, 'member' => REID, 'level' => 'own']);
ok($c === 422 && (fields($b)['level'] ?? '') === 'Only the owner of this knowledge base may give own.', 'an own share is a co-curator: kn_base_level() keeps own for the owner and the admin, so Marco may not give own (' . $c . ')');
pdo()->exec("UPDATE base_members SET level = 'curate' WHERE base_id = $id AND principal_id = " . MARCO);
pdo()->exec("DELETE FROM base_members WHERE base_id = $id AND principal_id = " . REID);
[$c, $b] = act(as_member(MARCO), '/bases/members/add.php', ['base' => $id, 'member' => REID, 'level' => 'own']);
ok($c === 422 && (fields($b)['level'] ?? '') === 'Only the owner of this knowledge base may give own.', 'a curate-level curator may not give own: the level field');
[$c, $b] = act($priya, '/bases/members/add.php', ['base' => $id, 'department' => DESIGN, 'level' => 'own']);
ok($c === 422 && isset(fields($b)['level']), 'a department reads or curates, never owns');
[$c, $b] = act($priya, '/bases/members/add.php', ['base' => $id, 'member' => ANN, 'level' => 'read']);
ok($c === 422 && str_contains(msg($b), 'A guest'), 'Ann through base_share → 422 pointing at A guest (' . msg($b) . ')');
[$c, $b] = act($priya, '/bases/members/add-guest.php', ['base' => $id, 'guest' => ANN]);
ok($c === 200 && one('SELECT level FROM base_members WHERE base_id = :b AND principal_id = :m', ['b' => $id, 'm' => ANN]) === 'read', 'Ann through base_share_guest at read → shared');
ok(count(activity('base.share_guest', $since)) === 1, 'logged base.share_guest (its own event)');
[$c, $b] = act($priya, '/bases/members/add-guest.php', ['base' => $id, 'guest' => ANN, 'level' => 'curate']);
ok($c === 422 && (fields($b)['level'] ?? '') === 'A guest reads; they never curate.', 'at curate → refused in words');
[$c, $b] = act($priya, '/bases/members/add-guest.php', ['base' => $id, 'guest' => REID]);
ok($c === 422 && isset(fields($b)['guest']), 'an insider through the guest door is refused on the guest field');
[$c, $b] = act($priya, '/bases/members/add-application.php', ['base' => $id, 'application' => 'helpdesk']);
ok($c === 200 && in_array($id, array_map('intval', array_column(q("SELECT kn_application_base_ids('helpdesk') AS id"), 'id')), true), "helpdesk through base_share_application → kn_application_base_ids('helpdesk') holds the base");
ok(count(activity('base.share_application', $since)) === 1, 'logged base.share_application');
[$c, $b] = act($priya, '/bases/members/add-application.php', ['base' => $id, 'application' => 'projects', 'level' => 'curate']);
ok($c === 422 && (fields($b)['level'] ?? '') === 'An application reads; it never curates.', 'curate for an application → the CHECK\'s sentence');
[$c, $b] = act($priya, '/bases/members/add-application.php', ['base' => $id, 'application' => 'Not A Key']);
ok($c === 422 && isset(fields($b)['application']), 'a malformed catalog key → the application field');
try {
    pdo()->exec("INSERT INTO base_members (base_id, principal_kind, level) VALUES ($id, 'everyone', 'read')");
    ok(false, 'an everyone row written by hand is refused');
} catch (PDOException $e) {
    ok(str_contains($e->getMessage(), 'The everyone principal follows the base\'s visibility'), 'an everyone row written by hand → the trigger\'s sentence');
}
$seamusRow = (int) one('SELECT id FROM base_members WHERE base_id = :b AND principal_id = :m', ['b' => $id, 'm' => SEAMUS]);
[$c, $b] = act($priya, '/bases/members/remove.php', ['base' => $id, 'base_member' => $seamusRow]);
ok($c === 200 && (int) one('SELECT count(*) FROM base_members WHERE id = :r', ['r' => $seamusRow]) === 0, 'removal of a row');
$un = activity('base.unshare', $since);
ok(count($un) === 1 && json_decode($un[0]['before'], true)['principal_id'] === SEAMUS && (int) $un[0]['base_id'] === $id, 'logged base.unshare with the row\'s facts');
[$c, $b] = act($priya, '/bases/members/remove.php', ['base' => $id, 'base_member' => 999999]);
ok($c === 422, 'a row not on this base is refused');
// the notifications (db/012 trigger)
ok(count(notifications_of(MARCO)) >= $notesBefore['marco'] + 2, 'Marco is told (his share, and Design\'s)');
ok(count(notifications_of(PRIYA)) === $notesBefore['priya'] + 1, 'every live member of Design is told — the sharer included');
$annNotes = notifications_of(ANN);
ok(count($annNotes) === $notesBefore['ann'] + 1 && str_contains($annNotes[count($annNotes) - 1]['title'], 'SMOKE Shared Manuals'), 'Ann is told');
ok(count(notifications_of(SEAMUS, 'shared')) === $notesBefore['seamus'], 'none for Seamus (an agent)');
ok((int) one("SELECT count(*) FROM notifications WHERE record_type = 'base' AND record_id = :b AND member_id NOT IN (" . implode(',', [PRIYA, MARCO, ANN, BEA]) . ')', ['b' => $id]) === 0, 'and none for helpdesk (an application is not a person)');
// the screen
$s = page($priya, '/bases/' . $id . '/share');
ok($s['code'] === 200 && str_contains($s['body'], 'id="base-members-table"') && str_contains($s['body'], 'id="share-form-member"') && str_contains($s['body'], 'id="share-form-department"')
    && str_contains($s['body'], 'id="share-form-guest"') && str_contains($s['body'], 'id="share-form-application"') && str_contains($s['body'], 'id="visibility-form"'), 'the share screen: the table, four add forms and Visibility');
ok(str_contains($s['body'], 'data-picker-source="person"') && str_contains($s['body'], 'data-picker-source="guest"') && !preg_match('/<select[^>]*name="member"/', $s['body']), 'a person and a guest are chosen through the record picker, never a select of everyone');
ok(str_contains($s['body'], 'a guest sees only the bases named to them') || str_contains($s['body'], 'A guest sees only the bases named to them'), 'the guest form says what a guest sees');
ok(str_contains($s['body'], 'The kernel must also approve a connection from that application.'), 'the application form says the kernel approves the connection');
$r = page(as_member(REID), '/bases/' . $id . '/share');
ok($r['code'] === 404 || $r['code'] === 403, 'a Reader with no share cannot open it (' . $r['code'] . ')');
pdo()->exec("INSERT INTO base_members (base_id, principal_kind, principal_id, level) VALUES ($id, 'member', " . REID . ", 'read') ON CONFLICT DO NOTHING");
$r = page(as_member(REID), '/bases/' . $id . '/share');
ok($r['code'] === 403, 'a Reader on the base → 403');
// the picker sources
$p = req('GET', '/pick/?source=person&q=SMOKE', ['jar' => $priya]);
ok($p['code'] === 200 && str_contains($p['body'], 'SMOKE Marco') && str_contains($p['body'], 'SMOKE Seamus') && !str_contains($p['body'], 'SMOKE Ann'), 'the person picker lists people and agents, never a guest');
$p = req('GET', '/pick/?source=guest', ['jar' => $priya]);
ok($p['code'] === 200 && str_contains($p['body'], 'SMOKE Ann') && !str_contains($p['body'], 'SMOKE Marco'), 'the guest picker lists the guests alone');
$p = req('GET', '/pick/?source=person', ['jar' => as_member(REID)]);
ok($p['code'] === 403, 'a Reader cannot search the person picker');
finish();
