<?php
// php bin/dev_handoff.php <member_id> — prints a /sso URL signed exactly as the kernel signs one, from the
// claims in bin/dev_directory.json (testing-without-a-kernel.md). Development only: needs a development
// ACTION_TOKEN_KEY (openssl rand -hex 32); the installer replaces it with the tenant's, so it never opens a real install.
// HANDOFF_TTL=<seconds> (default 60; negative = already expired) and APP_KEY=<other> (a token for another audience) HANDOFF_NO_GRANT=1 (claims without a grant) let the proofs mint the bad ones.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/app/bootstrap.php';
$member = (int) ($argv[1] ?? 1);
$key = action_token_key();
$app = app_key();
$payload = $member . '.' . (time() + (int) (getenv('HANDOFF_TTL') !== false ? getenv('HANDOFF_TTL') : 60)) . '.' . $app . '.' . bin2hex(random_bytes(16));
$token = $payload . '.' . hash_hmac('sha256', 'sso:' . $payload, $key);
$fixture = json_decode((string) file_get_contents(__DIR__ . '/dev_directory.json'), true);
$claims = ($fixture['claims'][(string) $member] ?? []) + ['member_id' => $member];
if (getenv('HANDOFF_NO_GRANT') === '1') { $claims['capability'] = null; $claims['roles'] = []; }
$text = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
echo rtrim((string) env('APP_URL'), '/') . '/sso?' . http_build_query(['token' => $token, 'claims' => $text . '.' . hash_hmac('sha256', $text, $key)]) . "\n";
