<?php
/** Slice 1 proof — archive, restore, delete (bases-core.md "Proof", bullet 6; divergence 2). */
require __DIR__ . '/lib.php';

echo "6. Archive, restore, delete\n";
$priya = as_member(PRIYA);
$id = make_base(PRIYA, 'SMOKE Lifecycle', ['key' => 'smoke-life']);
$since = last_activity_id();
[$c, $b] = act($priya, '/bases/archive.php', ['base' => $id]);
ok($c === 200 && base_row_of($id)['archived_at'] !== null && count(activity('base.archive', $since)) === 1, 'archive: archived_at set, base.archive logged');
$v = page($priya, '/bases/' . $id);
ok($v['code'] === 200 && str_contains($v['body'], 'id="base-view-archived"'), 'an archived base stays readable');
[$c, $b] = act($priya, '/bases/save.php', ['base' => $id, 'name' => 'SMOKE renamed']);
ok($c === 422 && msg($b) === 'Base "SMOKE Lifecycle" is archived: restore it first.', 'base_update is refused in words');
try {
    mksource($id);
    ok(false, 'a source insert is refused');
} catch (PDOException $e) {
    ok(str_contains($e->getMessage(), 'An archived base takes no sources'), 'a source insert → the database\'s words');
}
$lst = page($priya, '/bases?archived=1');
ok(str_contains($lst['body'], 'id="base-card-' . $id . '-archived"') && !str_contains(page($priya, '/bases')['body'], 'id="base-card-' . $id . '"'), 'the list hides it by default; Archived shows its card marked archived');
[$c, $b] = act($priya, '/bases/restore.php', ['base' => $id]);
ok($c === 200 && base_row_of($id)['archived_at'] === null && count(activity('base.restore', $since)) === 1, 'restore: back, base.restore logged');
act($priya, '/bases/archive.php', ['base' => $id]);
$taker = make_base(PRIYA, 'SMOKE Key Taker', ['key' => 'smoke-life']);
ok($taker > 0, 'another base takes its key meanwhile');
[$c, $b] = act($priya, '/bases/restore.php', ['base' => $id]);
ok($c === 422 && (fields($b)['key'] ?? '') === 'Another base already uses the key smoke-life: change its key first.', 'restoring then → the key field\'s sentence (' . json_encode(fields($b)) . ')');

echo "   Delete\n";
$fresh = make_base(PRIYA, 'SMOKE Never Built');
$src = mksource($fresh);
mksection($src);
$dir = APP_DIR_STORAGE . '/attachments/source-' . $src;
@mkdir($dir, 0775, true);
file_put_contents($dir . '/smoke.txt', 'synthetic');
pdo()->exec("INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path) VALUES ('source', $src, 'smoke.txt', 'text/plain', 9, repeat('a', 64), 'attachments/source-$src/smoke.txt')");
[$c, $b] = act($priya, '/bases/delete.php', ['base' => $fresh, 'confirm_name' => 'SMOKE Never']);
ok($c === 422 && isset(fields($b)['confirm_name']), 'a wrong name typed refuses the delete');
[$c, $b] = act(as_member(MARCO), '/bases/delete.php', ['base' => $fresh, 'confirm_name' => 'SMOKE Never Built']);
ok($c === 404, 'Marco, with no share, sees no base to delete');
act($priya, '/bases/members/add.php', ['base' => $fresh, 'member' => MARCO, 'level' => 'curate']);
[$c, $b] = act(as_member(MARCO), '/bases/delete.php', ['base' => $fresh, 'confirm_name' => 'SMOKE Never Built']);
ok($c === 403 && msg($b) === 'Only the owner of this knowledge base may delete it.', 'Marco (curate) cannot delete: own level');
$since = last_activity_id();
[$c, $b] = act($priya, '/bases/delete.php', ['base' => $fresh, 'confirm_name' => 'SMOKE Never Built']);
ok($c === 200 && ($b['phase'] ?? '') === 'deleted' && base_row_of($fresh) === [] && ($b['location'] ?? '') === '/bases', 'a base never in the engine is gone at once (location /bases)');
$d = activity('base.delete', $since);
ok(count($d) === 1 && json_decode($d[0]['after'], true)['phase'] === 'deleted' && (int) $d[0]['base_id'] === $fresh, 'base.delete logged, phase deleted');
ok(!is_file($dir . '/smoke.txt') && (int) one('SELECT count(*) FROM attachments WHERE record_id = :s', ['s' => $src]) === 0, 'its files and attachment rows went with it');

$built = make_base(PRIYA, 'SMOKE Built Once');
$s1 = mksource($built, 'SMOKE one');
mksection($s1, 'doc-smoke-1');
mksection($s1, 'doc-smoke-2');
$s2 = mksource($built, 'SMOKE two');
mksection($s2);
pdo()->exec("INSERT INTO builds (base_id, kind, started_by) VALUES ($built, 'extract', " . PRIYA . ')');
[$c, $b] = act($priya, '/bases/delete.php', ['base' => $built, 'confirm_name' => 'SMOKE Built Once']);
ok($c === 422 && msg($b) === 'A build is running: cancel it first.', 'delete while an extract build is queued → "A build is running"');
pdo()->exec("UPDATE builds SET status = 'cancelled', finished_at = now() WHERE base_id = $built");
act($priya, '/bases/archive.php', ['base' => $built]);
$since = last_activity_id();
[$c, $b] = act($priya, '/bases/delete.php', ['base' => $built, 'confirm_name' => 'SMOKE Built Once']);
$row = base_row_of($built);
ok($c === 200 && ($b['phase'] ?? '') === 'forgetting' && str_contains($b['did'] ?? '', 'Forgetting 2 sections'), 'with sections in the engine: phase forgetting — "' . ($b['did'] ?? '') . '"');
ok($row['key'] === 'deleting-' . $built && $row['archived_at'] !== null, 'key deleting-' . $built . ', archived (an archived base too — it is live only for the moment its build is queued)');
ok((int) one("SELECT count(*) FROM sources WHERE base_id = :b AND status <> 'forgotten'", ['b' => $built]) === 0
    && (int) one("SELECT count(*) FROM sections WHERE base_id = :b AND status <> 'forgotten'", ['b' => $built]) === 0, 'every source forgotten, their sections with them');
$fb = q("SELECT kind, status, items_total FROM builds WHERE base_id = :b AND status IN ('queued', 'running')", ['b' => $built]);
ok(count($fb) === 1 && $fb[0]['kind'] === 'forget' && (int) $fb[0]['items_total'] === 2, 'one forget build queued for the two documents');
$d = activity('base.delete', $since);
ok(count($d) === 1 && json_decode($d[0]['after'], true)['phase'] === 'forgetting' && (int) json_decode($d[0]['after'], true)['build_id'] > 0, 'base.delete logged with phase forgetting and the build');
[$c, $b] = act($priya, '/bases/restore.php', ['base' => $built]);
ok($c === 422 && msg($b) === 'This base is being deleted.', 'restore refused: "This base is being deleted."');
[$c, $b] = act($priya, '/bases/delete.php', ['base' => $built, 'confirm_name' => 'SMOKE Built Once']);
ok($c === 422 && msg($b) === 'This base is being deleted.', 'a second delete is refused the same way');
$v = page($priya, '/bases/' . $built);
ok(str_contains($v['body'], 'id="base-view-deleting"'), 'the base says it is being deleted');
[$c, $b] = act($priya, '/bases/save.php', ['name' => 'SMOKE Squatter', 'key' => 'deleting-' . $built]);
ok($c === 422 && isset(fields($b)['key']), 'nobody may take a deleting- key');
finish();
