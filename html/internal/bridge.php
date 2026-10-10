<?php
declare(strict_types=1);
/**
 * POST /internal/bridge.php — the engine bridge (app/knowledge/bridge.php), on the INTERNAL port only (the public vhost answers /internal/
 * with a 404). Loopback, ±30 s and the X-KN-Bridge HMAC, or 401; the op's JSON verbatim, an engine failure included.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/features/bases/handler.php';
require_once APP_ROOT . '/app/knowledge/bridge.php';
header_remove('Set-Cookie');
register_shutdown_function(static function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
});
$GLOBALS['__json_sent'] = true;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = static function (int $http, array $body): never {
    http_response_code($http);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $out(405, ['ok' => false, 'error' => ['code' => 'method', 'message' => 'POST only.']]);
}
$body = (string) file_get_contents('php://input');
if (($deny = kn_bridge_verify($_SERVER, $body)) !== null) {
    $out((int) $deny[0], ['ok' => false, 'error' => ['code' => $deny[1], 'message' => $deny[2]]]);
}
$msg = json_decode($body, true);
if (!is_array($msg)) {
    $out(400, ['ok' => false, 'error' => ['code' => 'invalid', 'message' => 'The body is one JSON object.']]);
}
[$http, $answer] = kn_bridge_dispatch(db(), $msg);
$out($http, $answer);
