<?php
declare(strict_types=1);
/**
 * CLI probes the kit proof (tests/phase0/run.sh) drives — each prints plain lines the shell compares. Needs the proof's environment
 * (DB_*, ACTION_TOKEN_KEY …, exported by run.sh). Never run against an installed database.
 *   php probe.php log                       log_activity with base_id/token_id, then with values that would throw (it must not)
 *   php probe.php attach <source_id>        store_attachment(): an executable, an executable named .pdf, an SVG, an SVG named .txt, a small PDF
 *   php probe.php apikey <raw> <base_id> [<member_id_for_session>]   api_authenticate() as a key, then api_require_base(<base_id>)
 *   php probe.php mail                      malumail_send() with and without a key
 */
if (PHP_SAPI !== 'cli') { exit(1); }
$mode = $argv[1] ?? '';
if ($mode === 'apikey') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . ($argv[2] ?? '');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require dirname(__DIR__, 2) . '/app/api/bootstrap.php';
    $member = api_authenticate();
    echo 'authenticated:' . $member['id'] . ':key=' . ((api_key()['token_id'] ?? 0) > 0 ? 'yes' : 'no') . "\n";
    api_require_base((int) ($argv[3] ?? 0));
    echo "reached:" . (int) $argv[3] . "\n";
    exit(0);
}
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$pdo = db();
if ($mode === 'log') {
    log_activity($pdo, 'probe.event', 'base', 7, ['actor_member_id' => null, 'base_id' => 7, 'token_id' => 3, 'after' => ['name' => 'a title', 'count' => 2]]);
    echo "logged\n";
    try {
        log_activity($pdo, 'probe.bad_actor', 'base', 7, ['actor_member_id' => 'not-a-number']);          // a bigint column refuses it
        log_activity($pdo, 'probe.bad_json', 'base', 7, ['actor_member_id' => null, 'after' => ['x' => NAN]]);   // json_encode throws
        log_activity($pdo, 'probe.bad_base', null, null, ['actor_member_id' => null, 'base_id' => 'abc', 'token_id' => []]);
        echo "survived\n";
    } catch (Throwable $e) {
        echo 'threw:' . get_class($e) . "\n";
    }
    exit(0);
}
if ($mode === 'attach') {
    require_once dirname(__DIR__, 2) . '/app/features/files/queries.php';
    $src = (int) ($argv[2] ?? 0);
    $tmp = sys_get_temp_dir() . '/knprobe-' . getmypid();
    @mkdir($tmp);
    $cases = [
        'exe'      => ['setup.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00" . str_repeat("\0", 200)],
        'exe_pdf'  => ['invoice.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00" . str_repeat("\0", 200)],
        'svg'      => ['logo.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'svg_txt'  => ['notes.txt', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
        'script'   => ['run.sh', "#!/bin/sh\necho hi\n"],
        'pdf'      => ['rule.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"],
        'md'       => ['notes.md', "# A heading\n\nSome text.\n"],
    ];
    foreach ($cases as $label => [$name, $bytes]) {
        $f = $tmp . '/' . $label;
        file_put_contents($f, $bytes);
        try {
            $id = store_attachment($pdo, 'source', $src, ['name' => $name, 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)], null);
            echo "accepted:$label:$id\n";
        } catch (DomainException $e) {
            echo "refused:$label\n";
            @unlink($f);
        }
    }
    @rmdir($tmp);
    exit(0);
}
if ($mode === 'mail') {
    try {
        $r = malumail_send(['from' => 'kit@example.invalid', 'to' => 'someone@example.invalid', 'subject' => 'Kit proof', 'text' => 'hello']);
        echo 'sent:' . $r['status'] . "\n";
    } catch (Throwable $e) {
        echo 'mail-threw:' . $e->getMessage() . "\n";
    }
    exit(0);
}
fwrite(STDERR, "unknown mode\n");
exit(2);
