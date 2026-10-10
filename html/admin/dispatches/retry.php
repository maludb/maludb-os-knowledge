<?php
declare(strict_types=1);
/**
 * Action `dispatch_retry` (agents.settings; free for an agent): `dispatch` — a failed or refused one goes back in the queue, due now, its attempts kept and its run, request and detail
 * cleared. Logged `agent.dispatch` (retry true). Location /admin/dispatches#dispatch-row-{id}.
 */
require_once dirname(__DIR__, 3) . '/app/features/agents/handler.php';
kn_handler_begin();
require_right('agents.settings');
$pdo = db();
$id = request_integer('dispatch') ?? request_integer('id') ?? refuse(422, 'Say which dispatch.');
$d = find_dispatch($pdo, $id) ?? refuse(404, 'Dispatch not found.');
$me = (int) current_member_id();
kn_guard($pdo, static function () use ($pdo, $id, $me, $d): void {
    $pdo->beginTransaction();
    retry_dispatch($pdo, $id, $me);
    log_activity($pdo, 'agent.dispatch', 'agent_dispatch', $id, ['base_id' => $d['base_id'], 'after' => ['dispatch_id' => $id, 'agent_member_id' => (int) $d['agent_member_id'], 'kind' => $d['kind'],
        'base_id' => $d['base_id'] === null ? null : (int) $d['base_id'], 'retry' => true]]);
    $pdo->commit();
});
kn_done('Back in the queue', $id, kn_land('/admin/dispatches', 'retried', 'dispatch-row-' . $id), 'dispatchChanged', ['dispatch_id' => $id]);
