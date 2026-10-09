<?php
declare(strict_types=1);

/**
 * The prelude of every base-scoped screen and handler, of this slice and every later one (docs/build-specs/bases-core.md):
 *   base_from_request()   the base a request names, through mcp_bases — 404 when unseen; the admin's look at a private base is logged
 *   require_base_right()  ONE rule for every base-scoped write: none → 404, read → 403, own → allowed, curate → allowed with the right
 *   require_base_own()    the owner (or an admin): deleting a base
 *   base_log()            a row of the trail about a base, base_id on every one
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/**
 * The base the request names (`base`, `base_id` or `id`), through the view: 404 "Knowledge base not found." when the caller may not
 * read it. When the caller is the admin and the base is private and they reach it only as the admin, `base.admin_view` is logged once
 * per request ($screen: the screen id, or the route for a handler).
 */
function base_from_request(PDO $pdo, ?string $screen = null): array
{
    $id = request_integer('base') ?? request_integer('base_id') ?? request_integer('id');
    if ($id === null) {
        refuse(422, 'Say which knowledge base.');
    }
    $b = find_base($pdo, $id) ?? refuse(404, 'Knowledge base not found.');
    base_admin_view_log($pdo, $b, $screen ?? request_route());
    return $b;
}

/** The admin's look at a private base they do not belong to — logged once per request; one's own, a shared or an internal base never. */
function base_admin_view_log(PDO $pdo, array $b, string $screen): void
{
    static $logged = false;
    if ($logged || $b['visibility'] !== 'private' || !is_kn_admin()) {
        return;
    }
    $me = (int) current_member_id();
    if (base_reach_without_admin($pdo, (int) $b['base_id'], $me)) {
        return;
    }
    $logged = true;
    base_log($pdo, 'base.admin_view', (int) $b['base_id'], ['after' => ['base_id' => (int) $b['base_id'], 'screen' => $screen]]);
}

/** The caller's level on a base: own | curate | read | none (kn_base_level, db/006). */
function base_level(int $baseId): string
{
    static $cache = [];
    return $cache[$baseId] ??= (string) one_value(db(), 'SELECT kn_base_level(:b)', ['b' => $baseId]);
}

/**
 * One rule for every base-scoped write: a base one may not read does not exist (404); a reader may not change it (403); the owner
 * or an admin may (own); a curate-level share acts with the right its roles give ($right — refused in that right's words).
 */
function require_base_right(int $baseId, string $right): void
{
    require_login();
    $level = base_level($baseId);
    if ($level === 'none') {
        refuse(404, 'Knowledge base not found.');
    }
    if ($level === 'read') {
        refuse(403, 'You may not change this knowledge base.');
    }
    if ($level === 'curate') {
        require_right($right);
    }
}

/** The owner, or an admin (own level): deleting a base, sharing at own. */
function require_base_own(int $baseId, string $what = 'do that'): void
{
    require_login();
    $level = base_level($baseId);
    if ($level === 'none') {
        refuse(404, 'Knowledge base not found.');
    }
    if ($level !== 'own') {
        refuse(403, 'Only the owner of this knowledge base may ' . $what . '.');
    }
}

/** An archived base changes only by being restored or deleted: refused in words. */
function require_base_live(array $b): void
{
    if ($b['archived_at'] !== null) {
        refuse(422, 'Base "' . $b['name'] . '" is archived: restore it first.');
    }
}

/**
 * A screen of a base was opened: screen.view with base_id (and after.base_id, plus $after — a source's or a section's id); JSON readers
 * only when they ask (log_screen_view's rule).
 */
function base_screen_view(PDO $pdo, string $screen, int $baseId, array $after = []): void
{
    if (wants_json() && ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') !== '1') {
        return;
    }
    log_activity($pdo, 'screen.view', null, null, ['screen' => $screen, 'base_id' => $baseId, 'after' => ['base_id' => $baseId] + $after]);
}

/** A row of the trail about a base: base_id on every one (design §6.4). */
function base_log(PDO $pdo, string $action, int $baseId, array $opts = [], ?string $entityType = 'base', int|string|null $entityId = null): void
{
    log_activity($pdo, $action, $entityType, $entityId ?? ($entityType === 'base' ? $baseId : null), ['base_id' => $baseId] + $opts);
}

