<?php
declare(strict_types=1);

/**
 * Whether a URL may be fetched: http or https, no userinfo, a standard port (80, 443, 8080, 8443), a real host name
 * or a canonical IP literal, and EVERY address it resolves to public — never private, loopback, link-local, unique
 * local, carrier-grade NAT, documentation, multicast, reserved, cloud metadata, or an IPv4 address dressed as IPv6.
 * The resolver is a parameter (host → list of address strings) so the check is pure and testable; the worker that
 * fetches must pin the connection to the addresses this approved (no second lookup) and re-check every redirect.
 */

const KN_URL_PORTS = [80, 443, 8080, 8443];

function kn_url_is_public(string $url, ?callable $resolve = null): bool
{
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
        return false;
    }
    if (!preg_match('#^(https?)://([^/?\#]*)#i', $url, $m)) {
        return false;
    }
    $authority = $m[2];
    if ($authority === '' || str_contains($authority, '@')) {
        return false;
    }
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    if (isset($parts['port']) && !in_array((int) $parts['port'], KN_URL_PORTS, true)) {
        return false;
    }
    if (preg_match('/:(\d*)$/', $authority, $pm) && !str_ends_with($authority, ']') && ($pm[1] === '' || !in_array((int) $pm[1], KN_URL_PORTS, true))) {
        return false;
    }
    $host = strtolower($parts['host']);
    if (str_starts_with($host, '[')) {
        $lit = substr($host, 1, -1);
        return filter_var($lit, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && kn_ip_is_public($lit);
    }
    $host = rtrim($host, '.');
    if ($host === '' || str_contains($host, '%')) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return kn_ip_is_public($host);
    }
    // numeric hosts in any other spelling (2130706433, 0x7f.1, 0177.0.0.1, 127.1) are refused, never resolved
    if (preg_match('/^[0-9.]+$/', $host) || preg_match('/^(0x[0-9a-f]*\.?)+[0-9a-fx.]*$/i', $host) || preg_match('/(^|\.)0x[0-9a-f]+(\.|$)/i', $host)) {
        return false;
    }
    if (!preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)) {
        return false;
    }
    if ($host === 'localhost' || preg_match('/\.(localhost|local|internal|localdomain|home\.arpa|lan|intranet|corp)$/', $host) || !str_contains($host, '.')
        || in_array($host, ['metadata.google.internal', 'metadata', 'instance-data'], true)) {
        return false;
    }
    $resolve ??= 'kn_url_resolve';
    $addrs = $resolve($host);
    if (!is_array($addrs) || $addrs === []) {
        return false;
    }
    foreach ($addrs as $a) {
        if (!is_string($a) || filter_var($a, FILTER_VALIDATE_IP) === false || !kn_ip_is_public($a)) {
            return false;
        }
    }
    return true;
}

/** The system resolver: every A and AAAA address of the host. */
function kn_url_resolve(string $host): array
{
    $out = [];
    foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
        if (isset($r['ip'])) {
            $out[] = $r['ip'];
        } elseif (isset($r['ipv6'])) {
            $out[] = $r['ipv6'];
        }
    }
    return array_values(array_unique($out));
}

/** Whether one IP address (v4 or v6, as text) is publicly routable and not a special-purpose range. */
function kn_ip_is_public(string $ip): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    if (strlen($bin) === 4) {
        foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
            '192.0.2.0/24', '192.31.196.0/24', '192.52.193.0/24', '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24',
            '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
            if (kn_ip_in_cidr($bin, $cidr)) {
                return false;
            }
        }
        return true;
    }
    foreach (['::/96', '::ffff:0:0/96', '::ffff:0:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32',
        '2002::/16', '3fff::/20', '5f00::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8'] as $cidr) {
        if (kn_ip_in_cidr($bin, $cidr)) {
            return false;
        }
    }
    return true;
}

function kn_ip_in_cidr(string $bin, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr);
    $nb = inet_pton($net);
    if ($nb === false || strlen($nb) !== strlen($bin)) {
        return false;
    }
    $bits = (int) $bits;
    $full = intdiv($bits, 8);
    if (strncmp($bin, $nb, $full) !== 0) {
        return false;
    }
    $rem = $bits % 8;
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($bin[$full]) & $mask) === (ord($nb[$full]) & $mask);
}
