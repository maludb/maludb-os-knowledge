<?php
declare(strict_types=1);

/**
 * Fetching ONE page for an address source (docs/build-specs/sources-sections.md "Fetching an address"; sectioning.md "the worker owes").
 *
 *   kn_fetch_url($url, $opts) → ['ok', 'code', 'status', 'content_type', 'body', 'final_url', 'etag', 'bytes', 'redirects']
 *
 * The rules: KN_URL_FETCH=off refuses (fetch_disabled); kn_url_is_public() judges the URL and EVERY redirect target; the host is resolved
 * once per hop by the same resolver and the connection pinned to an address it approved (CURLOPT_RESOLVE — no second lookup); redirects
 * are followed here, at most 5 (fetch_redirects); http and https only; no proxy, no cookies; the DECODED bytes counted against the limit
 * by the write callback, which aborts past it (fetch_too_large); 20 s in all across the redirects (fetch_timeout); a 2xx only
 * (fetch_status, with the status); the admitted types below (fetch_unsupported); DNS, connect and TLS failures fetch_failed.
 * The proofs' seam — $opts['resolve'] (host → addresses) and $opts['connect_to'] (CURLOPT_CONNECT_TO) — is honoured ONLY when
 * APP_ENV=dev; kn_fetch_test_seam() builds both from KN_URL_FETCH_TEST_MAP ("host=public-ip@127.0.0.1:port;…"). In production both are
 * ignored and the system resolver decides.
 */
require_once __DIR__ . '/extract/extract.php';

const KN_FETCH_TYPES = ['text/html', 'application/xhtml+xml', 'application/pdf', 'text/plain', 'text/markdown', 'text/csv', 'application/json',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
/** The extension a fetched type is stored and read under. */
const KN_FETCH_EXT = ['text/html' => 'html', 'application/xhtml+xml' => 'html', 'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/markdown' => 'md',
    'text/csv' => 'csv', 'application/json' => 'json', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx'];
const KN_FETCH_MAX_REDIRECTS = 5;

function kn_fetch_result(string $code, array $more = []): array
{
    return $more + ['ok' => $code === 'ok', 'code' => $code, 'status' => 0, 'content_type' => null, 'body' => '', 'final_url' => null, 'etag' => null, 'bytes' => 0, 'redirects' => 0];
}

function kn_fetch_enabled(): bool
{
    return strtolower(trim((string) (getenv('KN_URL_FETCH') ?: ''))) !== 'off';
}

/** The proofs' seam from KN_URL_FETCH_TEST_MAP, only under APP_ENV=dev: ['resolve' => ?callable, 'connect_to' => [..]]. */
function kn_fetch_test_seam(): array
{
    $map = trim((string) (getenv('KN_URL_FETCH_TEST_MAP') ?: ''));
    if ((getenv('APP_ENV') ?: 'dev') !== 'dev' || $map === '') {
        return ['resolve' => null, 'connect_to' => []];
    }
    $hosts = [];
    $connect = [];
    foreach (explode(';', $map) as $entry) {
        if (!preg_match('/^\s*([a-z0-9.-]+)=([0-9a-f.:]+)@([0-9.]+):(\d+)\s*$/i', $entry, $m)) {
            continue;
        }
        $host = strtolower($m[1]);
        $hosts[$host] = [$m[2]];
        foreach ([80, 443, 8080, 8443] as $port) {
            $connect[] = $host . ':' . $port . ':' . $m[3] . ':' . $m[4];
        }
    }
    $resolve = static fn (string $host): array => $hosts[strtolower($host)] ?? kn_url_resolve($host);
    return ['resolve' => $resolve, 'connect_to' => $connect];
}

/** A Location against the URL it came from: absolute, scheme-relative, absolute-path or relative. */
function kn_fetch_resolve_location(string $base, string $loc): string
{
    $loc = trim($loc);
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $loc)) {
        return $loc;
    }
    $p = parse_url($base);
    $scheme = $p['scheme'] ?? 'http';
    $auth = ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($loc, '//')) {
        return $scheme . ':' . $loc;
    }
    if (str_starts_with($loc, '/')) {
        return $scheme . '://' . $auth . $loc;
    }
    $path = $p['path'] ?? '/';
    $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
    if (str_starts_with($loc, '?')) {
        return $scheme . '://' . $auth . $path . $loc;
    }
    $parts = [];
    foreach (explode('/', $dir . $loc) as $seg) {
        if ($seg === '..') { array_pop($parts); } elseif ($seg !== '.') { $parts[] = $seg; }
    }
    return $scheme . '://' . $auth . '/' . ltrim(implode('/', $parts), '/');
}

