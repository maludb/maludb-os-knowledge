<?php
/** Slice 8 proof — the worker's feeds step (agents-feeds.md "Proof": sync.php): K7 reads through the fake kernel, sources keyed by the provider's ids. */
require __DIR__ . '/lib.php';

$w = agents_world();
$m = $w['manuals'];
$p = $w['policies'];
$cara = as_member(CARA);
pdo()->exec("INSERT INTO focus_areas (base_id, name, slug, description, status) VALUES ($m, 'Warranty', 'warranty', 'Guarantee terms.', 'active') ON CONFLICT DO NOTHING");
$area = (int) one("SELECT id FROM focus_areas WHERE base_id = :b AND slug = 'warranty'", ['b' => $m]);
pdo()->exec("DELETE FROM feeds WHERE base_id IN ($m, $p)");
$page = static fn (string $id, string $title, string $at, ?string $md = null): array => ['id' => $id, 'title' => $title, 'updated_at' => $at] + ($md === null ? [] : ['markdown' => $md]);
$text = static fn (string $what): string => "# $what\n\n" . str_repeat("The $what text is long enough to be cut into a section of its own. " . SENTINEL . ' ', 6);
$due = static function (int $fid): void { pdo()->exec("UPDATE feeds SET next_sync_at = now() WHERE id = $fid"); };

echo "1. A Spaces feed: new pages\n";
[$c, $b] = act($cara, '/feeds/save.php', ['base' => $m, 'source_tool' => 'spaces.pages_index', 'space' => 'product', 'focus_area' => $area]);
$fid = (int) $b['record_id'];
reads_state([
    'spaces.pages_index' => ['schema' => 'os.spaces-pages/1', 'rows' => [$page('p1', 'Warranty terms', '2026-10-01T10:00:00Z', $text('Warranty terms')), $page('p2', 'Returns', '2026-10-02T10:00:00Z'), $page('p3', 'Repairs', '2026-10-03T10:00:00Z'), ['title' => 'No id here'], ['id' => 'p9']]],
    'spaces.page_markdown' => ['by' => ['p2' => ['markdown' => $text('Returns')], 'p3' => $text('Repairs')]],
]);
reads_clear();
$since = last_activity_id();
$line = worker_at();
$rd = reads_log();
ok(count($rd) === 3 && $rd[0]['provider'] === 'spaces' && $rd[0]['tool'] === 'pages_index' && $rd[0]['arguments'] === ['space' => 'product'] && $rd[1]['tool'] === 'page_markdown' && $rd[1]['arguments'] === ['page' => 'p2'] && $rd[2]['arguments'] === ['page' => 'p3'], 'one index read with the arguments, then page_markdown for the two pages without a body (' . count($rd) . ' reads)');
$src = q("SELECT * FROM sources WHERE feed_id = :f ORDER BY feed_item_key", ['f' => $fid]);
ok(count($src) === 3 && array_column($src, 'feed_item_key') === ['p1', 'p2', 'p3'] && array_unique(array_column($src, 'kind')) === ['feed'] && $src[0]['url_etag'] === '2026-10-01T10:00:00Z' && $src[2]['url_etag'] === '2026-10-03T10:00:00Z', 'three feed sources keyed by the page ids, url_etag = updated_at');
ok($src[0]['title'] === 'Warranty terms' && $src[0]['origin'] === 'feed:spaces.pages_index#p1' && $src[0]['added_by'] === null && (int) $src[0]['base_id'] === $m, 'title, origin feed:<provider>.<tool>#<id>, no person added it');
ok((int) one('SELECT count(*) FROM source_focus_areas WHERE focus_area_id = :a AND source_id IN (' . implode(',', array_column($src, 'id')) . ')', ['a' => $area]) === 3, 'the feed\'s area is assigned to all three');
$sy = activity('feed.sync', $since);
$a = after_of($sy[0] ?? ['after' => '{}']);
ok(count($sy) === 1 && ($a['new'] ?? null) === 3 && ($a['changed'] ?? null) === 0 && ($a['gone'] ?? null) === 0 && ($a['skipped'] ?? null) === 2 && ($a['items'] ?? null) === 3 && $sy[0]['source'] === 'cron' && (int) $sy[0]['base_id'] === $m, 'feed.sync: new 3, skipped 2 (an item without an id, one without a title), items 3 — source cron');
$ad = activity('source.add', $since);
ok(count($ad) === 3 && $ad[0]['source'] === 'cron' && (int) (after_of($ad[0])['feed_id'] ?? 0) === $fid && !in_log('Warranty terms') && !in_log('Returns') && !in_log(SENTINEL), 'source.add ×3 (source cron, after.feed_id) — never a title or a word of a page');
$f = feed_row($fid);
ok($f['last_status'] === 'ok' && $f['last_error'] === null && (int) $f['items'] === 3 && strtotime((string) $f['next_sync_at']) > time() + 3000, 'the feed: ok, 3 items, the next sync an hour on');
ok(str_contains($line, 'feeds 1'), 'the worker said so: ' . $line);
worker_at();
$ready = q("SELECT status, sections_count FROM sources WHERE feed_id = :f ORDER BY feed_item_key", ['f' => $fid]);
ok(array_column($ready, 'status') === ['ready', 'ready', 'ready'] && min(array_column($ready, 'sections_count')) >= 1, 'the next pass cuts them as pasted text (ready, sections)');
$h = page($cara, '/sources/' . $src[0]['id'])['body'];
ok(str_contains($h, 'From a feed'), 'the source page says it is from a feed');
[$c, $b] = act($cara, '/sources/remove.php', ['source' => $src[0]['id']]);
ok($c === 422 && str_contains(msg($b), 'removed by its feed'), 'a person\'s source_remove on a fed source is refused: "' . msg($b) . '"');
[$c, $b] = act($cara, '/sources/replace.php', ['source' => $src[0]['id'], 'kind' => 'text', 'title' => 'x', 'text' => 'y']);
ok($c === 422 && str_contains(msg($b), 'replaced by its feed'), 'and source_replace: "' . msg($b) . '"');

