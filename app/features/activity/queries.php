<?php
declare(strict_types=1);

/** The activity trail the caller may see (mcp_activity_log, db/014: their own rows; rows about bases they may see; everything for the admin). */
const ACTIVITY_PAGE = 50;

/**
 * Rows newest first, one page; `more` says whether another page exists. $filters: own (bool), action (prefix), since (days),
 * base (int, the base's history), entity_type + entity_id (one record's history). mcp_activity_log decides what is seen.
 */
function find_my_activity(PDO $pdo, int $memberId, int $page, array $filters = []): array
{
    $where = [];
    $args = [];
    $keyed = false;
    if (($filters['base'] ?? null) !== null) {
        $where[] = 'base_id = :base';
        $args['base'] = (int) $filters['base'];
        $keyed = true;
    }
    if (($filters['entity_type'] ?? null) !== null) {
        $where[] = 'entity_type = :etype AND entity_id = :eid';
        $args['etype'] = (string) $filters['entity_type'];
        $args['eid'] = (int) $filters['entity_id'];
        $keyed = true;
    }
    if (!$keyed && ($filters['own'] ?? true)) {
        $where[] = 'actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim($filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    $sql = 'SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, entity_type, entity_id, after, base_id, token_id, agent_run_id
              FROM mcp_activity_log' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** A record's trail (the view decides who sees it): by the base for a base, else by entity. */
function find_record_activity(PDO $pdo, string $type, int $id, int $limit = 30): array
{
    $f = $type === 'base' ? ['base' => $id] : ['entity_type' => $type, 'entity_id' => $id];
    return array_slice(find_my_activity($pdo, 0, 1, $f)['rows'], 0, $limit);
}

/** A row's JSON: the actor, the sentence, the keys. */
function present_activity_row(array $r): array
{
    return ['activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']),
            'actor' => ['member_id' => $r['actor_member_id'] === null ? null : (int) $r['actor_member_id'], 'display_name' => $r['actor_name'], 'is_agent' => (bool) $r['actor_is_agent']],
            'source' => $r['source'], 'action' => $r['action'], 'sentence' => activity_sentence($r), 'entity_type' => $r['entity_type'],
            'entity_id' => $r['entity_id'] === null ? null : (int) $r['entity_id'],
            'base_id' => $r['base_id'] === null ? null : (int) $r['base_id'], 'agent_run_id' => $r['agent_run_id'] === null ? null : (int) $r['agent_run_id']];
}

/** The record a row concerns, as a link: [url, label] or null. Phase 3's slices add their screens. */
function activity_record_link(array $r): ?array
{
    if (($r['base_id'] ?? null) !== null) { return ['/bases/' . (int) $r['base_id'], 'knowledge base']; }
    return null;
}

/** One line for a row, in words: "Priya created a knowledge base: Fire code". Falls back to the event name. Later slices add their events. */
function activity_sentence(array $r): string
{
    $who = $r['actor_name'] ?? 'Knowledge';
    if (!empty($r['actor_is_agent'])) { $who .= ' (agent)'; }
    $after = is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
    $title = isset($after['title']) ? ': ' . mb_substr((string) $after['title'], 0, 80) : (isset($after['name']) ? ': ' . mb_substr((string) $after['name'], 0, 80) : '');
    $words = [
        'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'member.refused' => 'was refused', 'member.sign_on.refused' => 'was refused at sign-on', 'directory.sync' => 'refreshed the directory',
        'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token', 'prefs.save' => 'changed how they are told', 'notification.read' => 'read their notifications',
        'base.create' => 'created a knowledge base', 'base.update' => 'changed a knowledge base', 'base.archive' => 'archived a knowledge base', 'base.share' => 'shared a knowledge base',
        'base.admin_view' => 'opened a private base as admin',
        'source.add' => 'added a source', 'source.replace' => 'replaced a source', 'source.remove' => 'removed a source', 'source.view' => 'opened a source',
        'guideline.save' => 'saved a guideline', 'area.save' => 'saved a focus area', 'build.start' => 'started a build', 'build.finish' => 'finished a build', 'build.cancel' => 'cancelled a build',
        'question.ask' => 'asked a question', 'answer.rate' => 'rated an answer', 'answer.pin' => 'pinned an answer',
        'key.mint' => 'minted a key', 'key.rotate' => 'rotated a key', 'key.revoke' => 'revoked a key', 'feed.follow' => 'followed a feed',
        'attachment.add' => 'attached a file', 'attachment.refused' => 'tried to attach a file that was refused', 'attachment.delete' => 'removed an attachment',
        'worker.pass' => 'the worker ran', 'cron.run' => 'the worker ran', 'notification.send' => 'sent a notice',
    ];
    if ($r['action'] === 'screen.view') {
        return $who . ' opened a screen';
    }
    return $who . ' ' . ($words[$r['action']] ?? str_replace(['.', '_'], [' ', ' '], (string) $r['action'])) . $title;
}