function kn_fetch_url(string $url, array $opts = []): array
{
    if (!kn_fetch_enabled()) {
        return kn_fetch_result('fetch_disabled');
    }
    $dev = (getenv('APP_ENV') ?: 'dev') === 'dev';
    $resolve = $dev ? ($opts['resolve'] ?? null) : null;
    $connectTo = $dev ? ($opts['connect_to'] ?? []) : [];
    $max = (int) ($opts['max_bytes'] ?? 10485760);
    $deadline = microtime(true) + (float) ($opts['timeout'] ?? 20);
    $agent = 'Knowledge/' . (string) ($opts['version'] ?? '1.0') . ' (+' . rtrim((string) (getenv('APP_URL') ?: 'http://localhost'), '/') . ')';
    $current = $url;
    for ($redirects = 0; ; $redirects++) {
        $addrs = null;
        $resolver = static function (string $host) use ($resolve, &$addrs): array {
            $a = $resolve !== null ? $resolve($host) : kn_url_resolve($host);
            $addrs = is_array($a) ? $a : [];
            return $addrs;
        };
        if (!kn_url_is_public($current, $resolver)) {
            return kn_fetch_result($addrs === [] ? 'fetch_failed' : 'fetch_refused', ['final_url' => $current, 'redirects' => $redirects]);
        }
        $p = parse_url($current);
        $scheme = strtolower((string) $p['scheme']);
        $host = strtolower(trim((string) $p['host'], '[]'));
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        $pin = [];
        if ($addrs !== null && $addrs !== []) {
            $ip = (string) $addrs[0];
            $pin[] = $host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
        }
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            return kn_fetch_result('fetch_timeout', ['final_url' => $current, 'redirects' => $redirects]);
        }
        $body = '';
        $tooLarge = false;
        $headers = [];
        $ch = curl_init($current);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_COOKIEFILE => '', CURLOPT_COOKIESESSION => true, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $agent, CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/pdf,text/plain;q=0.9,*/*;q=0.5'],
            CURLOPT_TIMEOUT_MS => max(1, (int) ($remaining * 1000)), CURLOPT_CONNECTTIMEOUT_MS => (int) min(10000, max(1, $remaining * 1000)),
            CURLOPT_RESOLVE => $pin, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
                if (preg_match('/^HTTP\//', $line)) { $headers = []; }
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$body, &$tooLarge, $max): int {
                if (strlen($body) + strlen($chunk) > $max) {
                    $tooLarge = true;
                    return 0;                                   // abort the transfer
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($connectTo !== []) {
            curl_setopt($ch, CURLOPT_CONNECT_TO, $connectTo);
        }
        $okExec = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($tooLarge) {
            return kn_fetch_result('fetch_too_large', ['final_url' => $current, 'redirects' => $redirects, 'status' => $status]);
        }
        if ($okExec === false || $errno !== 0) {
            return kn_fetch_result($errno === CURLE_OPERATION_TIMEDOUT ? 'fetch_timeout' : 'fetch_failed', ['final_url' => $current, 'redirects' => $redirects]);
        }
        if ($status >= 300 && $status < 400 && isset($headers['location'])) {
            if ($redirects + 1 > KN_FETCH_MAX_REDIRECTS) {
                return kn_fetch_result('fetch_redirects', ['final_url' => $current, 'redirects' => $redirects + 1, 'status' => $status]);
            }
            $current = kn_fetch_resolve_location($current, $headers['location']);
            continue;
        }
        if ($status < 200 || $status > 299) {
            return kn_fetch_result('fetch_status', ['final_url' => $current, 'redirects' => $redirects, 'status' => $status]);
        }
        $type = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''))[0]));
        if (!in_array($type, KN_FETCH_TYPES, true)) {
            return kn_fetch_result('fetch_unsupported', ['final_url' => $current, 'redirects' => $redirects, 'status' => $status, 'content_type' => $type]);
        }
        return kn_fetch_result('ok', ['status' => $status, 'content_type' => $type, 'body' => $body, 'final_url' => $current,
            'etag' => $headers['etag'] ?? null, 'bytes' => strlen($body), 'redirects' => $redirects]);
    }
}
