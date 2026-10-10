<?php
/** Slice 9 proof — usage by base and month (home-admin.md "Proof": usage.php). */
require __DIR__ . '/lib.php';

$w = home_world();
$hb = $w['handbook']; $pol = $w['policies']; $pv = $w['private'];
pdo()->exec('DELETE FROM ai_usage');
pdo()->exec("UPDATE knowledge_bases SET monthly_text_mb_limit = 1 WHERE id IN ($hb, $pol, $pv)");        // 1 MB = 1,048,576 characters
set_setting('limit_warn_pct', '80');
$use = static function (int $b, string $kind, int $calls, int $in, int $out, int $chars, string $at = 'now()'): void {
    pdo()->prepare("SELECT kn_usage_add(:b, :k, :c, :i, :o, :ch, $at)")->execute(['b' => $b, 'k' => $kind, 'c' => $calls, 'i' => $in, 'o' => $out, 'ch' => $chars]);
};
$use($hb, 'extract', 5, 9000, 2100, 900000);            // 85 %: warning
$use($hb, 'answer', 8, 1200, 300, 0);
$use($hb, 'embed', 2, 400, 0, 0);
$use($pol, 'extract', 3, 5000, 1000, 1100000);          // 104 %: danger
$use($pv, 'extract', 1, 100, 20, 10);                   // 0 %: fine
$use($hb, 'extract', 7, 70000, 7000, 200000, "now() - interval '1 month'");        // last month: 19 %
$owner = as_member(OWNER);
$month = (string) one("SELECT to_char(date_trunc('month', now()), 'YYYY-MM')");
$last = (string) one("SELECT to_char(date_trunc('month', now()) - interval '1 month', 'YYYY-MM')");

echo "1. This month, and last\n";
$since = last_activity_id();
$r = page($owner, '/admin/usage');
$h = $r['body'];
ok($r['code'] === 200 && str_contains($h, 'id="usage-row-' . $hb . '"') && str_contains($h, 'id="usage-card-' . $hb . '"') && str_contains($h, 'id="usage-table"') && str_contains($h, 'id="usage-cards"'), 'a row per base at 1280 and a card at 375');
ok(count(activity('screen.view', $since)) === 1 && activity('screen.view', $since)[0]['screen'] === 'admin-usage', 'logs screen.view');
[$c, $d] = screen($owner, '/admin/usage');
$by = array_column($d['bases'], null, 'base_id');
ok($c === 200 && $d['month'] === $month && $by[$hb]['kinds']['extract']['calls'] === 5 && $by[$hb]['kinds']['answer']['calls'] === 8 && $by[$hb]['kinds']['embed']['calls'] === 2 && $by[$hb]['calls'] === 15
    && $by[$hb]['input_tokens'] === 10600 && $by[$hb]['output_tokens'] === 2400 && $by[$hb]['chars'] === 900000, 'usage by base and kind: calls, tokens in and out, characters — the Handbook this month');
ok($by[$hb]['pct'] === 85 && $by[$pol]['pct'] === 104 && $by[$pv]['pct'] === 0 && $by[$hb]['limit_chars'] === 1048576, 'each base\'s characters against its limit: 85 %, 104 % and 0 %');
ok($d['totals']['extract']['calls'] === 9 && $d['totals']['answer']['calls'] === 8 && $d['totals']['all']['calls'] === 19 && $d['totals']['extract']['chars'] === 2000010, 'the totals by kind: ' . json_encode($d['totals']['extract']));
ok(str_contains($h, 'id="usage-row-' . $hb . '-pct"') && preg_match('#bg-soft-warning text-warning"[^>]*>85 %<#', $h) === 1 && preg_match('#bg-soft-danger text-danger"[^>]*>104 %<#', $h) === 1 && preg_match('#bg-soft-success text-success"[^>]*>0 %<#', $h) === 1, 'the limit chips: ≥ the warning percent warning, ≥ 100 % danger');
ok(preg_match('#id="usage-statement-link"[^>]*>#', $h) === 1 && str_contains($h, 'https://os.example.invalid/ai/spend/statements') && str_contains($h, 'Dollars are on the AI statement in the OS'), 'a note says dollars are on the AI statement in the OS, with a link to os.<domain>');
preg_match_all('#<option value="(\d{4}-\d{2})"#', $h, $m);
ok(count(array_unique($m[1])) === 12 && $m[1][0] === $month && in_array($last, $m[1], true), 'the month picker holds the last twelve months, this one first');
[$c, $d] = screen($owner, '/admin/usage?month=' . $last);
$by = array_column($d['bases'], null, 'base_id');
ok($d['month'] === $last && $by[$hb]['kinds']['extract']['calls'] === 7 && $by[$hb]['pct'] === 19 && $by[$pol]['calls'] === 0, 'last month: the Handbook\'s 7 calls and 19 %; nothing for the Policies');
[$c, $d] = screen($owner, '/admin/usage?month=nonsense');
ok($c === 200 && $d['month'] === $month, 'a month that is not YYYY-MM falls back to this one');
[$c, $d] = screen($owner, '/admin/usage?base=' . $pol);
ok(count($d['bases']) === 1 && $d['bases'][0]['base_id'] === $pol, 'a base filter narrows to that base');
$h = page($owner, '/admin/usage?base=' . $pol)['body'];
ok(str_contains($h, 'id="usage-base-picker"') && str_contains($h, 'SMOKE Policies'), '…chosen through the record picker, its name shown');

echo "2. Who may see it\n";
foreach ([CARA => 'a Curator', PRIYA => 'a Member', REID => 'a Reader', ANN => 'a guest'] as $mem => $who) {
    ok(page(as_member($mem), '/admin/usage')['code'] === 403, "$who → 403 (no costs.read)");
}
ok(page(as_member(AVERY), '/admin/usage')['code'] === 200, 'a second admin may');
pdo()->exec("UPDATE knowledge_bases SET monthly_text_mb_limit = (SELECT default_monthly_text_mb FROM kn_settings WHERE id = 1) WHERE id IN ($hb, $pol, $pv)");
pdo()->exec('DELETE FROM ai_usage');
finish();
