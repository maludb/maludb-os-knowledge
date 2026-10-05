<?php
declare(strict_types=1);

/**
 * What the shell reads on every render (sso-shell.md "Query functions"): the bases the person may read for the sidebar, the
 * bell's count (mcp_notifications) and the business name. Each is cached for the request — one query per render.
 */

/** The bases the caller may read, newest activity first (mcp_bases decides who sees which). [{base_id, name, status, my_level}] */
function sidebar_bases(PDO $pdo, int $limit = 12): array
{
    static $cache = null;
    if ($cache === null) {
        $st = $pdo->prepare('SELECT base_id, name, status, my_level, source_count, section_count FROM mcp_bases WHERE archived_at IS NULL ORDER BY updated_at DESC, name LIMIT :n');
        $st->bindValue('n', $limit, PDO::PARAM_INT);
        $st->execute();
        $cache = $st->fetchAll();
    }
    return $cache;
}

/** The bell: how many of my notifications are unread. */
function bell_count(PDO $pdo, int $memberId): int
{
    static $cache = null;
    return $cache ??= (int) one_value($pdo, 'SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL');
}

/** The business's name on the header (kn_settings.business_name via mcp_settings), else the application's. */
function business_name(PDO $pdo): string
{
    static $cache = null;
    return $cache ??= (string) (one_value($pdo, 'SELECT business_name FROM mcp_settings') ?: app_name());
}

/** The path of the current request, for the sidebar's highlight. */
function current_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
}