echo "2. The next sync: changed, same words, gone\n";
$old1 = $src[0];
$old2 = $src[1];
$old3 = $src[2];
reads_state([
    'spaces.pages_index' => ['rows' => [$page('p1', 'Warranty terms', '2026-10-05T10:00:00Z', $text('Warranty terms') . "\n\nA new clause about transfers.\n"), $page('p2', 'Returns', '2026-10-06T10:00:00Z')]],
    'spaces.page_markdown' => ['by' => ['p2' => ['markdown' => $text('Returns')]]],
]);
reads_clear();
[$c] = act($cara, '/feeds/sync.php', ['feed' => $fid]);
$since = last_activity_id();
worker_at();
$n1 = source_by_key($fid, 'p1');
$o1 = q('SELECT * FROM sources WHERE id = :id', ['id' => $old1['id']])[0];
ok((int) $n1['id'] !== (int) $old1['id'] && $n1['url_etag'] === '2026-10-05T10:00:00Z' && $o1['status'] === 'forgotten' && (int) $o1['superseded_by'] === (int) $n1['id'], 'p1 changed (new updated_at, new words) → a new source; the old one superseded and forgotten');
ok((int) one('SELECT count(*) FROM source_focus_areas WHERE source_id = :s AND focus_area_id = :a', ['s' => $n1['id'], 'a' => $area]) === 1, '…its area copied');
ok((int) one("SELECT count(*) FROM sources WHERE feed_id = :f AND feed_item_key = 'p1' AND status <> 'forgotten'", ['f' => $fid]) === 1, 'one live source per feed item');
$n2 = source_by_key($fid, 'p2');
ok((int) $n2['id'] === (int) $old2['id'] && $n2['url_etag'] === '2026-10-06T10:00:00Z' && $n2['status'] === 'ready', 'p2: a new updated_at, the same words → only url_etag moved (the same source, still ready)');
$o3 = q('SELECT * FROM sources WHERE id = :id', ['id' => $old3['id']])[0];
ok($o3['status'] === 'forgotten' && (int) $o3['sections_count'] === 0, 'p3 is not in the index → forgotten (its sections go with it)');
$sy = activity('feed.sync', $since);
$a = after_of($sy[0] ?? ['after' => '{}']);
ok(($a['changed'] ?? null) === 1 && ($a['gone'] ?? null) === 1 && ($a['new'] ?? null) === 0 && ($a['unchanged'] ?? null) === 1 && ($a['items'] ?? null) === 2, 'feed.sync: changed 1, gone 1, unchanged 1, items 2');
$fg = activity('source.forget', $since);
$reasons = array_map(static fn ($r) => after_of($r)['reason'] ?? '', $fg);
sort($reasons);
ok(count($fg) === 2 && $reasons === ['gone', 'replaced'] && $fg[0]['source'] === 'cron', 'source.forget ×2 (source cron): one replaced, one gone');
ok(count(activity('source.replace', $since)) === 1 && activity('source.replace', $since)[0]['source'] === 'cron', 'source.replace logged (cron)');
$rd = reads_log();
ok(count($rd) === 2 && $rd[1]['arguments'] === ['page' => 'p2'], 'a changed page without a body is read again: ' . json_encode(array_column($rd, 'tool')));
worker_at();
ok(source_by_key($fid, 'p1')['status'] === 'ready', 'the new version is cut on the next pass');

echo "3. A paged answer forgets nothing\n";
reads_state(['spaces.pages_index' => ['rows' => [$page('p1', 'Warranty terms', '2026-10-05T10:00:00Z', 'x')], 'next_cursor' => 'abc']]);
$due($fid);
worker_at();
ok(source_by_key($fid, 'p2')['status'] === 'ready' && (int) feed_row($fid)['items'] === 2, 'the index carries a paging marker → p2, missing from this page, stays');

