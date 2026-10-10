<?php
declare(strict_types=1);

/**
 * The worker's step `url_checks` (slice 8, agents-feeds.md "URL change checks"; design §2): once a week an address source is fetched again — with the same public-address,
 * size, time and type rules as an add (kn_fetch_url) — to learn whether its page changed or vanished. NOTHING is replaced automatically: the check dates the change
 * (url_changed_at) and logs `source.url_changed`; the Archivist proposes (url_changed) and a curator replaces from the source's page. The bytes fetched are discarded.
 * Source cron; never the URL's text.
 */
require_once APP_ROOT . '/app/knowledge/fetch.php';

const URL_CHECK_DAYS = 7;
const URL_VANISHED_AFTER_FAILURES = 2;

/** The text a fetched page reads as (what the worker would store), or null when it cannot be read. */
function url_check_text(array $f): ?string
{
    if ($f['content_type'] === 'text/html' || $f['content_type'] === 'application/xhtml+xml') {
        $ex = kn_extract_url_text($f['body'], (string) $f['final_url']);
    } else {
        $ext = KN_FETCH_EXT[$f['content_type']] ?? 'txt';
        $tmp = tempnam(sys_get_temp_dir(), 'knchk');
        file_put_contents($tmp, $f['body']);
        $ex = kn_extract_text($tmp, 'page.' . $ext, (string) $f['content_type']);
        @unlink($tmp);
    }
    return !empty($ex['ok']) && isset($ex['text']) ? (string) $ex['text'] : null;
}

/**
 * Check up to $limit address sources not checked for a week (never-checked first, then the oldest). Skipped entirely when fetching is off (the setting or KN_URL_FETCH).
 * ['checked', 'unchanged', 'changed', 'vanished', 'deferred', 'off'].
 */
function url_checks_pass(PDO $pdo, DateTimeImmutable $now, int $limit = 10, float $deadline = INF): array
{
    $out = ['checked' => 0, 'unchanged' => 0, 'changed' => 0, 'vanished' => 0, 'deferred' => 0, 'off' => false];
    $settings = $pdo->query('SELECT url_fetch_allowed, url_fetch_max_bytes FROM kn_settings WHERE id = 1')->fetch();
    if (!(bool) $settings['url_fetch_allowed'] || !kn_fetch_enabled()) {
        $out['off'] = true;
        return $out;
    }
    $ts = $now->format('Y-m-d\TH:i:s.uP');
    $st = $pdo->prepare("SELECT s.* FROM sources s JOIN knowledge_bases b ON b.id = s.base_id AND b.archived_at IS NULL
                          WHERE s.kind = 'url' AND s.status = 'ready' AND (s.url_checked_at IS NULL OR s.url_checked_at < CAST(:now AS timestamptz) - make_interval(days => " . URL_CHECK_DAYS . "))
                          ORDER BY s.url_checked_at NULLS FIRST, s.id LIMIT " . max(1, $limit));
    $st->execute(['now' => $ts]);
    $seam = kn_fetch_test_seam();
    $cron = ['actor_member_id' => null, 'source' => 'cron'];
    foreach ($st->fetchAll() as $s) {
        if (microtime(true) > $deadline) {
            break;
        }
        $out['checked']++;
        $id = (int) $s['id'];
        $bid = (int) $s['base_id'];
        $f = kn_fetch_url((string) $s['origin'], ['resolve' => $seam['resolve'], 'connect_to' => $seam['connect_to'], 'max_bytes' => (int) $settings['url_fetch_max_bytes']]);
        $vanished = false;
        $changed = false;
        $failures = 0;
        $etag = $s['url_etag'];
        if ($f['ok']) {
            $same = $f['etag'] !== null && $f['etag'] === $s['url_etag'];
            if (!$same) {
                $text = url_check_text($f);
                $same = $text !== null && $s['text_sha256'] !== null && hash('sha256', $text) === $s['text_sha256'];
                $changed = !$same;
                if ($same && $f['etag'] !== null) {
                    $etag = $f['etag'];                                  // the same words behind a new tag: remember the tag
                }
            }
        } elseif ($f['code'] === 'fetch_status' && in_array($f['status'], [404, 410], true)) {
            $vanished = true;
        } elseif ($f['code'] === 'fetch_failed') {
            $failures = (int) $s['url_failed_checks'] + 1;
            $vanished = $failures >= URL_VANISHED_AFTER_FAILURES;
        } else {                                                          // a timeout, a 5xx, a refusal: only the check is dated — tried again next week
            $out['deferred']++;
            $failures = (int) $s['url_failed_checks'];
        }
        $pdo->prepare('UPDATE sources SET url_checked_at = CAST(:now AS timestamptz), url_etag = :e, url_failed_checks = :n'
            . ($changed || $vanished ? ', url_changed_at = CAST(:now AS timestamptz)' : '') . ' WHERE id = :id')
            ->execute(['now' => $ts, 'e' => $etag, 'n' => $vanished || $changed || $f['ok'] ? 0 : min($failures, 32000), 'id' => $id]);
        if ($changed || $vanished) {
            log_activity($pdo, 'source.url_changed', 'source', $id, $cron + ['base_id' => $bid, 'after' => ['source_id' => $id, 'vanished' => $vanished, 'status' => $f['ok'] ? null : ($f['status'] ?: $f['code'])]]);
            $out[$vanished ? 'vanished' : 'changed']++;
        } elseif ($f['ok']) {
            $out['unchanged']++;
        }
    }
    return $out;
}
