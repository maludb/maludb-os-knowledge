<?php
/**
 * Helpers for the slice 9 proofs (docs/build-specs/home-admin.md "Proof"). Run through tests/phase3/slice9/run.sh: a fresh SCRATCH database (kn_dev9), the application on :8501, the fake kernel
 * (:8502 — chat, K7 reads, K6 texts in each mode), THE FAKE ENGINE (:8503, state.config_fake: kb:0's namespace config is stateful in $FAKE_KB_STATE.cfg), the fake MaluDB + MaluMail (:8506,
 * its sends logged to $FAKE_MALUMAIL_LOG). The worker is run pass by pass (worker_at() of slice 8), its clock moved with KN_WORKER_NOW.
 * The cast: Priya (26, Member), Cara → Marco (27, Curator), Dee → Bea (28, Member), Gus → Ann (29, a guest), Reid (32, Reader), the admin (1), Avery (2, a second admin), Seamus (40, an agent —
 * the expert), the Archivist → 91 "SMOKE Archivist", and 90 "SMOKE Agent Ninety" (an agent the agents proof names).
 * The world (home_world()): ask_world()'s "SMOKE Handbook" (internal), "SMOKE Private Notes" (private) and "SMOKE Empty" (private, no section), plus "SMOKE Policies" (internal, a source cut and
 * extracted, the area "Warranty"). Priya reads the two internal bases; Cara owns all four. Auto-extract is off everywhere (the fake engine builds nothing).
 */
require dirname(__DIR__) . '/slice8/lib.php';
require_once APP_ROOT . '/app/knowledge/engine.php';       // kn_engine_error_message(): the module's sentences, asserted word for word
const AGENT90 = 90;

/** The world (idempotent): ask_world() plus 'policies' (id) and 'warranty' (its area id). */
function home_world(): array
{
    $w = ask_world();
    if (base_by_key('smoke-policies9') === null) {
        pdo()->exec("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (" . ARCHIVIST . ", 'agent', 'SMOKE Archivist', 'user', 'active', 'write', '{user}') ON CONFLICT (id) DO NOTHING");
        pdo()->exec("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (" . AGENT90 . ", 'agent', 'SMOKE Agent Ninety', 'user', 'active', 'write', '{user}') ON CONFLICT (id) DO NOTHING");
        $c = as_member(CARA);
        $p = make_base(CARA, 'SMOKE Policies', ['key' => 'smoke-policies9']);
        pdo()->exec("UPDATE knowledge_bases SET auto_extract = false WHERE id = $p");
        act($c, '/bases/visibility.php', ['base' => $p, 'visibility' => 'internal']);
        act($c, '/sources/add.php', ['base' => $p, 'kind' => 'text', 'title' => 'Policy manual', 'text' => handbook_text()]);
        worker_pass();
        pdo()->exec("UPDATE sections SET status = 'extracted', extracted_version = (SELECT guideline_version FROM knowledge_bases k WHERE k.id = sections.base_id), maludb_document_id = '8' || id WHERE base_id = $p");
        pdo()->exec("INSERT INTO focus_areas (base_id, name, slug, description, status) VALUES ($p, 'Warranty', 'warranty', 'Guarantee terms.', 'active')");
    }
    $p = (int) base_by_key('smoke-policies9');
    return $w + ['policies' => $p, 'warranty' => (int) one("SELECT id FROM focus_areas WHERE base_id = :b AND slug = 'warranty'", ['b' => $p])];
}

/** The page's HTML as a member. */
function html_of(int $member, string $path): string { return page(as_member($member), $path)['body']; }
/** A screen's JSON data as a member (status, data). */
function data_of(int $member, string $path): array { [$c, $d] = screen(as_member($member), $path); return [$c, $d]; }

