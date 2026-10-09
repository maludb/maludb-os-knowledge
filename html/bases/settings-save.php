<?php
declare(strict_types=1);
/**
 * Action `base_settings_save` (base.manage on a curated base; free) — auto_extract (yes/no), sensitivity, language, and the monthly
 * limit ONLY from a holder of limits.manage (a curator posting it: 403 "You may not set the limits."). A field left out keeps its
 * value; the CHECKs of db/006 are the referee. Logged `base.update` with after.form = settings (the diff).
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$id = (int) $b['base_id'];
require_base_right($id, 'base.manage');
require_base_live($b);
$mayLimit = false;
if (req_has('monthly_text_mb_limit')) {
    require_right('limits.manage');
    $mayLimit = true;
}
$errors = [];
$f = [];
if (req_has('auto_extract')) {
    $f['auto_extract'] = kn_yes('auto_extract');
}
if (req_has('sensitivity')) {
    $f['sensitivity'] = (string) req_val('sensitivity');
}
if (req_has('language')) {
    $f['language'] = (string) req_val('language');
}
if ($mayLimit) {
    $f['monthly_text_mb_limit'] = kn_int('monthly_text_mb_limit', null, 1, 100000, 'The monthly limit', $errors);
}
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$me = (int) current_member_id();
$d = kn_guard_fields($pdo, static function () use ($pdo, $id, $f, $mayLimit, $me): array {
    $pdo->beginTransaction();
    $d = save_base_settings($pdo, $id, $f, $mayLimit, $me);
    if ($d['after'] !== []) {
        base_log($pdo, 'base.update', $id, ['before' => $d['before'], 'after' => ['form' => 'settings'] + $d['after']]);
    }
    $pdo->commit();
    return $d;
}, base_field_refusals());
kn_done('Saved the settings of ' . $b['name'], $id, kn_land(return_path('/bases/' . $id . '/settings'), 'settings'), 'baseChanged', ['base_id' => $id]);
