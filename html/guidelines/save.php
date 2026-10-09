<?php
declare(strict_types=1);
/**
 * Action `guideline_save` (base.manage on a curated base; free for an agent) — with or without `guideline`: kind (one of the five), title
 * (≤ 200), body (1–20,000), active, position. A field left out keeps its value (the partial-update target). The database numbers the
 * version, writes its row and — for a graph-shaping change — bumps the base's version and marks the built sections stale; then the
 * compiled prompt is sent best-effort. Logged `guideline.save` (never the body), and `guideline.version` + `section.stale` when the version
 * moved. Location /bases/{id}/guidelines#guideline-card-{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
kn_handler_begin();
$pdo = db();
$cur = null;
if (request_integer('guideline') !== null) {
    [$cur, $b] = guideline_from_request($pdo);
} else {
    $b = base_from_request($pdo);
}
$bid = (int) $b['base_id'];
require_base_right($bid, 'base.manage');
require_base_live($b);
$errors = [];
$f = [];
if ($cur === null || req_has('kind')) {
    $f['kind'] = (string) (req_val('kind') ?? '');
    if (!isset(GUIDELINE_KINDS[$f['kind']])) {
        $errors['kind'] = 'The kind is extraction, vocabulary, exclusion, answering or audience.';
    }
}
if (req_has('title')) {
    $f['title'] = (string) req_val('title');
}
if ($cur === null || req_has('body')) {
    $f['body'] = trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')));
    if ($f['body'] === '' || mb_strlen($f['body']) > 20000) {
        $errors['body'] = 'Write the guideline: 1 to 20,000 characters.';
    }
}
if (req_has('is_active')) {
    $f['is_active'] = kn_yes('is_active');
}
if (req_has('position') && (string) req_val('position') !== '') {
    $f['position'] = kn_int('position', null, 0, 100000, 'The position', $errors);
}
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$me = (int) current_member_id();
$origin = current_agent_run_id() !== null ? 'agent' : 'person';
$gid = $cur === null ? null : (int) $cur['guideline_id'];
$r = kn_guard_fields($pdo, static function () use ($pdo, $gid, $bid, $f, $origin, $me): array {
    $pdo->beginTransaction();
    $r = save_guideline($pdo, $gid, $bid, $f, $origin, $me);
    log_version_move($pdo, $bid, $r);
    $pdo->commit();
    return $r;
}, guideline_field_refusals());
$sent = send_compiled_after($pdo, $bid, $r);
$row = q_guideline_row($pdo, $r['guideline_id']);
guideline_log($pdo, 'guideline.save', $bid, 'guideline', $r['guideline_id'], ['after' => ['guideline_id' => $r['guideline_id'], 'kind' => $row['kind'], 'title' => $row['title'],
    'version_no' => $r['version_no'], 'active' => (bool) $row['is_active'], 'origin' => $row['origin'], 'shapes_graph' => $r['shapes_graph'], 'body_chars' => mb_strlen((string) $row['body']),
    'created' => $gid === null, 'engine_synced' => $sent === null ? null : (bool) ($sent['ok'] ?? false)]]);
kn_done(guideline_did($r, $sent), $r['guideline_id'], kn_land('/bases/' . $bid . '/guidelines', guideline_notice_key($r, $sent), 'guideline-card-' . $r['guideline_id']), 'guidelinesChanged',
    ['base_id' => $bid, 'version' => $r['base_version_after'], 'stale' => $r['stale_total'], 'engine_synced' => $sent === null ? null : (bool) ($sent['ok'] ?? false)]);

/** The guideline's row after the save (base table: the log's facts). */
function q_guideline_row(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT kind, title, body, is_active, origin FROM guidelines WHERE id = :id');
    $st->execute(['id' => $id]);
    return $st->fetch();
}
