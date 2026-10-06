# Build spec: the token API, keys and K7 (slice 7)

Built by the worker model from this spec, replicating slice 5's files, gates and proof style (`docs/build-specs/ask.md`). What exists at the
end: a curator mints a key for one base (shown once), rotates it with a 24-hour overlap, revokes it, sets its limits; an outside system asks
that base with `POST /api/v1/ask` and gets exactly what the window gets, searches it, reads its own questions and rates them, and is refused
the 31st call in a minute with a 429 and `Retry-After`; the three K7 shares answer a sibling application only about the bases shared with it;
the admin sees which application reads what. The answers are slice 5's: every keyed door calls `kn_ask()` / `kn_search()` unchanged.
Schema: `mcp_access_tokens` with the bound-token columns (`base_id`, `focus_area_id`, `rate_per_minute`, `rate_per_day`, `rotated_from`)
(db/003); `kn_settings` (`key_rate_per_minute`, `key_rate_per_day`, `key_rotation_overlap_hours`) (db/005); `base_members` with the
`application` principal and `kn_application_base_ids()` (db/006); `questions`, `question_feedback` (db/010); `key_usage`,
`kn_access_tokens_before`, `kn_key_reaches_base()`, `kn_resolve_key()`, `kn_rate_ok()`, `kn_key_calls_today()`, `kn_key_rotate()`,
`kn_prune_key_usage()` (db/011); the views `mcp_keys` (never the hash), `mcp_bases`, `mcp_focus_areas`, `mcp_base_members`, `mcp_feeds`,
`mcp_questions`, `mcp_citations`, `mcp_activity_log` (db/014). Never modify them. The kit's API door: `app/api/bootstrap.php` (`api_authenticate()`,
`api_key()`, `api_require_base()`, `api_rate_limit()`, `api_json()`, `api_error()`, `api_cors()`, `api_require_method()`). **The database is the
referee**: a key reaches its one base for life, its area is an area of that base, its limits default from the settings, the minute bucket
refuses the 31st call and the day the 2,001st (a burst never eats the day), rotation keeps the old key the overlap and no longer, a revoked or
expired key or one whose minter lost access resolves to nothing.

## Divergences from the design
1. **K7 tells a provider who is asking — K26, approved and built by the kernel 2026-10-06 (db/173).** The design's share gate is "only for bases shared
   with the consumer application" (`kn_application_base_ids(<consumer>)`). The kernel's `application_read()` now sends two HTTP headers on every
   message of its MCP call, set from the consumer's own registration: `X-OS-Consumer` (its catalog key) and `X-OS-Consumer-Agent` (its expert
   agent's member id, when it has one) — headers, not an argument, because the siblings' share models forbid extra arguments — and strips any
   `consumer`, `consumer_agent_id`, `as_agent` or `scope_id` the consumer sent. **Here (Phase 4):** the records server's kernel-token gate reads
   the two headers beside `Authorization` into request-scoped variables and hands `consumer` to the share functions, which take it as a parameter;
   a kernel call with no `X-OS-Consumer` **fails closed**: `{"error": {"code": "consumer_unknown", "message": "The kernel did not say which
   application is asking."}}` and nothing shared. `ask` and `search` declare `timeout_seconds: 60` in `maludb-os.json` (`bases_index` keeps 8).
2. **A key's rating is its minter's.** `question_feedback` has no token column and is unique per `(question_id, member_id)`: a rating posted with
   a key is stored as the key's minter's (the trigger fills `member_id` from `app.member_id`, which `api_authenticate()` sets to the minter), so a
   key's rating replaces the minter's own on that question. The log row carries the `token_id`, so a curator can tell them apart in the history.
   (The tool surface's "one rating per question per key" cannot be kept by this table; a token column on `question_feedback` would be an additive
   migration if the owner wants keys rated apart from their minter.)
3. **`key_revoke` "(not its own)"** (design §5) cannot be expressed as an approval category: the manifest marks `key_revoke` `deletion`, so every
   agent revoke pauses, its own keys included.