/** A question of $base asked by $member (a row, not an ask), $ago seconds old: its id. */
function old_question(int $base, int $member, string $words, int $ago = 0, string $status = 'answered'): int
{
    return (int) one("INSERT INTO questions (base_id, asked_by_member_id, door, question, answer_md, status, created_at) VALUES (:b, :m, 'web', :q, 'An answer.', :s, now() - make_interval(secs => :a)) RETURNING id",
        ['b' => $base, 'm' => $member, 'q' => $words, 's' => $status, 'a' => $ago]);
}

/** A running build of $base with $total items of which $done are done (the database's own triggers run): its id. The sections must be there. */
function running_build(int $base, int $total, int $done): int
{
    $id = (int) one('INSERT INTO builds (base_id, kind, started_by) VALUES (:b, \'extract\', :m) RETURNING id', ['b' => $base, 'm' => CARA]);
    pdo()->exec("UPDATE builds SET status = 'running' WHERE id = $id");
    $secs = q("SELECT id FROM sections WHERE base_id = :b AND status <> 'forgotten' ORDER BY id LIMIT $total", ['b' => $base]);
    foreach ($secs as $i => $s) {
        pdo()->exec("INSERT INTO build_items (build_id, section_id, status) VALUES ($id, {$s['id']}, '" . ($i < $done ? 'done' : 'queued') . "')");
    }
    pdo()->exec("UPDATE builds SET started_at = now() - interval '2 minutes', items_total = $total, items_done = $done WHERE id = $id");
    return $id;
}
function finish_build(int $id, string $status = 'done'): void
{
    pdo()->exec("UPDATE builds SET status = '$status'" . ($status === 'failed' ? ", error = 'every_section_failed'" : '') . " WHERE id = $id");
}

/** The fake engine's state for the model page: kb:0 configured (the default) or not, and the namespaces the engine refuses ({"kb:0": "validation_failed" | "engine_error"}); every stored namespace config forgotten. */
function model_world(bool $configured = true, array $refuse = []): void
{
    $default = ['provider_name' => 'knowledge-ledger', 'provider_kind' => 'cloud_api', 'model_alias' => 'knowledge-extract', 'model_identifier' => 'fake-model-1', 'base_url' => 'http://127.0.0.1:9/v1',
                'embedding_model' => 'fake-embed-1', 'secret_ref' => 'knowledge-model-credential', 'prompt_template' => 'REFERENCE PROMPT {{chunk}}'];
    kb_state(['config_fake' => true, 'config' => $configured ? $default : null, 'config_refuse' => $refuse]);
    @unlink(need('FAKE_KB_STATE') . '.cfg');
}
/** What the fake engine stores per namespace (the config the application sent). */
function kb_cfg(): array { return json_decode((string) @file_get_contents(need('FAKE_KB_STATE') . '.cfg'), true) ?: []; }
/** The POSTs to /v1/memory/config the fake engine saw, oldest first (the bodies). */
function config_posts(): array { return array_values(array_filter(kb_log('/v1/memory/config'), static fn ($r) => $r['method'] === 'POST')); }
function config_log_clear(): void { file_put_contents(need('FAKE_KB_STATE') . '.log', ''); }

