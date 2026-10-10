<?php
declare(strict_types=1);

/**
 * The graph explorer's file cache (docs/build-specs/graph-explorer.md "Confinement and caching"): storage/cache/graph/<base id>/<sha1(key)>.json,
 * directory 0750, files 0640, written atomically by rename. The key always carries the base's last_built_at and guideline_version, so a build
 * moves every key; TTL 60 s for an engine read and 600 s for duplicate_subjects (the tool surface's figures, so a screen and a tool agree).
 * An engine failure is never cached; a write first removes that base's files older than a day. The cache holds only what is ALREADY
 * confined to the base and is read only after the base's gate, so every Reader of a base sees the same and no one else sees it at all.
 */
const GRAPH_TTL_READ = 60;
const GRAPH_TTL_DUPLICATES = 600;
const GRAPH_CACHE_KEEP_SECONDS = 86400;
const GRAPH_CACHE_MAX_FILES = 400;

/** Does this request bypass the cache (a curator's ?fresh=1)? Set by the screen after its gate; read by graph_cache(). */
function graph_fresh(?bool $set = null): bool
{
    static $fresh = false;
    if ($set !== null) {
        $fresh = $set;
    }
    return $fresh;
}

function graph_cache_dir(int $baseId): string
{
    return APP_ROOT . '/storage/cache/graph/' . $baseId;
}

/** What is never cached: a failure (ok false, a code, an error) and an answer marked `nocache` (a name that is not the base's — arbitrary words must not make files). */
function graph_result_failed(array $r): bool
{
    return (array_key_exists('ok', $r) && $r['ok'] === false) || !empty($r['error']) || !empty($r['code']) || !empty($r['errors']) || !empty($r['nocache']);
}

/**
 * The cached answer of $fn for ($baseId, $key), or $fn's own (cached when it is not a failure). $fn returns an array.
 * @param callable():array $fn
 */
function graph_cache(int $baseId, string $key, int $ttl, callable $fn): array
{
    $st = db()->prepare('SELECT last_built_at, guideline_version FROM knowledge_bases WHERE id = :b');
    $st->execute(['b' => $baseId]);
    $row = $st->fetch() ?: ['last_built_at' => null, 'guideline_version' => 0];
    $dir = graph_cache_dir($baseId);
    $file = $dir . '/' . sha1($key . '|' . (string) $row['last_built_at'] . '|' . (int) $row['guideline_version']) . '.json';
    if (!graph_fresh() && is_file($file) && (time() - (int) filemtime($file)) < $ttl) {
        $hit = json_decode((string) @file_get_contents($file), true);
        if (is_array($hit)) {
            return $hit;
        }
    }
    $result = $fn();
    if (!graph_result_failed($result)) {
        graph_cache_write($dir, $file, $result);
    }
    return $result;
}

/** Write one file (after removing the base's files older than a day), atomically; a cache that cannot be written is simply not kept. */
function graph_cache_write(string $dir, string $file, array $result): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return;
    }
    $old = time() - GRAPH_CACHE_KEEP_SECONDS;
    $live = [];
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        if ((int) @filemtime($f) < $old) {
            @unlink($f);
        } else {
            $live[$f] = (int) @filemtime($f);
        }
    }
    if (count($live) >= GRAPH_CACHE_MAX_FILES) {                       // arbitrary search words must not grow a directory without end
        asort($live);
        foreach (array_slice(array_keys($live), 0, count($live) - GRAPH_CACHE_MAX_FILES + 1) as $f) {
            @unlink($f);
        }
    }
    $tmp = $dir . '/.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json !== false && @file_put_contents($tmp, $json) !== false) {
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}
