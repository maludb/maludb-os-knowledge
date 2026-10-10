<?php
/** Slice 4 proof — the month's text limit (builds.md "Proof", bullet 6). */
require __DIR__ . '/lib.php';

echo "13. The monthly limit\n";
world4();
$lb = curated_base('SMOKE Limits', 'smoke-limits');
act(as_member(PRIYA), '/bases/members/add.php', ['base' => $lb, 'member' => SEAMUS, 'level' => 'curate']);
pdo()->exec("UPDATE knowledge_bases SET monthly_text_mb_limit = 1 WHERE id = $lb");
$para = static fn (int $i): string => str_repeat("Section $i of the long manual explains the Zorblat Pump at length. ", 70);
add_text($lb, 'SMOKE Long Manual', implode("\n\n", array_map(static fn ($i) => "# Part $i\n\n" . $para($i), range(1, 5))));
worker();
$sizes = array_map('intval', array_column(q("SELECT length(text) AS n FROM sections WHERE base_id = :b ORDER BY position", ['b' => $lb]), 'n'));
$limit = 1048576;
$pre = $limit - $sizes[0] - intdiv($sizes[1], 2);
pdo()->exec("INSERT INTO ai_usage (base_id, month, kind, calls, chars) VALUES ($lb, date_trunc('month', now())::date, 'extract', 100, $pre),
                                                                         ($lb, (date_trunc('month', now()) - interval '1 month')::date, 'extract', 999, $limit)");
[, $d] = screen(as_member(PRIYA), "/bases/$lb/builds");
$e = $d['estimate'];
ok((int) $e['month_used_chars'] === $pre && (int) $e['month_limit_chars'] === $limit && $e['within_limit'] === false, 'the estimate: ' . number_format($pre) . ' used of 1 MB, not within the limit (last month\'s use does not count)');
ok(str_contains(page(as_member(PRIYA), "/bases/$lb/builds")['body'], 'Stops when the limit is reached: ' . number_format($limit - $pre) . ' of ' . number_format(array_sum($sizes)) . ' characters fit.'), 'the card: "stops when the limit is reached: X of Y fits"');
[$c, $b] = act_token('/builds/start.php', ['base' => $lb], as_agent(run_token(SEAMUS, 7451)));
ok($c === 422 && msg($b) === "An agent's build must fit this month's limit: it needs " . number_format(array_sum($sizes)) . ' characters, ' . number_format($limit - $pre) . ' remain.', 'the expert\'s start: "' . msg($b) . '"');
$p0 = told(PRIYA, 'limit_near'); $m0 = told(MARCO, 'limit_near'); $f0 = told(PRIYA, 'build_failed');
[$c, $b] = start(PRIYA, $lb);
$id = (int) ($b['record_id'] ?? 0);
ok($c === 200, 'Priya\'s start is allowed');
worker();
$r = build_row($id);
ok($r['status'] === 'failed' && $r['error'] === 'monthly_limit' && (int) $r['items_done'] === 1, 'the build stops at the limit: failed / monthly_limit after one section');
ok(count(sections_by($lb, 'queued')) === count($sizes) - 1, 'the remaining sections still queued');
ok((int) usage_month($lb)['chars'] === $pre + $sizes[0], 'ai_usage: the section sent counted, nothing more');
ok(told(PRIYA, 'limit_near') === $p0 + 1 && told(MARCO, 'limit_near') === $m0 + 1 && told(PRIYA, 'build_failed') === $f0 + 1, 'Priya and Marco told limit_near once, build_failed once');
ok(str_contains((string) one("SELECT title FROM notifications WHERE member_id = :m AND kind = 'limit_near' ORDER BY id DESC LIMIT 1", ['m' => PRIYA]), '% of its monthly text'), 'the warning names the share: "' . one("SELECT title FROM notifications WHERE member_id = :m AND kind = 'limit_near' ORDER BY id DESC LIMIT 1", ['m' => PRIYA]) . '"');
ok(str_contains(page(as_member(PRIYA), "/builds/$id")['body'], 'This month&#039;s text limit was reached — ' . (count($sizes) - 1) . ' sections left for next month'), 'build-view: the limit\'s words with the count');
pdo()->exec("UPDATE knowledge_bases SET auto_extract = true WHERE id = $lb");
worker();
worker();
ok(live_build($lb) === null, 'with auto-extract on, the next passes queue no automatic build (the month is spent for these sections)');
ok(told(PRIYA, 'limit_near') === $p0 + 1 && told(PRIYA, 'build_failed') === $f0 + 1, 'and nobody is told again');
pdo()->exec("UPDATE knowledge_bases SET auto_extract = false WHERE id = $lb");
finish();