echo "4. Refusals are states, never errors\n";
$cases = [
    ['no_connection', ['status' => 403, 'code' => 'no_connection'], 'no_connection', 'No approved connection to that tool — a super-admin approves it in the operating system.'],
    ['not_shared', ['status' => 403, 'code' => 'not_shared', 'message' => 'That application does not share that tool.'], 'refused', 'That application does not share that tool.'],
    ['people_restricted', ['status' => 403, 'code' => 'people_restricted'], 'refused', null],
    ['invalid', ['status' => 422, 'code' => 'invalid', 'message' => 'The arguments are wrong.'], 'refused', 'The arguments are wrong.'],
    ['provider_failed', ['status' => 502, 'code' => 'provider_failed', 'message' => 'The provider did not answer.'], 'failed', 'The provider did not answer.'],
    ['server_error', ['status' => 500, 'code' => 'server_error', 'message' => 'The kernel broke.'], 'failed', 'The kernel broke.'],
];
foreach ($cases as [$code, $read, $status, $words]) {
    reads_state(['spaces.pages_index' => $read]);
    $due($fid);
    $since = last_activity_id();
    worker_at();
    $f = feed_row($fid);
    $fl = activity('feed.fail', $since);
    ok($f['last_status'] === $status && ($words === null || $f['last_error'] === $words) && count($fl) === 1 && (after_of($fl[0])['code'] ?? '') === $code && (int) $f['items'] === 2, "$code → $status" . ($words === null ? '' : " \"{$f['last_error']}\"") . ', feed.fail logged, the sources it has stay');
}
$h = page($cara, '/bases/' . $m . '/feeds');
ok($h['code'] === 200 && str_contains($h['body'], 'failed') && str_contains($h['body'], 'The kernel broke.') && !str_contains($h['body'], 'Fatal error'), 'the feeds page shows the state and the error, no error page');
reads_state(['spaces.pages_index' => ['status' => 403, 'code' => 'no_connection']]);
$due($fid);
worker_at();
$h = page($cara, '/bases/' . $m . '/feeds')['body'];
ok(str_contains($h, 'no connection') && str_contains($h, 'a super-admin approves it in the operating system'), 'no_connection is shown with the kernel\'s sentence');
kernel_state(static function (array $s): array { unset($s['reads']); return $s; });
$due($fid);
worker_at();
ok(feed_row($fid)['last_status'] === 'no_connection', 'no entry at all in the fake kernel (an unapproved connection) → no_connection');

echo "5. Help Desk: bodies inline, many articles\n";
[$c, $b] = act($cara, '/feeds/save.php', ['base' => $p, 'source_tool' => 'helpdesk.articles_published']);
$hd = (int) $b['record_id'];
$arts = [];
for ($i = 1; $i <= 25; $i++) { $arts[] = ['article_id' => 'a' . $i, 'title' => 'Article ' . $i, 'revised_at' => '2026-09-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'body_md' => $text('Article ' . $i)]; }
reads_state(['helpdesk.articles_published' => ['items' => $arts]]);
reads_clear();
$since = last_activity_id();
worker_at();
$rd = reads_log();
ok(count($rd) === 1 && $rd[0]['provider'] === 'helpdesk' && $rd[0]['tool'] === 'articles_published' && $rd[0]['arguments'] === [], 'one read, bodies inline → no second read (arguments {})');
ok((int) one("SELECT count(*) FROM sources WHERE feed_id = :f AND status <> 'forgotten'", ['f' => $hd]) === 20 && (after_of(activity('feed.sync', $since)[0])['more'] ?? null) === true, 'at most 20 items a pass; the log says there is more');
ok(strtotime((string) feed_row($hd)['next_sync_at']) <= time() + 2, 'the rest is due at the very next pass, not an interval later');
worker_at();
ok((int) one("SELECT count(*) FROM sources WHERE feed_id = :f AND status <> 'forgotten'", ['f' => $hd]) === 25 && (int) feed_row($hd)['items'] === 25, 'the next pass brings the other five (25 items)');

echo "6. Delete: the sources are forgotten first\n";
$ids = array_column(q("SELECT id FROM sources WHERE feed_id = :f AND status <> 'forgotten' ORDER BY id", ['f' => $hd]), 'id');
$since = last_activity_id();
[$c, $b] = act($cara, '/feeds/delete.php', ['feed' => $hd]);
$gone = q("SELECT status FROM sources WHERE id IN (" . implode(',', $ids) . ')');
ok($c === 200 && ($b['forgotten'] ?? 0) === 25 && count($gone) === 25 && array_unique(array_column($gone, 'status')) === ['forgotten'] && feed_row($hd) === [], 'Delete → its 25 sources forgotten, then the row is gone');
$fd = activity('feed.delete', $since);
ok(count($fd) === 1 && (after_of($fd[0])['forgotten'] ?? null) === 25 && count(activity('source.forget', $since)) === 25, 'feed.delete logged (its own event) with the count; source.forget ×25');
$sec = (int) one("SELECT count(*) FROM sections WHERE source_id IN (" . implode(',', $ids) . ") AND status <> 'forgotten'");
ok($sec === 0, 'their sections are forgotten with them');
[$c] = act($cara, '/feeds/delete.php', ['feed' => $hd]);
ok($c === 404, 'deleting it again → 404');
[$c] = act(as_member(PRIYA), '/feeds/delete.php', ['feed' => $fid]);
ok($c === 403 && feed_row($fid) !== [], 'Priya may not delete → 403');
finish();
