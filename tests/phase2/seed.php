<?php
/** Seeds the proofs' two SMOKE bases (idempotent) and prints their ids — the browser proof calls this between its empty and its listed home. */
require __DIR__ . '/lib.php';
[$open, $private] = seed_bases();
echo "$open $private\n";