4. **`question.search`** is logged for `/api/v1/search` (slice 5's divergence 4); the `search` share logs `share.read` instead.
5. **`key.save`** is added to the design's event list (§6.4 has `key.mint|rotate|revoke|rate_limited` and no event for changing a key's label,
   area or limits, which the design's `key-form` does).

## Screens (usable at 375 px, designed at 1280 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `keys` | `/bases/{id}/keys` | the base's keys (`mcp_keys`): a table — label, area, per minute / per day, last used, **today's count** (`calls_today`), status (live; expiring at <time> when rotated; revoked; expired), minted by and when, "rotated from" linking to the older key; **Mint a key**; per row **Edit**, **Rotate**, **Revoke**; the copy-once box after a mint or a rotate (below); a note on the API's URL (`APP_URL` + `/api/v1/ask`) with a curl example using `KEY` as a placeholder |
| `key-add` / `key-edit` | `/keys/new?base=`, `/keys/{id}/edit` | label (1–80, required — what will use the key), area (Whole base or an active area of the base — `mcp_focus_areas`), calls per minute and per day (blank = the settings' defaults, shown as the placeholder); Mint / Save |
| `connections` | `/admin/connections` | **read-only**: the three shares this application declares (`maludb-os.json` `shares[]`: tool, what it answers, its document); for each application named as a principal on a base (`mcp_base_members` `principal_kind = 'application'`): the application, the bases shared with it and since when; the reads this application declares (`reads[]`: provider, tool, why) with the state of the feeds that use them (`mcp_feeds` `last_status`, slice 8); the last 50 `share.read` rows (`mcp_activity_log`: when, consumer, tool, base, outcome, milliseconds); the K26 notice while the kernel does not name the consumer; a note: "Approving a connection between applications is a super-admin's decision in the OS" with a link to the OS's application page (`OS_LAUNCHER_URL`'s host with `app.` → `os.`) |

### The copy-once box
After a mint or a rotate the raw key is put in `$_SESSION['minted_key'] = ['key_id', 'raw', 'expires_old' => ?time]` and the redirect lands on
`/bases/{id}/keys#key-{id}`; the page renders it once in a `success` box (`key-minted`) — the value in a read-only field with a Copy button,
"Shown once — store it now; it cannot be shown again." — and unsets it. It is never in a URL, a log row, a notice, an HTMX header or the JSON of
any read. JSON mode (an agent under an action token, after its approval) gets it once in the action's response (`key`), as the kit's
`token_mint` does.

## The keys
- **Mint** (`key_mint`): `keys.manage` and `kn_can_curate_base(base)` (the admin: `keys.all`); label required; area optional (the trigger's
  words when it is not the base's); limits: blank → the trigger's defaults; a value above the settings' default needs `limits.manage` (the
  admin), else 422 `fields.rate_per_minute` "A key's limit is at most the default (30 a minute) unless an admin raises it."; the raw key is
  `'mcp_' . bin2hex(random_bytes(24))` (db/011's form), stored as `hash('sha256', $raw)` with `scope = 'api'`, `member_id` = the minter. An
  archived base refuses ("An archived base gets no keys."). `external_send` for an agent.
- **Edit** (`key_save`): label, area, limits (the same rules); never the base (the trigger: "A key is bound to one base for life").
- **Rotate** (`key_rotate`): a live key only (`kn_key_rotate()`'s words otherwise); a new raw key, `kn_key_rotate(old, hash(new))` → the new id;
  the old key expires after `key_rotation_overlap_hours` (24) — both work until then; the copy-once box shows the new key and "The old key stops
  working at <time>." `external_send` for an agent.
- **Revoke** (`key_revoke`): `revoked_at = now()` on a key not already revoked; it answers 401 at once (`kn_resolve_key()` returns nothing);
  revoking a key that is mid-overlap revokes only it. `deletion` for an agent (divergence 3).
- **A revoked grant shuts the door**: a minter whose capability is withdrawn (the mirror's `capability` NULL) or who loses the base —
  `kn_resolve_key()` and `api_require_base()` refuse within the directory sync's minute.

## The token API (`/api/v1/*`, bearer = a key; the JSON shapes are design §8 and `docs/knowledge-mcp-tool-surface.md` — `os.knowledge-answer/1`, `os.knowledge-evidence/1`, `os.knowledge-bases/1` — not repeated here)
Every handler: `api_cors()`; `api_require_method()`; `$member = api_authenticate()` (a session caller — the application's own pages — is also
admitted, held to the ordinary reach and **never charged**); **`api_rate_limit()` once**, before anything else is read (a key's call counted in
its minute and day buckets by `kn_rate_ok()`; the 31st in a minute → 429 `rate_limited` with `Retry-After: <seconds>`); the base — a key's base is
`api_key()['base_id']` and a body naming another base is ignored (a session caller names `base`, by id or key); `api_require_base()`; the work;
`api_json()`.
| Endpoint | File | Does |
|---|---|---|
| `POST /api/v1/ask` `{question, focus_area?, conversation_id?}` | `html/api/v1/ask.php` | `kn_ask()` with door `api`, `token_id`, the area by id or slug (the key's own area forces it — naming another → **403 `area_not_allowed`**), the conversation (the key's own, or its
predecessor's; another's starts a new one) → `os.knowledge-answer/1`; HTTP 200 for `answered`, `evidence_only`, `no_evidence`, `unverified` and `refused` (the status says which), **503 `engine_unavailable` / `engine_timeout`** for `failed` with the `question_id` in the error body; a gate refusal → 404 or 422 `{error: {code, message, fields}}` |
| `POST /api/v1/search` `{query, limit?}` | `search.php` | `kn_search()` (`limit` 1–20, default 8; `focus_area` accepted and echoed) → `os.knowledge-evidence/1`; `question.search` logged; 503 when the engine is unavailable |
| `GET /api/v1/bases` | `bases.php` | `os.knowledge-bases/1` — a key: its one base; a session: every base `mcp_bases` gives (people-free: no owner, curator or key) |
| `GET /api/v1/questions/{id}` | `question.php` | a key: a question asked with this key or the key it was rotated from (else 404); a session: `mcp_questions` decides → the question with its answer, citations (`mcp_citations`) and evidence |
| `POST /api/v1/questions/{id}/feedback` `{rating: up|down, comment?}` | `feedback.php` | the same visibility; slice 5's `rate_question()` (divergence 2); the thumbs-down notice; 200 `{question_id, rating, comment, updated_at}` |
| `GET /api/v1/health` | `health.php` (Phase 2's) | gains `engine` (the tool surface's values): `ok` / `unavailable` from `kn_engine_available()` (no URL or token configured is `unavailable`), and `answer_unavailable` when the engine is up but the last question of the past hour fell back for `answer_unavailable` (M1 not deployed); cached 60 s under `storage/cache/`; never a URL or token |
Errors: 401 `unauthorized` (one body for every bearer failure — a missing, wrong, revoked, expired or `mcp`-scope token); 404 `not_found` (a base
or question outside the key's reach — the same sentence as one that does not exist); 403 `area_not_allowed`; 405; 422 `invalid` with `fields`;
429 `{"error": {"code": "rate_limited", "message": "Too many calls (minute).", "limit": "minute" | "day"}}` + `Retry-After` (≥ 1 s); 503
`engine_unavailable` / `engine_timeout`. A key never learns that another base exists.
- **The 429 is logged once per bucket**: `api_rate_limit()` (the kit — this slice adds the line) logs `key.rate_limited` (`token_id`, `after`:
  `key_label`, `limit_hit` minute|day, `retry_after`) when that bucket's `key_usage.refused` has just become 1, so a flood writes one row a
  minute, not thousands.
- **Every keyed question** is logged by `kn_ask()` as `question.ask` with `source api`, the `token_id` column and `after.key_label` — the key
  itself never.
- **The vhost allow-list** is already in `deploy/apache-knowledge.conf` (`/api/v1/{ask,search,bases}`, `/api/v1/questions/{id}`,
  `/api/v1/questions/{id}/feedback`, `/api/v1/health`); the rule passing `^/api/v1/[a-z_-]+\.php$` means **nothing that is not meant for a key may
  ever live under `html/api/v1/`**. This slice extends `tests/dev_router.php` with the same five rewrites (it answers 404 for `/api/` today).
- **Pruning**: the worker's step `key_usage` runs `kn_prune_key_usage()` once a pass (slice 7 adds the step to `bin/worker.php`; `cron.run` lists
  it with the count).

## K7 — the three shares (Knowledge as a provider)
The tools themselves are Phase 4's (the records MCP server admits the kernel's token to `app_roles` and these three alone). They reach PHP
through the engine bridge (`docs/knowledge-mcp-tool-surface.md` § The engine bridge, built by slice 5) as the ops `share_ask`, `share_search`
and `share_bases` with an `application` caller — this slice adds those three ops to `app/knowledge/bridge.php` and writes what they call, in
`app/knowledge/share.php`; the documents and arguments are the tool surface's (§ The shares), with the kernel-set `consumer`:
- `kn_share_bases_index(PDO, ?string $consumer): array` → `os.knowledge-bases/1`: the bases in `kn_application_base_ids($consumer)` (not archived):
  `key`, `name`, `description`, `status`, `areas[{slug, name}]` (active), `last_built_at`. No member, no guideline, no count of questions.
- `kn_share_ask(PDO, ?string $consumer, array $args): array` → `os.knowledge-answer/1`: `$args` `{base (key or id), question, focus_area?}`;
  `kn_ask()` with door `share` and `application_key = $consumer` (no member: the question row names the application; nobody's identity crosses —
  the kernel's rule); a base not shared with `$consumer` → `{"schema", "application", "error": {"code": "not_shared", "message": "No such base
  is shared with this application."}}` — the same words for a base that does not exist, and a normal result, not an MCP error, so the kernel
  passes it back.
- `kn_share_search(PDO, ?string $consumer, array $args): array` → `os.knowledge-evidence/1` via `kn_search()` with door `share` (`limit` ≤ 20).
- **Time**: the kernel waits a share's own `timeout_seconds` (K26): `ask` and `search` declare 60, so the bridge budgets a share's engine call at
  50 s — a slower M1 answer comes back `failed`, `error_code: engine_timeout`, `message: "call search for the passages"`; `bases_index` answers
  from the records alone inside the default 8.
- **The gate**: `$consumer` (from `X-OS-Consumer`) null → `consumer_unknown` (divergence 1); a consumer key not matching `^[a-z0-9_]{1,60}$` → `invalid`; then the
  `application` principal — `kn_application_base_ids()` — is the only thing that opens a base: an internal base is **not** shared with an
  application unless it is named, a guest's or an agent's share counts for nothing here.
- **`share.read`** — every call, answered or refused, logs `share.read` (`source application`, `actor_member_id` NULL, `base_id` when one was
  named, `after` (the tool surface's): `application` (the consumer), `tool`, `outcome` ok|not_shared|consumer_unknown|invalid|engine_unavailable|
  engine_timeout, `ms`) — never the
  question or the answer. A share's question is also logged by `kn_ask()` (`question.ask`, `source application`).
- Rate: a sibling is not rate-limited here (the kernel meters K7 calls); the base's monthly limit does not apply to asking.

## Files (exactly these)
- `html/bases/keys.php` (`keys`, at `/bases/{id}/keys`) · `html/keys/form.php` (`key-add`, `key-edit`) · `html/keys/mint.php` · `html/keys/save.php` · `html/keys/rotate.php` · `html/keys/revoke.php`
- `html/api/v1/ask.php` · `search.php` · `bases.php` · `question.php` · `feedback.php` · `health.php` (gains `engine`)
- `html/admin/connections.php` (`connections`)
- `app/knowledge/share.php` · `app/knowledge/bridge.php` (slice 5's: the ops `share_ask`, `share_search`, `share_bases`) · `app/features/keys/{queries,present,write,handler}.php` · `app/features/connections/{queries,present}.php`
- `app/api/bootstrap.php` — the kit's: `api_rate_limit()` gains the once-per-bucket `key.rate_limited` row; nothing else changes
- `app/views/keys/{index,form,partials/key-row,partials/key-minted}.php` · `app/views/admin/{connections,partials/share-read-row}.php`
- `bin/worker.php` — the `key_usage` step · `tests/dev_router.php` — the API rewrites
- `tests/phase3/slice7/{run.sh,lib.php,keys.php,rotate.php,api.php,rate.php,feedback.php,share.php,connections.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `find_keys(PDO, int $baseId): array` (`mcp_keys`) · `find_key(PDO, int $keyId): ?array` (`mcp_keys`; null = not a base the caller curates) · `all_keys(PDO, array $f): array` (slice 9's admin list reads it)
- `mint_key(PDO, int $baseId, array $fields, int $by): array` (`['key_id', 'raw']`) · `save_key(PDO, int $keyId, array $fields, int $by): array` (`['changed']`) · `rotate_key(PDO, int $keyId, int $by): array` (`['key_id', 'raw', 'old_expires_at']`) · `revoke_key(PDO, int $keyId, int $by): void`
- `key_token_ids(PDO, int $tokenId): array` (this key's id and its `rotated_from`, the ids whose questions the key may read) · `api_find_question(PDO, int $id): ?array`
- `kn_share_bases_index(PDO, ?string $consumer): array` · `kn_share_ask(PDO, ?string $consumer, array $args): array` · `kn_share_search(PDO, ?string $consumer, array $args): array` · `share_log(PDO, ?string $consumer, string $tool, ?int $baseId, string $outcome, int $ms): void`
- `declared_shares(): array` · `declared_reads(): array` (from `maludb-os.json`) · `application_principals(PDO): array` (`mcp_base_members`) · `share_reads(PDO, int $limit = 50): array` (`mcp_activity_log`, `action = 'share.read'`)

## Handlers (every one: `kn_handler_begin()`; the right; the base's reach; `kn_guard()`; `log_activity`; `emit_action_status()`; `kn_done()`; `HX-Trigger: keyChanged`)
- `keys/mint.php` (`key_mint`): `keys.manage` + curate (or `keys.all`); `key.mint` (`key_id` as `entity_id`, `base_id`, `after`: `label`, `focus_area_id`, `rate_per_minute`, `rate_per_day`) — never the raw key or its hash; location `/bases/{id}/keys#key-{id}`; **external_send**.
- `keys/save.php` (`key_save`): the same right; `key.save` (divergence 5; `before`/`after` of the changed fields — `kn_diff()`); no category.
- `keys/rotate.php` (`key_rotate`): the same right; `key.rotate` (`after`: `old_key_id`, `new_key_id`, `overlap_hours`); **external_send**.
- `keys/revoke.php` (`key_revoke`): the same right; `key.revoke` (`after.label`); **deletion**.
- The screens: `keys`, `key-add` and `key-edit`: slice 1's `base_from_request()` then `require_base_right($base, 'keys.manage')` (the admin: `keys.all`); `connections`: `require_any_right('settings.manage|agents.settings')`; `log_screen_view()` on each.
- The API handlers log through `kn_ask()` / `kn_search()` / `rate_question()` (`question.feedback` with the `token_id` column) and `key.rate_limited`; they never call `log_screen_view()`.

## Action manifest entries
Screens: `keys`, `key-add`, `key-edit`, `connections`. Actions (4): `key_mint`, `key_save`, `key_rotate`, `key_revoke`. Agent approvals (as
`maludb-os.json` lists them): `key_mint` and `key_rotate` `external_send`; `key_revoke` `deletion`; `key_save` none (a key's label or limits
under the default reach no one new). The token API and the share functions are doors, not actions. `PARTIAL_UPDATE_TARGETS` gains
`/keys/save.php => ['mcp_access_tokens', 'api_key', 'mcp_keys', 'key_id']`. The key's param is **`api_key`** in every key action (`key_save`,
`key_rotate`, `key_revoke`; `docs/knowledge-action-manifest.md`), because a base's own `key` param (`base_create`, `base_update`) is its slug
and must never be resolved as an API key.

## Activity log events
`key.mint`, `key.save`, `key.rotate`, `key.revoke`, `key.rate_limited` (once per bucket), `question.ask` / outcome rows with `source api`
(slice 5's), `question.search`, `question.feedback` (with `token_id`), `share.read`, `cron.run` (the `key_usage` step's count), `screen.view`.
**A key, its hash or a question's text beyond 120 characters is in no payload.**

## Notifications this slice queues
None of its own (a keyed thumbs-down queues slice 5's `feedback` notice).

## Status vocabulary
Key status: live `success`, expiring (rotated, inside the overlap) `warning` with the time, revoked `dark`, expired `secondary`. Today's count
`danger` at ≥ 90 % of the day's limit. Share outcome: ok `success`, not_shared `secondary`, consumer_unknown `warning`, invalid `dark`,
engine_unavailable `danger`. Ids: `keys-table`, `key-{id}`, `key-minted`, `key-form`, `connections-shares`, `connections-principals`,
`connections-reads`, `share-reads`.

## Out of scope for this slice
The MCP servers and the share tools themselves (Phase 4); the kernel's K26 (owed — divergence 1); the public widget, CORS origins per key (Extended);
the admin's key defaults (`limits_save`, slice 9) and the all-keys page (slice 9, over `all_keys()`); feeds (slice 8, which uses the kernel's K7
as a consumer).

## Proof (`tests/phase3/slice7/run.sh`: the scratch database `knowledge_dev7`; ports 8501 the app (`tests/dev_router.php` with the API rewrites), 8502 the fake kernel, 8503 the fake engine, 8506 the fake episodes; curl with bearer keys and signed action tokens; a PHP probe (`share.php`) calling the share functions; headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
The world (`lib.php` `keys_world()`): base **Manuals** (id 1, internal, Cara curator) with built sections and areas **Warranty** (active) and
**Returns** (active); base **Contracts** (id 2, private); the fake engine scripted as in slice 5; Priya (Member), Cara, the admin; base 1 shared to
the application principal `helpdesk`, base 2 to nothing.
- [ ] **Mint**: Cara mints "Support site" → the copy-once box shows `mcp_` + 48 hex once (a reload does not show it again); the row stores only the
  sha256; `key.mint` logged without the raw key or hash (searched for across `activity_log`); defaults 30 / 2,000 filled by the trigger; an area of
  base 2 → the trigger's words; a per-minute limit of 100 by Cara → 422, by the admin → saved; Priya → 403; an archived base → refused.
- [ ] **The API**: `POST /api/v1/ask` with the key → slice 5's shape, `question.ask` with `source api`, `token_id`, `key_label`; the question row's
  `door api`, `token_id`, `asked_by_member_id` = Cara (the trigger); a body naming base 2 still asks base 1; a key bound to Warranty asking with
  `focus_area: returns` → 403 `area_not_allowed`; the fake engine stopped → 503 `engine_unavailable` with the `question_id`; `/api/v1/search` → evidence, `question.search`
  logged with the count; `/api/v1/bases` → the one base with its areas and today's count; `/api/v1/questions/{id}` for the key's own → 200, for
  Priya's window question → 404; feedback → stored as Cara's, logged with `token_id`; no bearer, a wrong one, a person's `mcp` token, a revoked key →
  the same 401 body; `GET /api/v1/health` shows `engine` and no URL or token.
- [ ] **The rate (`rate.php`)**: 30 calls in one minute → 200 each; the 31st → 429 with `Retry-After` ≤ 60 and `key.rate_limited` logged once; the
  32nd and 33rd → 429 and no further log row; the day bucket unchanged by the refused calls; a key with `rate_per_day = 3` → the 4th call 429
  `limit_hit day`; `kn_rate_ok()` called with a fixed `p_at` proves the minute boundary; **the window, as Priya, 40 asks in a minute → never a 429
  and no `key_usage` row** (slice 5's guard, from this side); a session caller of `/api/v1/ask` is not charged.
- [ ] **Rotate (`rotate.php`)**: rotate → a new key shown once, `rotated_from` set, the old key's `expires_at` = now + 24 h; both answer; with the
  clock past the overlap (`expires_at` moved back in SQL) the old one → 401; the new key reads the old key's questions; rotating a revoked key → the
  function's words; `key.rotate` logged with both ids.
- [ ] **Revoke**: revoked → 401 on the next call; `key.revoke` logged; the minter's capability withdrawn in the mirror → every key of theirs 401; the
  minter removed from a private base → 404 on that base.
- [ ] **K7 (`share.php`)**: `kn_share_bases_index($pdo, 'helpdesk')` → base 1 only, with its areas, no counts of people; `kn_share_ask($pdo,
  'helpdesk', {base: 'manuals', question})` → the answer document, a question row with `door share`, `application_key helpdesk` and no member;
  asking base 2 → `not_shared`; `projects` (shared nothing) → no bases, `not_shared`; an internal base not named to `helpdesk` → `not_shared`;
  the same three through a signed bridge call with an `application` caller answer the same documents, and an `application` caller on `ask`
  (not `share_ask`) is refused;
  `$consumer = null` → `consumer_unknown` and nothing shared; every call logged `share.read` with its outcome and no question text.
- [ ] **Connections**: the admin sees the three shares, `helpdesk` with base 1, the three reads with their feeds' status (none yet: "no feed uses
  it"), the `share.read` rows from the probe, the K26 notice; Cara → 403 in words.
- [ ] **JSON mode (`json.php`)**: `key_mint` under a signed action token answers `{ok, did, record_id, location, key}` once; `key_save` with
  `_partial=1` keeps the label when only a limit is sent; `key_rotate`, `key_revoke` answer their facts; on the MCP path the kernel's hook pauses
  `key_mint`/`key_rotate` (`external_send`) and `key_revoke` (`deletion`) — the categories in the registry (Phase 4 proves the pause).
- [ ] **375 × 740 and 1280 × 800**: the keys table at 1280 and as cards at 375; the mint form; the copy-once box with Copy; rotate shows the old key's
  end time; revoke through a confirm step (a second page, no modal); every control ≥ 44 px, `scrollWidth` = viewport, no console errors; the
  registry reads the four screens and four actions built.

## Built and proven
Not built.

## Open questions
