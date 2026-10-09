<?php
declare(strict_types=1);
/**
 * Actions `base_create` (no `base`; bases.create) and `base_update` (with `base`; base.manage on a curated base, an owner always) —
 * logged `base.create` (name, key, template, visibility) / `base.update` (the changed fields; description_chars, never the words;
 * template_switch). A field left out keeps its value (the partial-update target). The database is the referee: the key unique among
 * live bases, the template switched only while empty — each refusal shown on its field. Location /bases/{id}.
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
kn_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$cur = null;
if (request_integer('base') !== null || request_integer('base_id') !== null) {
    $cur = base_from_request($pdo);
    require_base_right((int) $cur['base_id'], 'base.manage');
    require_base_live($cur);
} else {
    require_right('bases.create');
}
$errors = [];
$f = [];
if ($cur === null || req_has('name')) {
    $f['name'] = (string) req_val('name');
    if ($f['name'] === '' || mb_strlen($f['name']) > 200) {
        $errors['name'] = 'Give the base a name of up to 200 characters.';
    }
}
if (req_has('key')) {
    $f['key'] = mb_strtolower((string) req_val('key'));
    if ($f['key'] === '' && $cur !== null) {
        $f['key'] = 'kb-' . (int) $cur['base_id'];
    }
    if (str_starts_with($f['key'], 'deleting-')) {
        $errors['key'] = 'A key beginning "deleting-" is kept for a base being deleted.';
    }
}
if (req_has('description')) {
    $f['description'] = (string) req_val('description');
    if (mb_strlen($f['description']) > 4000) {
        $errors['description'] = 'A description is up to 4,000 characters.';
    }
}
if (req_has('template') && (string) req_val('template') !== '') {
    $f['template'] = (string) req_val('template');
    $t = find_template($pdo, $f['template']);
    if ($t === null || (!$t['is_active'] && $f['template'] !== ($cur['template'] ?? ''))) {
        $errors['template'] = 'Choose one of the templates.';
    }
}
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$result = kn_guard_fields($pdo, static function () use ($pdo, $me, $cur, $f): array {
    $pdo->beginTransaction();
    if ($cur === null) {
        $id = create_base($pdo, $f, $me);
        $row = base_row($pdo, $id);
        base_log($pdo, 'base.create', $id, ['after' => ['name' => $row['name'], 'key' => $row['key'], 'template' => $row['template'], 'visibility' => $row['visibility']]]);
        $pdo->commit();
        return ['id' => $id, 'created' => true];
    }
    $id = (int) $cur['base_id'];
    $u = update_base($pdo, $id, $f, $me);
    $d = kn_diff($u['before'], $u['after']);
    if ($d['after'] !== [] || $u['template_switch'] !== null) {
        base_log($pdo, 'base.update', $id, ['before' => $d['before'], 'after' => $d['after'] + ($u['template_switch'] !== null ? ['template_switch' => $u['template_switch']] : [])]);
    }
    $pdo->commit();
    return ['id' => $id, 'created' => false, 'switch' => $u['template_switch']];
}, base_field_refusals((string) ($f['key'] ?? '')));
$id = (int) $result['id'];
// The compile step (slice 3) tells the engine the base's guidelines; best-effort — a create or a switch never fails on it.
if (($result['created'] || !empty($result['switch'])) && is_file(APP_ROOT . '/app/knowledge/compile.php')) {
    try {
        require_once APP_ROOT . '/app/knowledge/compile.php';
        if (function_exists('kn_compile_send')) {
            kn_compile_send($pdo, $id);
        }
    } catch (Throwable $e) {
        error_log('compile after base save: ' . $e->getMessage());
    }
}
$b = find_base($pdo, $id);
kn_done(($result['created'] ? 'Created ' : 'Saved ') . $b['name'], $id, kn_land(return_path('/bases/' . $id), $result['created'] ? 'created' : 'saved'), 'baseChanged', ['base_id' => $id]);
