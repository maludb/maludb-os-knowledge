<?php
declare(strict_types=1);
/** GET /api/v1/health — unauthenticated; the kernel's health check and the installer's monitor read it. */
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/knowledge/engine.php';
api_cors();
api_require_get();
$out = ['ok' => true, 'application' => app_key(), 'version' => (string) (json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['version'] ?? ''), 'database' => 'ok', 'maludb' => 'unconfigured', 'ingest_lag' => null, 'directory' => null, 'engine' => 'unavailable'];
try {
    $pdo = db();
    $lag = $pdo->query('SELECT (SELECT COALESCE(max(id), 0) FROM activity_log) - (SELECT last_id FROM activity_ingest_state WHERE id = 1)')->fetchColumn();
    $out['ingest_lag'] = (int) $lag;
    $sync = $pdo->query('SELECT last_run_at, last_error, next_cursor FROM directory_sync_state WHERE id = 1')->fetch();
    $out['directory'] = ['last_run_at' => json_ts($sync['last_run_at'] ?? null), 'synced' => ($sync['next_cursor'] ?? null) !== null, 'error' => $sync['last_error'] ?? null];
} catch (Throwable $e) {
    error_log('health: ' . $e->getMessage());
    $out['ok'] = false;
    $out['database'] = 'error';
}
if ((string) env('MALUDB_API_URL', '') !== '' && (string) env('MALUDB_API_TOKEN', '') !== '') {
    $out['maludb'] = 'ok';
}
$out['engine'] = kn_health_engine($out['ok'] ? $pdo : null);
api_json($out, $out['ok'] ? 200 : 503);

/**
 * The engine, in one word (slice 7): `ok` when the MaluDB API answers and the token is proven, `unavailable` when no URL or token is configured or it cannot be
 * reached, `answer_unavailable` when it is up but the last question of the past hour fell back for answer_unavailable (M1 is not deployed). Cached for 60 s under
 * storage/cache/ so a monitor's polling never loads the engine; never a URL, a token or an engine message.
 */
function kn_health_engine(?PDO $pdo): string
{
    $file = dirname(__DIR__, 3) . '/storage/cache/health-engine.json';
    $c = json_decode((string) @file_get_contents($file), true);
    if (is_array($c) && isset($c['at'], $c['engine']) && time() - (int) $c['at'] < 60) {
        return (string) $c['engine'];
    }
    $word = kn_engine_available()['ok'] ? 'ok' : 'unavailable';
    if ($word === 'ok' && $pdo !== null) {
        try {
            $last = $pdo->query("SELECT error FROM questions WHERE created_at > now() - interval '1 hour' ORDER BY id DESC LIMIT 1")->fetchColumn();
            if ($last === 'answer_unavailable') {
                $word = 'answer_unavailable';
            }
        } catch (Throwable $e) {
            error_log('health engine: ' . $e->getMessage());
        }
    }
    if (is_dir(dirname($file)) || @mkdir(dirname($file), 0770, true)) {
        @file_put_contents($file, json_encode(['at' => time(), 'engine' => $word]), LOCK_EX);
    }
    return $word;
}
