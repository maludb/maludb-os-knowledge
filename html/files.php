<?php
declare(strict_types=1);
/**
 * GET /files/{id} — the gated attachment door: a session (an anonymous request is 401, never a redirect), and the record's base as the
 * view mcp_attachments decides it (a base the caller may not read is a 404, the same sentence as a missing file). Streams the file from
 * storage/ — never by path. Only a PDF is shown inline; everything else, HTML included, is a download, with nosniff and a sandboxing CSP.
 * Not an action of the manifest; no log (a page view logs the page).
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/files/queries.php';
if (!is_logged_in()) {
    http_response_code(401);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Sign in required.');
}
$id = request_integer('id');
$row = $id === null ? null : attachment_path(db(), $id);
$root = realpath(APP_ROOT . '/storage') ?: (APP_ROOT . '/storage');
$full = $row === null ? false : realpath($row['path']);
if ($row === null || $full === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}
$inline = $row['mime'] === 'application/pdf';
header('Content-Type: ' . $row['mime']);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $row['filename']) . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
header('Cache-Control: private, max-age=300');
readfile($full);
