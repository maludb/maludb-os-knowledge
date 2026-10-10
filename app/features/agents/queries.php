<?php
declare(strict_types=1);

/** The dispatches — the reads of slice 8 (mcp_agent_dispatches: the admin sees all, a curator those of the bases they curate, a person those they asked for). */

const DISPATCH_PAGE = 50;

/**
 * A page of dispatches, newest first. $f: status (sent | answered | awaiting_approval | refused | failed), kind, base (an id). ['rows', 'total', 'page', 'pages'].
 * Each row carries its base's name when the caller may read the base.
 */
function find_dispatches(PDO $pdo, array $f, int $page): array
{
    $where = [];
    $args = [];
    if (in_array($f['status'] ?? '', ['sent', 'answered', 'awaiting_approval', 'refused', 'failed'], true)) {
        $where[] = 'd.status = :status';
        $args['status'] = $f['status'];
    }
    if (in_array($f['kind'] ?? '', ['ask', 'propose_areas', 'draft_guidelines', 'report'], true)) {
        $where[] = 'd.kind = :kind';
        $args['kind'] = $f['kind'];
    }
    if (!empty($f['base'])) {
        $where[] = 'd.base_id = :base';
        $args['base'] = (int) $f['base'];
    }
    $w = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $total = (int) one_value($pdo, 'SELECT count(*) FROM mcp_agent_dispatches d' . $w, $args);
    $pages = max(1, (int) ceil($total / DISPATCH_PAGE));
    $page = max(1, min($page, $pages));
    $st = $pdo->prepare('SELECT d.*, b.name AS base_name, m.display_name AS acting_name FROM mcp_agent_dispatches d
                           LEFT JOIN mcp_bases b ON b.base_id = d.base_id LEFT JOIN mcp_members m ON m.member_id = d.acting_member_id' . $w
        . ' ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT ' . DISPATCH_PAGE . ' OFFSET ' . (($page - 1) * DISPATCH_PAGE));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function find_dispatch(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT d.*, b.name AS base_name, m.display_name AS acting_name FROM mcp_agent_dispatches d
                           LEFT JOIN mcp_bases b ON b.base_id = d.base_id LEFT JOIN mcp_members m ON m.member_id = d.acting_member_id WHERE d.dispatch_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
