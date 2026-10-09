<?php
/** Slice 1 proof — visibility and reach: seven actors × a private and an internal base; the admin's look (bases-core.md "Proof", bullet 4). */
require __DIR__ . '/lib.php';

echo "4. Visibility and reach\n";
$priya = as_member(PRIYA);
$p = make_base(PRIYA, 'SMOKE Private Reach');
$i = make_base(PRIYA, 'SMOKE Internal Reach');
foreach ([$p, $i] as $b) {
    act($priya, '/bases/members/add.php', ['base' => $b, 'member' => MARCO, 'level' => 'curate']);
    act($priya, '/bases/members/add.php', ['base' => $b, 'department' => ACCOUNTING, 'level' => 'read']);
    act($priya, '/bases/members/add.php', ['base' => $b, 'member' => SEAMUS, 'level' => 'read']);
    act($priya, '/bases/members/add-application.php', ['base' => $b, 'application' => 'helpdesk']);
}
act($priya, '/bases/members/add-guest.php', ['base' => $p, 'guest' => ANN]);
$since = last_activity_id();
[$c, $b] = act($priya, '/bases/visibility.php', ['base' => $i, 'visibility' => 'internal']);
ok($c === 200 && base_row_of($i)['visibility'] === 'internal' && (int) one("SELECT count(*) FROM base_members WHERE base_id = :b AND principal_kind = 'everyone'", ['b' => $i]) === 1,
    'base_visibility_set private → internal adds the everyone row');
$v = activity('base.visibility_set', $since);
ok(count($v) === 1 && json_decode($v[0]['before'], true)['visibility'] === 'private' && json_decode($v[0]['after'], true)['visibility'] === 'internal', 'logged base.visibility_set before / after');
$expect = [
    'the owner (Priya)'                     => [PRIYA, [$p, $i]],
    'a curate share (Marco)'                => [MARCO, [$p, $i]],
    'a department share (Bea, Accounting)'  => [BEA, [$p, $i]],
    'an insider with no share (Reid)'       => [REID, [$i]],
    'a guest named on the private one (Ann)' => [ANN, [$p]],
    'an agent named (Seamus)'               => [SEAMUS, [$p, $i]],
];
foreach ($expect as $who => [$m, $want]) {
    $got = array_values(array_intersect(visible_to($m), [$p, $i]));
    sort($want);
    ok($got === $want, "$who: mcp_bases answers " . json_encode($got));
    if ($m !== SEAMUS) {
        $html = page(as_member($m), '/bases')['body'];
        $cards = [];
        foreach ([$p, $i] as $b) { if (str_contains($html, 'id="base-card-' . $b . '"')) { $cards[] = $b; } }
        ok($cards === $want, "$who: the list shows exactly those cards " . json_encode($cards));
    }
}
$app = array_map('intval', array_column(q("SELECT kn_application_base_ids('helpdesk') AS id"), 'id'));
ok(in_array($p, $app, true) && in_array($i, $app, true), 'an application principal (helpdesk) reaches both through kn_application_base_ids()');
pdo()->exec("SELECT set_config('app.member_id', '', false)");
ok(array_intersect(array_map('intval', array_column(q('SELECT kn_visible_base_ids() AS id'), 'id')), [$p, $i]) === [], '…and never through kn_visible_base_ids() (no member, nothing)');
// a guest never reaches an internal base, even when it is internal
ok(!in_array($i, visible_to(ANN), true), 'a guest never sees the internal base');
$r = page(as_member(ANN), '/bases/' . $i);
ok($r['code'] === 404, 'and opening it is a 404 for her');

echo "   The admin's look\n";
$avery = as_member(AVERY);
$since = last_activity_id();
$list = page($avery, '/bases');
ok(str_contains($list['body'], 'id="base-card-' . $p . '-admin-view"') && !str_contains($list['body'], 'id="base-card-' . $i . '-admin-view"'), 'Avery\'s list marks the private base "admin view", not the internal one');
ok(count(activity('base.admin_view', $since)) === 0, 'the list logs nothing');
page($avery, '/bases/' . $p);
page($avery, '/bases/' . $p . '/share');
$a = activity('base.admin_view', $since);
ok(count($a) === 2 && (int) $a[0]['base_id'] === $p && json_decode($a[0]['after'], true)['screen'] === 'base-view' && json_decode($a[1]['after'], true)['screen'] === 'base-share',
    'Avery opens Priya\'s private base: one base.admin_view per request (two screens, two rows)');
$since = last_activity_id();
page($avery, '/bases/' . $i);
$own = make_base(AVERY, 'SMOKE Avery Own');
page($avery, '/bases/' . $own);
ok(count(activity('base.admin_view', $since)) === 0, 'opening an internal base or her own logs none');
act($priya, '/bases/members/add.php', ['base' => $p, 'department' => ENGINEERING, 'level' => 'read']);
$since = last_activity_id();
page($avery, '/bases/' . $p);
ok(count(activity('base.admin_view', $since)) === 0, 'once the base is shared with her department, none');
[$c, $b] = act($priya, '/bases/visibility.php', ['base' => $i, 'visibility' => 'private']);
ok($c === 200 && (int) one("SELECT count(*) FROM base_members WHERE base_id = :b AND principal_kind = 'everyone'", ['b' => $i]) === 0, 'back to private removes the everyone row');
ok(!in_array($i, visible_to(REID), true), 'and the insider with no share no longer sees it');
[$c, $b] = act($priya, '/bases/visibility.php', ['base' => $i, 'visibility' => 'public']);
ok($c === 422 && isset(fields($b)['visibility']), 'a visibility other than private or internal is refused on its field');
[$c, $b] = act(as_member(BEA), '/bases/visibility.php', ['base' => $i, 'visibility' => 'internal']);
ok($c === 403, 'a reader of the base may not set its visibility');
finish();