/** Queue an outbox row directly (the notice's kind, channel and words), $minutes in the past: its id. */
function outbox_add(string $channel, int $member, string $to, string $subject, string $body, ?string $kind = 'build_done', ?string $dedupe = null): int
{
    return (int) one("INSERT INTO notification_outbox (channel, member_id, to_email, kind, subject, body, dedupe_key, send_after) VALUES (:c, :m, :t, :k, :s, :b, :d, now() - interval '1 minute') RETURNING id",
        ['c' => $channel, 'm' => $member, 't' => $channel === 'email' ? $to : null, 'k' => $kind, 's' => $channel === 'email' ? $subject : null, 'b' => $body, 'd' => $dedupe]);
}
function outbox_get(int $id): array { return q('SELECT * FROM notification_outbox WHERE id = :id', ['id' => $id])[0] ?? []; }
/** The fake MaluMail's log (each JSON body sent), and a clean slate. */
function mail_log(): array { return array_map(static fn ($l) => json_decode($l, true), file(need('FAKE_MALUMAIL_LOG'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []); }
function mail_clear(): void { file_put_contents(need('FAKE_MALUMAIL_LOG'), ''); }
/** The fake kernel's K6 log (each body asked), and a clean slate. */
function sms_log(): array { return array_map(static fn ($l) => json_decode($l, true), file(need('FAKE_KERNEL_STATE') . '.sms', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []); }
function sms_clear(): void { file_put_contents(need('FAKE_KERNEL_STATE') . '.sms', ''); }
/** K6 in a mode for everyone (and per member). */
function sms_mode(string $mode, array $members = []): void { kernel_state(static function (array $s) use ($mode, $members): array { $s['sms'] = ['mode' => $mode, 'members' => $members]; return $s; }); }
/** The queue emptied: every queued row skipped, so a proof sees only its own. */
function outbox_drain(): void { pdo()->exec("UPDATE notification_outbox SET status = 'skipped', detail = 'drained' WHERE status = 'queued'"); }
/** The cron.run rows since an activity id. */
function cron_runs(int $since): array { return q("SELECT * FROM activity_log WHERE action = 'cron.run' AND id > :s ORDER BY id", ['s' => $since]); }
function steps_of(array $row): array { return (json_decode((string) $row['after'], true) ?: [])['steps'] ?? []; }
/** An ISO instant $days after today's noon UTC (the worker's clock for a given day). */
function day_at(int $days, string $time = '12:00:00'): string { return gmdate('Y-m-d', time() + $days * 86400) . 'T' . $time . 'Z'; }
function settings_row(): array { return q('SELECT * FROM kn_settings WHERE id = 1')[0]; }
function set_setting(string $col, string $value): void { pdo()->exec('UPDATE kn_settings SET ' . $col . " = '" . str_replace("'", "''", $value) . "' WHERE id = 1"); }

/** Mint a key through the handler as $member (JSON), as slice 7's helper does: ['status', 'body', 'id', 'raw']. */
function mint(int $member, int $base, string $label = 'SMOKE key', array $more = []): array
{
    [$c, $b] = act(as_member($member), '/keys/mint.php', ['base' => $base, 'label' => $label] + $more);
    return ['status' => $c, 'body' => $b, 'id' => (int) ($b['record_id'] ?? 0), 'raw' => (string) ($b['key'] ?? '')];
}
/** A call of the token API with a bearer key (or none): ['code', 'body' (decoded), 'headers', 'raw']. */
function api(string $method, string $path, ?string $bearer, ?array $body = null, array $extra = []): array
{
    $h = array_merge(['Accept: application/json'], $bearer !== null ? ['Authorization: Bearer ' . $bearer] : [], $extra);
    $o = ['headers' => $h];
    if ($body !== null) { $o['json'] = $body; }
    $r = req($method, $path, $o);
    return ['code' => (int) $r['code'], 'body' => json_decode($r['body'], true) ?? [], 'headers' => $r['headers'], 'raw' => $r['body']];
}

/**
 * What the browser proof opens (idempotent enough to be rerun for each viewport): Priya's six questions, a pin of Cara's past its date, the Archivist's note to Cara, a gap cluster and
 * a thumbs-down on the Handbook, a build running on the Handbook (3 sections, 1 done) and a failed one on the Policies, a failed dispatch, an open proposal, usage, two keys, an unread
 * notification for Priya, three questions older than the retention for the purge page. ['handbook', 'policies', 'private', 'empty', 'build', 'failed_build', 'keys' => [ids], 'section', 'dispatch'].
 */
function browser_world9(): array
{
    $w = home_world();
    $hb = $w['handbook']; $pol = $w['policies'];
    foreach (['handbook', 'policies', 'private', 'empty'] as $k) { pdo()->exec("UPDATE builds SET status = 'cancelled' WHERE base_id = {$w[$k]} AND status IN ('queued', 'running')"); }
    pdo()->exec("UPDATE pinned_answers SET status = 'retired', retired_at = now() WHERE status = 'active'");
    pdo()->exec('UPDATE kn_settings SET expert_member_id = ' . SEAMUS . ', archivist_member_id = ' . ARCHIVIST . ', question_retention_days = 365 WHERE id = 1');
    foreach ([1, 2, 3, 4, 5, 6] as $i) { old_question($hb, PRIYA, "How does leave work for case $i?", (7 - $i) * 90); }
    $qa = old_question($hb, PRIYA, 'How many sick days are there?', 300);
    $pin = (int) one('SELECT kn_pin_answer(:q, :m, :t)', ['q' => $qa, 'm' => CARA, 't' => 'Sick days, verified']);
    pdo()->exec("UPDATE pinned_answers SET review_until = current_date - 3 WHERE id = $pin");
    one("SELECT kn_notify(:m, 'monday_note', :t, :b, 'base', :bid, :d)", ['m' => CARA, 't' => 'Monday note: SMOKE Handbook', 'b' => "- 2 sources failed to read\n- 1 build failed\n- 3 questions found nothing", 'bid' => $hb, 'd' => 'smoke-browser-' . uniqid()]);
    foreach (['Do contractors carry over leave days?', 'How do contractors carry over leave?', 'Contractors and leave carry-over rules'] as $i => $words) { old_question($hb, PRIYA, $words, 100 + $i, 'no_evidence'); }
    pdo()->prepare("INSERT INTO question_feedback (question_id, member_id, rating, comment) VALUES (:q, :m, 'down', 'Not what I asked') ON CONFLICT DO NOTHING")->execute(['q' => $qa, 'm' => PRIYA]);
    $failed = running_build($pol, 1, 0);
    finish_build($failed, 'failed');
    $build = running_build($hb, 3, 1);
    pdo()->exec("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, base_id, status, detail, attempts, answered_at) VALUES ('base', $pol, " . SEAMUS . ", 'propose_areas', 'chat', " . CARA . ", $pol, 'failed', 'The kernel is not reachable right now.', 5, now())");
    $dispatch = (int) one('SELECT max(id) FROM agent_dispatches');
    pdo()->exec("INSERT INTO archivist_proposals (base_id, kind, title, reason, proposed_by) VALUES ($hb, 'gap', 'Browser gap', 'Three people asked.', " . ARCHIVIST . ") ON CONFLICT DO NOTHING");
    foreach ([[$hb, 'extract', 900000], [$pol, 'extract', 1100000]] as [$b, $k, $ch]) { pdo()->prepare('SELECT kn_usage_add(:b, :k, 3, 3000, 600, :c)')->execute(['b' => $b, 'k' => $k, 'c' => $ch]); }
    pdo()->exec("UPDATE knowledge_bases SET monthly_text_mb_limit = 1 WHERE id IN ($hb, $pol)");
    $keys = [mint(CARA, $hb, 'Browser key one')['id'], mint(CARA, $pol, 'Browser key two')['id']];
    pdo()->exec("INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (" . PRIYA . ", 'shared', 'base', $hb, 'Marco shared SMOKE Handbook with you', 'You can read')");
    pdo()->exec("INSERT INTO notifications (member_id, kind, record_type, record_id, title, body) VALUES (" . PRIYA . ", 'build_done', 'build', $failed, 'Build finished: SMOKE Policies', '3 sections')");
    foreach ([1, 2, 3] as $i) { old_question($hb, PRIYA, "An old question $i for the purge page", 400 * 86400); }
    model_world();
    return $w + ['build' => $build, 'failed_build' => $failed, 'keys' => $keys, 'section' => (int) one("SELECT id FROM sections WHERE base_id = :b AND status <> 'forgotten' ORDER BY id LIMIT 1", ['b' => $hb]), 'dispatch' => $dispatch];
}
