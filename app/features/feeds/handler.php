<?php
declare(strict_types=1);

/**
 * The prelude of the feed screens and handlers (slice 8): slice 1's base prelude and the feeds' code, the feed a request names (through mcp_feeds — the curators'),
 * the form's fields read (the tool from the declared reads only, the arguments of that tool, the area, the interval, enabled), the log with base_id.
 */
require_once dirname(__DIR__) . '/bases/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** The feed the request names (`feed` or `id`) and its base: [feed, base]. A reader of the base is told whose it is (403); unseen is 404. */
function feed_from_request(PDO $pdo, ?string $screen = null): array
{
    $id = request_integer('feed') ?? request_integer('id');
    if ($id === null) {
        refuse(422, 'Say which feed.');
    }
    $f = find_feed($pdo, $id);
    if ($f === null) {
        $base = one_value($pdo, 'SELECT base_id FROM feeds WHERE id = :id', ['id' => $id]);
        if ($base !== null && base_level((int) $base) === 'read') {
            refuse(403, 'Feeds are the curators\'.');
        }
        refuse(404, 'Feed not found.');
    }
    $b = find_base($pdo, (int) $f['base_id']) ?? refuse(404, 'Feed not found.');
    base_admin_view_log($pdo, $b, $screen ?? request_route());
    return [$f, $b];
}

function feed_log(PDO $pdo, string $action, int $baseId, int $feedId, array $after): void
{
    log_activity($pdo, $action, 'feed', $feedId, ['base_id' => $baseId, 'after' => $after]);
}

/** The database's refusals of a feed, named on the field. */
function feed_field_refusals(): array
{
    return [
        'feeds_one_per_tool' => ['provider', 'This base already follows that.'],
        'feeds_every_minutes_check' => ['every_minutes', 'Every 5 to 10,080 minutes.'],
        '/area of its base/' => ['focus_area', 'Choose an area of this base.'],
    ];
}

/**
 * A feed's fields from the request: [fields, errors]. $cur: the feed's row on an edit — a field left out keeps its value. The tool is `source_tool` ("spaces.pages_index") or
 * `provider` + `tool`, and one of the tools this application declares as a read (feed_tools()); the arguments are the tool's own fields (`space`, `category`) or `arguments`
 * (a JSON object) — a field named on the form wins over `arguments` (a partial update brings the old object along).
 */
function feed_fields_from_request(PDO $pdo, int $baseId, ?array $cur): array
{
    $errors = [];
    $f = [];
    $tools = feed_tools();
    $key = null;
    if (req_has('source_tool') && (string) req_val('source_tool') !== '') {
        $key = (string) req_val('source_tool');
    } elseif (req_has('provider') || req_has('tool')) {
        $key = (string) (req_val('provider') ?? $cur['provider'] ?? '') . '.' . (string) (req_val('tool') ?? $cur['tool'] ?? '');
    } elseif ($cur !== null) {
        $key = $cur['provider'] . '.' . $cur['tool'];
    }
    if ($key === null || !isset($tools[$key])) {
        $errors['tool'] = 'Knowledge does not read that tool.';
        $spec = null;
    } else {
        $spec = $tools[$key];
        $f['provider'] = $spec['provider'];
        $f['tool'] = $spec['tool'];
    }
    $args = $cur['arguments'] ?? [];
    $given = false;
    if (req_has('arguments')) {
        $raw = $_POST['arguments'];
        $d = is_array($raw) ? $raw : (trim((string) $raw) === '' ? [] : json_decode((string) $raw, true));
        if (!is_array($d) || ($d !== [] && array_is_list($d))) {
            $errors['arguments'] = 'The arguments are a JSON object.';
        } else {
            $args = $d;
            $given = true;
        }
    }
    if ($spec !== null) {
        $names = array_column($spec['arguments'], 'name');
        foreach ($spec['arguments'] as $a) {
            if (req_has($a['name'])) {
                $v = trim((string) req_val($a['name']));
                if ($v === '') {
                    unset($args[$a['name']]);
                } else {
                    $args[$a['name']] = $v;
                }
                $given = true;
            }
        }
        if (!$given && $cur !== null && ($cur['provider'] . '.' . $cur['tool']) !== $key) {
            $args = [];                                                  // another tool: the old tool's arguments do not carry over
        }
        foreach ($args as $k => $v) {
            if (!in_array($k, $names, true)) {
                $errors['arguments'] = 'That tool takes ' . ($names === [] ? 'no arguments' : implode(', ', $names)) . ', not ' . $k . '.';
            } elseif (!is_string($v) || mb_strlen($v) > 200 || preg_match('/[\x00-\x1f]/', $v)) {
                $errors[$k] = 'The ' . strtolower((string) ($spec['arguments'][array_search($k, $names, true)]['label'] ?? $k)) . ' is up to 200 characters, on one line.';
            }
        }
        foreach ($spec['arguments'] as $a) {
            if (!empty($a['required']) && (!isset($args[$a['name']]) || $args[$a['name']] === '')) {
                $errors[$a['name']] = 'Give the ' . strtolower($a['label']) . ' to follow.';
            }
        }
    }
    $f['arguments'] = $args;
    // the area: `focus_area` (or the column's own name `focus_area_id`); empty = the whole base
    $f['focus_area_id'] = $cur === null || $cur['focus_area_id'] === null ? null : (int) $cur['focus_area_id'];
    $areaField = req_has('focus_area') ? 'focus_area' : (req_has('focus_area_id') ? 'focus_area_id' : null);
    if ($areaField !== null) {
        $v = (string) req_val($areaField);
        if ($v === '' || $v === '0') {
            $f['focus_area_id'] = null;
        } elseif (ctype_digit($v)) {
            $f['focus_area_id'] = (int) $v;                              // another base's area: the trigger's words
        } else {
            $errors['focus_area'] = 'Choose an area of this base.';
        }
    }
    $f['every_minutes'] = (int) kn_int('every_minutes', $cur === null ? 60 : (int) $cur['every_minutes'], 5, 10080, 'Every', $errors);
    $f['enabled'] = kn_yes('enabled', $cur === null ? true : (bool) $cur['enabled']);
    return [$f, $errors];
}
