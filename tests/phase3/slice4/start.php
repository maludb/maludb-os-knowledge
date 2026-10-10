<?php
/** Slice 4 proof — starting a build (builds.md "Proof", bullet 2: the start, its items and its log row; the build view before the worker). */
require __DIR__ . '/lib.php';

echo "2. Start\n";
$w = world4();
$bid = $w['base'];
cancel_live($bid);
$priya = as_member(PRIYA);
$queued = count(sections_by($bid, 'queued')) + count(sections_by($bid, 'stale')) + count(sections_by($bid, 'failed'));
$chars = (int) one("SELECT COALESCE(sum(length(text)), 0) FROM sections WHERE base_id = :b AND status IN ('queued', 'stale', 'failed')", ['b' => $bid]);
$since = last_activity_id();
[$c, $b] = start(PRIYA, $bid);
$id = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $id > 0 && ($b['location'] ?? '') === "/builds/$id" && ($b['refresh'] ?? '') === 'buildsChanged', 'Priya starts: 200, the location /builds/{id}, refresh buildsChanged');
ok(($b['did'] ?? '') === "Building $queued sections, " . number_format($chars) . ' characters', 'did: "' . ($b['did'] ?? '') . '"');
$r = build_row($id);
ok($r['status'] === 'queued' && $r['kind'] === 'extract' && (int) $r['started_by'] === PRIYA && (int) $r['guideline_version'] === base_version($bid), 'the build: queued, extract, started by Priya, stamped with the base\'s guideline version');
ok((int) $r['items_total'] === $queued && (int) one('SELECT count(*) FROM build_items WHERE build_id = :id', ['id' => $id]) === $queued, "an item for every queued, stale and failed section ($queued)");
$log = activity('build.start', $since);
$after = json_decode($log[0]['after'] ?? '{}', true);
ok(count($log) === 1 && $log[0]['source'] === 'web' && (int) $log[0]['base_id'] === $bid && (int) $log[0]['entity_id'] === $id, 'build.start logged once (web, base_id, the build)');
ok(($after['prompt_sha256'] ?? '') === kn_compile_prompt(pdo(), $bid)['sha256'] && $after['sections'] === $queued && $after['characters'] === $chars && $after['auto'] === false && $after['within_limit'] === true,
    '…with the prompt\'s hash, the sections, the characters, within_limit, auto false');
[$c, $b] = start(PRIYA, $bid);
ok($c === 422 && msg($b) === 'A build is already running.', 'a second start: "' . msg($b) . '"');

echo "   The build view\n";
[$c, $d] = screen($priya, "/builds/$id");
ok($c === 200 && ($d['progress']['status'] ?? '') === 'queued' && (int) $d['progress']['items_queued'] === $queued && count($d['items'] ?? []) === $queued, 'build-view (JSON): the progress, every item');
$h = page($priya, "/builds/$id")['body'];
ok(str_contains($h, 'id="build-progress" hx-get="/builds/' . $id . '/progress" hx-trigger="every 5s [document.visibilityState==\'visible\']"') && str_contains($h, 'id="build-cancel"') && str_contains($h, 'id="build-items"'),
    'the page polls its progress every 5 s while visible, offers Cancel and the items');
$p = page($priya, "/builds/$id/progress", ['headers' => ['HX-Request: true']]);
ok($p['code'] === 200 && str_contains($p['body'], 'hx-trigger="every 5s'), 'the progress partial answers 200 and keeps polling while the build is queued');
ok(page(as_member(REID), "/builds/$id")['code'] === 403 && page(as_member(BEA), "/builds/$id")['code'] === 404 && page($priya, '/builds/999999')['code'] === 404, 'Reid → 403, Bea → 404, an unknown build → 404');
ok(count(activity('screen.view', $since)) >= 1 && (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'screen.view' AND after->>'build_id' = :b", ['s' => $since, 'b' => (string) $id]) === 1, 'build-view logged screen.view with the build id');
$builds = page($priya, "/bases/$bid/builds")['body'];
ok(str_contains($builds, 'id="build-running"') && str_contains($builds, 'id="build-row-' . $id . '"'), 'the builds page shows the running card and the build\'s row');
finish();
