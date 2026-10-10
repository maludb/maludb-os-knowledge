<?php
/** Slice 5 proof — the database's own words through kn_guard() (ask.md divergence 5, the kit prerequisite). */
require __DIR__ . '/lib.php';

echo "13. The database's words\n";
ask_world();
$try = static function (string $sql): array {
    $out = php_run('register_shutdown_function(function () { echo json_encode(["code" => http_response_code(), "status" => $GLOBALS["__action_status"] ?? null]); });
        ob_start(); kn_guard($pdo, function () use ($pdo, $arg) { $pdo->exec($arg); }); ob_end_clean(); echo json_encode(["code" => 200]); exit;', PRIYA, $sql);
    preg_match('/\{"code".*$/s', $out, $m);
    return json_decode($m[0] ?? '{}', true) ?: ['raw' => $out];
};
$r = $try("DO \$\$BEGIN RAISE EXCEPTION 'This answer is already pinned' USING ERRCODE = 'unique_violation'; END\$\$");
ok(($r['code'] ?? 0) === 422 && ($r['status']['data']['error'] ?? '') === 'This answer is already pinned', 'a 23505 RAISE shows its own words (not "That name is already taken.")');
$r = $try("DO \$\$BEGIN RAISE EXCEPTION 'Only an answered question is pinned' USING ERRCODE = 'check_violation'; END\$\$");
ok(($r['code'] ?? 0) === 422 && ($r['status']['data']['error'] ?? '') === 'Only an answered question is pinned', 'a 23514 RAISE shows its own words');
$r = $try('SELECT kn_pin_answer(0, ' . CARA . ', NULL)');
ok(($r['code'] ?? 0) === 404 && ($r['status']['data']['error'] ?? '') === 'No such question', 'a P0002 RAISE ("No such question") is a 404');
$fid = (int) one("SELECT id FROM questions ORDER BY id LIMIT 1");
pdo()->exec("INSERT INTO question_feedback (question_id, member_id, rating) VALUES ($fid, " . DEE . ", 'up') ON CONFLICT DO NOTHING");
$r = $try("INSERT INTO question_feedback (question_id, member_id, rating) VALUES ($fid, " . DEE . ", 'up')");
ok(($r['code'] ?? 0) === 422 && ($r['status']['data']['error'] ?? '') === 'That name is already taken.', 'a bare unique constraint keeps the generic sentence');
$m = php_run('try { $pdo->exec("UPDATE question_feedback SET rating = \'sideways\'"); } catch (PDOException $e) { echo json_encode([db_is_raise($e), db_message($e, "That could not be done.")]); }', PRIYA);
$m = (string) preg_replace('/^.*\R/s', '', trim($m));                     // the kit's error_log line goes to stderr first
ok(trim($m) === '[false,"That could not be done."]', 'a bare check constraint is never shown: db_message() answers the fallback' . (trim($m) === '[false,"That could not be done."]' ? '' : ' — ' . trim($m)));
finish();