/** The database's refusals of a share row, named on the field (db/006's trigger and CHECKs). */
function share_field_refusals(): array
{
    return [
        '/No such member/'              => ['member', 'That person is not here.'],
        '/member principal is a person/' => ['member', null],
        '/agent principal is an agent/' => ['member', null],
        '/No such department/'          => ['department', 'That department is not here.'],
        '/everyone principal/'          => ['base_member', null],
        'base_members_level_check'      => ['level', 'The level is read, curate or own.'],
        'base_members_check1'           => ['level', 'An application reads; it never curates.'],
        'base_members_check'            => ['application', 'A catalog key is lower-case letters, digits and underscores, up to 60.'],
    ];
}

/**
 * The end of every share handler: the row written or its level changed (share_base), logged as $event with the row's facts, the
 * person told by the db/012 trigger, landed back on the share screen. record_id = the share row.
 */
function base_share_finish(PDO $pdo, array $b, string $kind, ?int $principalId, ?string $ref, string $level, string $event, string $who): never
{
    $id = (int) $b['base_id'];
    $me = (int) current_member_id();
    $r = kn_guard_fields($pdo, static function () use ($pdo, $id, $kind, $principalId, $ref, $level, $event, $me): array {
        $pdo->beginTransaction();
        $r = share_base($pdo, $id, $kind, $principalId, $ref, $level, $me);
        base_log($pdo, $event, $id, ['after' => ['base_member_id' => $r['base_member_id'], 'principal_kind' => $kind,
            'principal_id' => $principalId, 'principal_ref' => $ref, 'level' => $level, 'changed' => $r['changed']]
            + ($r['changed'] ? ['before_level' => $r['before_level']] : [])], 'base_member', $r['base_member_id']);
        $pdo->commit();
        return $r;
    }, share_field_refusals());
    $did = $r['changed'] ? $who . ' now ' . ($level === 'read' ? 'reads' : ($level === 'curate' ? 'curates' : 'owns')) . ' ' . $b['name']
        : 'Shared ' . $b['name'] . ' with ' . $who . ' (' . $level . ')';
    kn_done($did, $r['base_member_id'], kn_land(return_path('/bases/' . $id . '/share'), $r['changed'] ? 'changed' : 'shared'), 'baseChanged', ['base_id' => $id]);
}

/** A level the caller may give on this base: read · curate, and own only from the owner or an admin. Refused in words. */
function share_level_from_request(int $baseId, array $allowed = ['read', 'curate', 'own']): string
{
    $level = req_has('level') && (string) req_val('level') !== '' ? (string) req_val('level') : 'read';
    if (!in_array($level, $allowed, true)) {
        kn_refuse_fields(['level' => 'The level is ' . implode(' or ', $allowed) . '.']);
    }
    if ($level === 'own' && base_level($baseId) !== 'own') {
        kn_refuse_fields(['level' => 'Only the owner of this knowledge base may give own.']);
    }
    return $level;
}

/** The database's refusals of a base's own fields, named on the field (kn_guard_fields). */
function base_field_refusals(string $key = ''): array
{
    return [
        'knowledge_bases_key_live'  => ['key', 'Another base already uses the key ' . ($key !== '' ? $key : 'you gave') . '.'],
        'knowledge_bases_key_check' => ['key', 'A key is lower-case letters, digits, dashes and underscores, starting with a letter or digit, up to 60.'],
        'knowledge_bases_name_check' => ['name', 'Give the base a name of up to 200 characters.'],
        'knowledge_bases_language_check' => ['language', 'The language is an ISO code such as en, fr or pt-BR.'],
        'knowledge_bases_sensitivity_check' => ['sensitivity', 'The sensitivity is standard, confidential or restricted.'],
        'knowledge_bases_monthly_text_mb_limit_check' => ['monthly_text_mb_limit', 'The monthly limit is a whole number of megabytes from 1 to 100000.'],
        'knowledge_bases_visibility_check' => ['visibility', 'The visibility is private or internal.'],
        '/switch template/' => ['template', null],
        '/No such template/' => ['template', 'Choose one of the templates.'],
    ];
}
