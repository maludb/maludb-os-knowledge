<?php
/**
 * Router for `php -S 127.0.0.1:8501 -t html tests/dev_router.php` — the vhost's rewrites (deploy/apache-knowledge.conf), so the
 * proofs run without Apache: /sso, /sso/logout, /api/v1/health, the token API's /api/v1/{ask,search,bases} and /questions/{id}[/feedback], the attachment door /files/{id}, and the canonical URLs
 * (/x/new, /x/{id}/edit, /x/{id}, /x → x.php or x/index.php). A real file is served as it is; a rewrite to a file its slice has not
 * built yet answers 404, as Apache does.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__) . '/html';
if ($path !== '/' && is_file($root . $path)) {
    if (str_ends_with($path, '.webmanifest')) { header('Content-Type: application/manifest+json'); readfile($root . $path); return true; }
    return false;                                             // a static file or a real .php file
}
// The token API (slice 7): the same rewrites as deploy/apache-knowledge.conf's public allow-list; anything else under /api is 404.
if (preg_match('#^/api/v1/(ask|search|bases)/?$#', $path, $m)) { $_SERVER['SCRIPT_NAME'] = "/api/v1/$m[1].php"; $_SERVER['SCRIPT_FILENAME'] = $root . "/api/v1/$m[1].php"; chdir($root . '/api/v1'); require $root . "/api/v1/$m[1].php"; return true; }
if (preg_match('#^/api/v1/questions/([0-9]+)/?$#', $path, $m)) { $_GET['id'] = $m[1]; $_REQUEST['id'] = $m[1]; $_SERVER['SCRIPT_NAME'] = '/api/v1/question.php'; $_SERVER['SCRIPT_FILENAME'] = $root . '/api/v1/question.php'; chdir($root . '/api/v1'); require $root . '/api/v1/question.php'; return true; }
if (preg_match('#^/api/v1/questions/([0-9]+)/feedback/?$#', $path, $m)) { $_GET['id'] = $m[1]; $_REQUEST['id'] = $m[1]; $_SERVER['SCRIPT_NAME'] = '/api/v1/feedback.php'; $_SERVER['SCRIPT_FILENAME'] = $root . '/api/v1/feedback.php'; chdir($root . '/api/v1'); require $root . '/api/v1/feedback.php'; return true; }
if (str_starts_with($path, '/api/') && !preg_match('#^/api/v1/health/?$#', $path)) { http_response_code(404); echo 'Not found'; return true; }
$map = ['/sso' => '/sso.php', '/sso/logout' => '/sso/logout.php', '/api/v1/health' => '/api/v1/health.php'];
$rel = rtrim($path, '/') ?: '/';
$set = static function (array $kv): void { foreach ($kv as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; } };
if (preg_match('#^/files/([0-9]+)$#', $path, $m)) { $set(['id' => $m[1]]); $target = '/files.php'; }
elseif (preg_match('#^/(.+)/([0-9]+)/([a-z_-]+)$#', $path, $m) && is_file($root . '/' . $m[1] . '/' . $m[3] . '.php')) { $target = '/' . $m[1] . '/' . $m[3] . '.php'; $set(['id' => $m[2]]); }
elseif (isset($map[$rel])) { $target = $map[$rel]; }
elseif ($rel === '/') { $target = '/index.php'; }
elseif (preg_match('#^/(.+)/new$#', $rel, $m)) { $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/(.+)/([0-9]+)/edit$#', $rel, $m)) { $target = '/' . $m[1] . '/form.php'; $set(['id' => $m[2]]); }
elseif (preg_match('#^/(.+)/([0-9]+)$#', $rel, $m)) { $target = '/' . $m[1] . '/view.php'; $set(['id' => $m[2]]); }
elseif (is_file($root . $rel . '.php')) { $target = $rel . '.php'; }
elseif (is_file($root . $rel . '/index.php')) { $target = $rel . '/index.php'; }
else { http_response_code(404); echo 'Not found'; return true; }
if (!is_file($root . $target)) { http_response_code(404); echo 'Not found'; return true; }
$_SERVER['SCRIPT_NAME'] = $target;
$_SERVER['SCRIPT_FILENAME'] = $root . $target;
chdir(dirname($root . $target));
require $root . $target;
return true;
