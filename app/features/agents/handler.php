<?php
declare(strict_types=1);

/**
 * The prelude of the agent screens and handlers (slice 8): slice 1's base prelude, the dispatch code and its reads, the notice words. The log row of a
 * person's request or an admin's retry carries base_id; the worker's rows are dispatch.php's.
 */
require_once dirname(__DIR__) . '/bases/handler.php';
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';

/** A person's request for the expert's work on a base: propose_areas | draft_guidelines — the shared body of the two handlers. */
function agent_request_handle(string $kind, string $landPath, string $event): never
{
    kn_handler_begin();
    require_human();
    $pdo = db();
    $b = base_from_request($pdo);
    $bid = (int) $b['base_id'];
    require_base_right($bid, 'base.manage');
    require_base_live($b);
    $me = (int) current_member_id();
    $r = kn_guard($pdo, static function () use ($pdo, $bid, $kind, $me): array {
        $pdo->beginTransaction();
        $r = request_dispatch($pdo, $bid, $kind, $me);
        if (!$r['existing']) {
            log_activity($pdo, 'agent.dispatch', 'agent_dispatch', $r['dispatch_id'], ['base_id' => $bid, 'after' => ['dispatch_id' => $r['dispatch_id'], 'agent_member_id' => expert_member_id($pdo),
                'kind' => $kind, 'base_id' => $bid, 'requested' => true]]);
        }
        $pdo->commit();
        return $r;
    });
    kn_done($r['existing'] ? 'The expert is already on it.' : 'The Knowledge Expert has been asked — its proposals appear here.', $r['dispatch_id'],
        kn_land('/bases/' . $bid . '/' . $landPath, $r['existing'] ? 'already' : 'asked'), $event, ['base_id' => $bid, 'existing' => $r['existing'], 'kind' => $kind]);
}
