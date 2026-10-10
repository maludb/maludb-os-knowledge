<?php
/**
 * One step of the worker's build module called on its own, as the worker calls it (the CLI bootstrap; the proofs' environment):
 *   php tests/phase3/slice4/call.php prepare <build>        kn_build_prepare()
 *   php tests/phase3/slice4/call.php item <build> <item>    kn_build_item()
 * Prints the answer as JSON. The proofs use it to stop between prepare and the items, and to call an item while the engine is down.
 */
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/knowledge/worker/builds.php';
$pdo = db();
$a = array_slice($argv, 1);
echo json_encode(match ($a[0] ?? '') {
    'prepare' => kn_build_prepare($pdo, (int) $a[1]),
    'item' => kn_build_item($pdo, (int) $a[1], (int) $a[2]),
    default => 'usage: prepare <build> | item <build> <item>',
}), "\n";
