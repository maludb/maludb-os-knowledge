<?php
declare(strict_types=1);
/**
 * Action `base_share_application` (base.share on a curated base; external_send — pauses for an agent) — `application` (a sibling's
 * catalog key), level read: the CHECK refuses curate in its words. The K7 shares answer from kn_application_base_ids() (slice 7); the
 * kernel must also approve a connection from that application. Nobody is told — an application is not a person. Logged
 * `base.share_application`.
 */
require_once dirname(__DIR__, 3) . '/app/features/bases/handler.php';
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$id = (int) $b['base_id'];
require_base_right($id, 'base.share');
require_base_live($b);
$app = mb_strtolower((string) (req_val('application') ?? ''));
if ($app === '') {
    kn_refuse_fields(['application' => 'Give the application\'s catalog key, such as helpdesk.']);
}
$level = req_has('level') && (string) req_val('level') !== '' ? (string) req_val('level') : 'read';
base_share_finish($pdo, $b, 'application', null, $app, $level, 'base.share_application', $app);
