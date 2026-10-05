# Build spec: home, admin, usage and tokens (slice 9)

Built by the worker model from this spec, replicating slice 5's files, gates and proof style (`docs/build-specs/ask.md`) and the siblings'
`home-admin` slices (`/srv/apps/spaces/docs/build-specs/home-admin.md`). What exists at the end: the Home every person lands on is real — a new
person's is one button, "Create a knowledge base"; everyone else's shows their bases, the builds running, their recent questions, the pins due,
the Archivist's last note, and for curators the week's gaps and thumbs-down, for the admin usage, failed builds and pending dispatches; the
admin area gathers the settings and templates (slice 1's screens), the model (shown, changed with a pause), usage by base and month, every
base, every key, the agents, the connections (slice 7's) and the proposals and dispatches (slice 8's); a person's notifications, preferences
and MCP tokens are whole; the worker sends the outbox, warns of pins due and limits near, and purges questions past retention. Nothing new in
the schema beyond slice 5's agent-member columns.
Schema: `kn_settings` and `mcp_settings` (db/005, db/014, and slice 5's `expert_member_id`, `archivist_member_id`); `mcp_templates` (db/014);
`knowledge_bases` / `mcp_bases` (db/006); `builds` / `mcp_builds`, `kn_build_progress()` (db/009, db/013); `questions` / `mcp_questions`,
`pinned_answers` / `mcp_pinned_answers`, `kn_purge_questions()` (db/010); `mcp_keys` (db/011, db/014); `notifications`, `notification_prefs`,
`notification_outbox`, `kn_notify()`, `kn_base_curator_ids()`, `ai_usage` / `mcp_ai_usage`, `agent_dispatches` / `mcp_agent_dispatches`,
`archivist_proposals` (db/012, db/014); `kn_question_gaps()` (db/013); `kn_graph_shape_changed()` (db/008); `mcp_notifications`,
`mcp_notification_prefs`, `mcp_access_tokens_mine`, `mcp_members`, `mcp_activity_log` (db/014). Never modify them. The kit:
`app/features/home/queries.php` (`home_summary()`), `app/features/settings/queries.php` (`find_prefs()`, `save_prefs()`,
`find_my_notifications()`, `mark_notifications_read()`, `find_my_tokens()`, `mint_token()`, `revoke_token()`, `last_text_refusal()`),
`html/settings/*`, `html/notifications.php`, `app/mail.php` (`malumail_send()`), `kernel_call()` (`app/auth.php`). Engine:
`kn_engine_available()`, `kn_engine_get_namespace_config()`, `kn_engine_config_namespace()`, `kn_engine_preview()`,
`kn_engine_error_message()` (finished). Compile (`docs/build-specs/guidelines-compile.md`): `kn_compile_connection()` (the business's engine
connection, the reference namespace `kb:0`), `kn_compile_send()`, `kn_compile_prompt()`. Slice 1 (`bases-core.md`) already built
`admin-settings` (the Settings, Limits and Retention forms — `settings_save`, `limits_save`, `retention_set`), `templates`,
`find_kn_settings()`, `save_kn_settings()` and the `base.admin_view` rule; this slice links them and adds to slice 1's Retention form only the
**Purge now** link. **The database is the referee**: a region shows what its view or function answers the caller; a bad value is the
database's sentence as a field error; the admin sees private bases because `kn_visible_base_ids()` says so — and the log says so.

## Divergences from the design
1. **The worker has no member**, and the read functions gate on the caller (`kn_build_estimate()`, `kn_usage()`, `kn_question_gaps()` answer
   nothing without `app.member_id`). The worker's steps here (limits near, pins due, retention) read the base tables directly as the writer role —
   they write only notifications and the purge, never a screen.
2. **Who the expert and the Archivist are** is set here (slice 5's columns): Admin › Agents lists the mirror's agent members and the admin names
   each (`agents_settings_save`). The action is not in the design's list; it is `other` for an agent, like `settings_save`, and is in
   `maludb-os.json`'s `approvals` (written from the manifest, 2026-10-05).
3. **The outbox sender** is not named in any earlier slice; it is this slice's step `outbox` (design §14 lists "the outbox" among the worker's
   jobs). Notices queued since slice 4 wait in `notification_outbox` until it lands.
4. **The model lives in `kb:0`** (guidelines-compile.md, divergence 1): what the admin changes is the reference namespace's connection — the
   provider's model id and the embedding model — and every base is re-sent from it. `kn_settings.model_alias` and `embedding_model` record the
   admin's choice (the model id in `model_alias`; the column's name predates the reference namespace), so the page and the log show what was chosen
   even when the engine is down.

## Screens (375 px is the design for Home; the admin pages usable at 375 px, designed at 1280 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `home` | `/` | **first run** — no base visible: with `bases.create`, one card with one button, **Create a knowledge base** (→ slice 1's `/bases/new`) and the three steps under it in muted text ("Create a base · Add documents · Ask"); a guest or anyone without `bases.create`: "Nothing has been shared with you yet." **Otherwise**: **My bases** (cards — `mcp_bases`: name, status chip, sources and sections, "needs a rebuild", last built, my level; → slice 1's `base-view`; an empty base's card says **Add documents** → `/sources/new?base={id}`); **Builds running** (curators — `mcp_builds` queued or running with `kn_build_progress()`'s percent and ETA; polls); **My recent questions** (`mcp_questions` mine, five — the first line, status chip, base → `question-view`); **Pins due for review** (`mcp_pinned_answers` active and `review_due`, owned by me or on bases I curate → `pinned`); **The Archivist's note** (my latest `monday_note` notification — its title and first lines → the notification); **Gaps this week** (curators — for each base I curate, `kn_question_gaps(base, now − 7 days)`' top three clusters and the thumbs-down count → `gaps`); **Admin** (`settings.manage`): usage this month by base (`mcp_ai_usage`: calls and tokens by kind, characters extracted against each base's limit), failed builds (7 days), pending or failed dispatches (`mcp_agent_dispatches`), open proposals. Each region a card linking to its page; an empty region says what will appear there |
| `admin` | `/admin/` | the admin area's index: a card per page — slice 1's `admin-settings` and `templates`; this slice's model, usage, bases, keys, agents, purge; slice 7's `connections`; slice 8's `admin-proposals` and `dispatches` — each with its one-line state ("3 bases private", "2 keys expiring", "1 dispatch failed") |
| `admin-purge` | `/admin/purge?days=` | `retention.manage`: "Purge questions older than N days now" (N defaults to the retention setting) — how many would go (questions behind an active pin are kept), then **Purge** (`question_purge`); reached from slice 1's Retention form |
| `admin-model` | `/admin/model` | `model.manage`: the business's connection (`kn_compile_connection()` — provider, model id, embedding model, the ledger proxy's base URL, the secret's **name** only; when `kb:0` is missing, `model_not_configured` in words with "the owner configures it once — `deploy/ROOT_STEPS.sh`"); what a chosen base's namespace holds (`kn_engine_get_namespace_config(ns)` — never a secret); the engine's health (`kn_engine_available()`); the admin's last choice (`kn_settings.model_alias`, `embedding_model`); the form (`model_config_save`) with the line "the model must be allowed on Knowledge's K24 credential and priced in the OS's model registry"; **Test** — the base's compiled prompt filled with a chosen section (`kn_compile_prompt()` with `{{chunk}}` replaced, as slice 3's Preview does), labelled "This shows the prompt the model would be sent — MaluDB cannot yet run a dry extraction (M5)." |
| `admin-usage` | `/admin/usage?month=&base=` | `costs.read`: `mcp_ai_usage` by base and kind for the month (calls, input and output tokens, characters), totals, the month picker (the last 12), each base's characters against its limit; a note: "Dollars are on the AI statement in the OS" with a link (`OS_LAUNCHER_URL`'s host, `app.` → `os.`) |
| `admin-bases` | `/admin/bases?visibility=&status=&archived=` | every base (`mcp_bases` — the admin's set is all): name, owner, visibility (private marked `feather-lock`), status, sources, sections, questions in 30 days, last built, archived; → slice 1's `base-view`, **which logs `base.admin_view`** when the admin is not one of its members (slice 1's `base_from_request()`); the list itself logs nothing |
| `admin-keys` | `/admin/keys?base=&status=` | `keys.all`: every key (slice 7's `all_keys()` over `mcp_keys`): base, label, area, limits, today's count, last used, status; **Revoke** (slice 7's `key_revoke`) |
| `admin-agents` | `/admin/agents` | `agents.settings`: the agents `maludb-os.json` declares (key, the job description's first line, the duty and its schedule in words, the skill and tool counts), the mirror's agent members (`mcp_members` where `is_agent`: name, roles here, status), **the expert** and **the Archivist** as named (`agents_settings_save` — a select of the agent members), each agent's last activity (`mcp_activity_log`) and dispatches by status (→ slice 8's `dispatches`), the last Monday note sent; a note: hiring, grants and duties are the kernel's (a link to the OS's Agent HR) |
| `settings` | `/settings/` | the kit's, made whole: my preferences (`save_prefs()`): email on/off, texts on/off (K6 — "texts go through the OS; the last refusal: …" from `last_text_refusal()`), the kinds I am emailed and texted — exactly the CHECK's list `build_done`, `build_failed`, `limit_near`, `proposal`, `feedback`, `review_due`, `monday_note`, `shared`, `source_failed`, each with a sentence of when it comes; my time zone (from HR, read-only) |
| `notifications` | `/notifications?unread=` | the kit's, made whole: my notifications (`find_my_notifications()`), each linking to its record (a build → slice 4's `build-view`, a proposal → `proposals`, a question → `question-view`, a pin → `pinned`, a base → `base-view`, a source → `source-view`, the Monday note → its base's `proposals`); mark one or all read (the kit's `notification_read`) |
| `tokens` | `/settings/tokens/` | the kit's, made whole: my MCP tokens (`mcp_access_tokens_mine`, scope `mcp`): label, last used, created, revoke; mint shows the value once (the kit's); the two URLs to paste (`APP_URL` + `/mcp/records`, `/mcp/activity`) and the sentence "A token reads what you may read. An API key for a base is minted on that base's Keys page." |

## Home
- `home_summary(PDO, int $memberId): array` (the kit's function, extended) — keys: `bases`, `first_run` (bool), `builds` (curated, running),
  `recent_questions`, `pins_due`, `archivist_note`, `gaps` (per curated base: `base_id`, `name`, `clusters` (three), `thumbs_down`), `admin`
  (`admin_home()`: `usage`, `failed_builds`, `dispatches`, `open_proposals`) or null, `may` (`create`, `admin`, `costs`). One query per region; a
  region the caller has no right to is null and not rendered.
- **The order on a phone**: first run alone; else My bases, Builds running, My recent questions, Pins due, the Archivist's note, Gaps this week, the
  admin cards. At 1280: bases full-width, then two columns.
- **Builds running** polls: `hx-get="/home/builds" hx-trigger="every 5s [document.visibilityState=='visible']" hx-swap="outerHTML"`; the endpoint
  (`html/home/builds.php`) renders the region; when nothing is queued or running it answers **286** (HTMX stops polling — slice 2's
  `sources-status` pattern) and sends `HX-Trigger: baseChanged` once so the bases region refreshes.
- Home logs `screen.view` and writes nothing else.

## The admin pages' actions
- **`question_purge`** (`retention.manage`; **deletion** for an agent): `admin-purge` shows the count first (`SELECT count(*) FROM questions q WHERE
  q.created_at < now() - N days AND NOT EXISTS (an active pin on q)`), then POST with `confirm=1` runs `kn_purge_questions(make_interval(days =>
  N))`; N from 1 to 3,650; `question.purge` (`older_than_days`, `purged`); a pinned answer keeps its words (the pin holds them).
- **`model_config_save`** (`model.manage`; **other**): the model id and the embedding model. First `kn_engine_config_namespace('kb:0', …)` with
  `kn_compile_connection()['connection']`, `model_identifier` and `embedding_model` replaced, the secret **by name** (`secret_name`, never a
  `token`) and the reference's own `prompt_template` (from `kn_engine_get_namespace_config('kb:0')`) kept — refused → nothing else changes and the
  form shows `kn_engine_error_message()`. Accepted → `save_kn_settings($pdo, 'model', …)` records the choice, then `kn_compile_send($pdo, $base)`
  for every base not archived, counting failures. An **embedding model change** requires the box "Every base must be rebuilt — the stored passages
  were embedded with the old model" and then calls `kn_graph_shape_changed(base)` for each base (their sections go stale and each shows "needs a
  rebuild"; curators start the builds — `build_rebuild_all` stays paused for agents). `model_config.save` (`before`/`after` of the two values,
  `bases_sent`, `bases_failed`, `rebuild_marked`). A base whose send failed is listed with the module's sentence; the worker's `kn_compile_ensure()`
  before that base's next build sends it again (the compile spec's rule).
- **`agents_settings_save`** (`agents.settings`; **other**): `expert_member_id`, `archivist_member_id` — each an agent member or blank (slice 5's
  trigger refuses a human); `save_kn_settings($pdo, 'agents', …)`; `agents_settings.save` (`after`: the two member ids) — its own event,
  so the kernel's policy on it pauses this action and nothing else. The manifest's params are `expert` and `archivist` (an agent member each,
  resolved by name through `find_members`); they are written to the two columns.
- Every admin page: `require_right()` per its row above (the index `settings.manage`); `log_screen_view()`.

## The worker's steps (`bin/worker.php` gains them; `app/features/admin/worker_steps.php`)
- **`outbox`** (every pass): `notification_outbox` rows `status = 'queued' AND send_after <= now()`, 20 a pass, oldest first, claimed `FOR UPDATE
  SKIP LOCKED`. **email**: no `MALUMAIL_API_KEY` → `skipped`, `detail = 'no_mail_key'`; else `malumail_send()` with `to_email`, `subject`, `body`
  (and `body_html` when set) → `sent` with `provider_ref`; a rejection (suppressed, invalid) → `skipped` with the reason; 5xx or unreachable →
  `attempts + 1`, `send_after = now() + (2 ^ attempts) minutes`, five attempts → `failed`. **text**: `kernel_call('POST', '/api/v1/notify/sms.php',
  ['member_id' => member_id, 'text' => body], [], 15)` (K6 — the kernel holds the phone and the opt-out) → 202 `sent` with the kernel's id; its
  refusals (`not_held`, `no_verified_phone`, `opted_out`, `rate_limited`, `no_sender`) → `skipped` with the code; 5xx → the same backoff. The email
  of the same notice goes whatever the text's fate. `cron.run` counts `sent`, `skipped`, `failed`; no per-message activity row.
- **`pins_due`** (once a day — the first pass after midnight in `kn_settings.timezone`; `daily_step_due()` reads that day's `cron.run` rows): every
  active pin with `review_until <= current_date` → `kn_notify(owner, 'review_due', 'Pinned answer due for review: <title>', <base name>,
  'pinned_answer', id, 'review:' || id || ':' || review_until)` (the dedupe key tells the owner once per review date).
- **`limits_near`** (once a day): for each base not archived, this month's `ai_usage.chars` of kind `extract` against `monthly_text_mb_limit ×
  1,048,576` (db/013's approximation); at or above `limit_warn_pct` → `kn_notify(curator, 'limit_near', '<base> has used N % of this month's text',
  NULL, 'base', base, 'limit:' || base || ':' || to_char(month, 'YYYY-MM') || ':' || curator)` for each of `kn_base_curator_ids()`.
- **`retention`** (once a day): `kn_purge_questions()` (the setting's interval); `question.purge` (`source cron`, `purged`) when any went.
- Each step is a named function returning its counts; `cron.run`'s `after.steps` lists them.

## Files (exactly these)
- `html/index.php` (real) · `html/home/builds.php` · `app/features/home/{queries,present}.php` · `app/views/home/{dashboard,partials/first-run,partials/bases,partials/builds,partials/recent-questions,partials/pins-due,partials/note,partials/gaps,partials/admin}.php`
- `html/admin/index.php` (`admin`) · `html/admin/purge.php` (`admin-purge`, `question_purge`) · `html/admin/model.php` (`admin-model`, `model_config_save`) · `html/admin/usage.php` · `html/admin/bases.php` · `html/admin/keys.php` · `html/admin/agents.php` (`admin-agents`, `agents_settings_save`)
- `app/features/admin/{queries,present,write,worker_steps}.php` (slice 1 made `queries.php` and `write.php`; this slice adds to them) · `app/views/admin/{index,purge,model,usage,bases,keys,agents,partials/base-row,partials/usage-row,partials/agent-card}.php` · slice 1's `app/views/admin/settings.php` — the Purge now link only
- `html/settings/index.php`, `html/settings/prefs.php`, `html/settings/tokens/*.php`, `html/notifications.php`, `html/settings/notifications/read.php` (the kit's — made whole) · `app/views/settings/*`, `app/views/notifications/*`
- `bin/worker.php` — the steps `outbox`, `pins_due`, `limits_near`, `retention`
- `tests/phase3/slice9/{run.sh,lib.php,home.php,admin.php,model.php,usage.php,admin_lists.php,agents.php,prefs.php,outbox.php,steps.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId): array` · `admin_home(PDO): array` · `home_builds(PDO): array` (`mcp_builds` + `kn_build_progress()`) · `home_gaps(PDO, int $memberId): array`
- slice 1's `find_kn_settings(PDO): array` and `save_kn_settings(PDO, string $form, array $fields): array` — `$form` gains `agents` (`expert_member_id`, `archivist_member_id`) and `model` (`model_alias`, `embedding_model`) · `purge_preview(PDO, int $days): int` · `purge_questions(PDO, int $days, int $by): int`
- `model_state(PDO, ?int $baseId): array` (the connection, the base's namespace config, health, the recorded choice) · `save_model_config(PDO, array $fields, bool $rebuildConfirmed, int $by): array` (`['bases_sent', 'bases_failed' => [base => code], 'rebuild_marked']`) · `model_test(PDO, int $sectionId): array`
- `usage_by_base(PDO, string $month, ?int $baseId): array` · `admin_bases(PDO, array $f): array` · `agents_here(PDO): array` · `declared_agents(): array` (from `maludb-os.json`)
- `outbox_pass(PDO, DateTimeImmutable $now, int $limit = 20): array` · `pins_due_pass(PDO, DateTimeImmutable $now): array` · `limits_near_pass(PDO, DateTimeImmutable $now): array` · `retention_pass(PDO, DateTimeImmutable $now): array` · `daily_step_due(PDO, string $step, DateTimeImmutable $now): bool`

## Handlers (every one: `kn_handler_begin()`; `require_right()`; `kn_guard()`; `log_activity`; `emit_action_status()`; `kn_done()`; `HX-Trigger: settingsChanged`)
- `admin/purge.php` (`question_purge`): GET the count, POST with `confirm=1` purges; `question.purge`; **deletion**.
- `admin/model.php` (`model_config_save`): `model_config.save`; **other**. `admin/agents.php` (`agents_settings_save`): `agents_settings.save`; **other**.
- The kit's `settings/prefs.php` (`prefs_save`), `settings/tokens/mint.php` (`token_mint`), `settings/tokens/revoke.php` (`token_revoke`), `settings/notifications/read.php` (`notification_read`): unchanged contracts; this slice completes their screens and the prefs' kinds list.

## Action manifest entries
Screens: `home` (real), `admin`, `admin-purge`, `admin-model`, `admin-usage`, `admin-bases`, `admin-keys`, `admin-agents`, `settings`,
`notifications`, `tokens` (whole). Actions (3 new): `question_purge`, `model_config_save`, `agents_settings_save` (slice 1 owns `settings_save`,
`limits_save`, `retention_set`); the kit's `prefs_save`, `token_mint`, `token_revoke`, `notification_read` stay. Agent approvals (as
`maludb-os.json` lists them): `model_config_save` `other`; `question_purge` `deletion`; `agents_settings_save` `other` (divergence 2).
`PARTIAL_UPDATE_TARGETS` gains nothing.

## Activity log events
`agents_settings.save`, `model_config.save`, `question.purge` (web and cron), `cron.run` (the steps' counts), `screen.view`
(`home`, the admin pages, `settings`, `notifications`, `tokens`), the kit's `prefs.save`, `token.mint|revoke`, `notification.read`.
`base.admin_view` is slice 1's (opening a private base from `admin-bases`). **No notification body, outbox text, email address, secret or token is
in a payload.**

## Notifications this slice queues (and sends)
| Step | Who is told | Kind |
|---|---|---|
| a pin's review date passed | its owner, once per review date | `review_due` |
| a base's month passed the warning percent | the base's curators, once a month | `limit_near` |
| every queued notice of every slice | — | sent by `outbox` (email through MaluMail, text through the kernel's K6) |

## Status vocabulary
Home regions: `home-first-run`, `home-bases`, `home-builds`, `home-recent`, `home-pins`, `home-note`, `home-gaps`, `home-admin`; an empty region in
muted text naming what will appear. Base status chips: empty `secondary`, building `info`, ready `success`, needs_rebuild `warning`. A private base
`feather-lock` `dark`. Usage at ≥ the warning percent `warning`, ≥ 100 % `danger`. Outbox: queued `secondary`, sent `success`, skipped `dark`,
failed `danger`. Ids: `purge-confirm`, `model-form`, `model-test`, `usage-table`, `admin-base-row-{id}`, `admin-key-row-{id}`, `agent-card-{key}`,
`prefs-form`, `token-row-{id}`.

## Out of scope for this slice
The settings, limits, retention and templates screens (slice 1); the slices' own actions linked from the admin pages (keys 7, proposals and
dispatches 8, bases 1, builds 4); hiring, grants, duties and connection approvals (the kernel's); the K24 credential and `kb:0`'s first
configuration (the owner's, `deploy/ROOT_STEPS.sh`); the AI statement's dollars (the kernel's); the MCP servers (Phase 4); per-question cost
shown to the asker (Extended).

## Proof (`tests/phase3/slice9/run.sh`: the scratch database `knowledge_dev9`; the worlds of slices 1–8 composed; ports 8501 the app, 8502 the fake kernel (K6 in each mode), 8503 the fake engine (slice 5's, with `kb:0`'s config), 8506 the fake episodes + MaluMail; `bin/worker.php` with `KN_WORKER_NOW`; headless Chromium at 375 × 740 and 1280 × 800; the registry `--check` reads every screen and action of the manifest as built)
- [ ] **Home (`home.php`)**: a new Member with no base → one button and the three steps, nothing else; a guest with none → "Nothing has been shared
  with you yet."; Priya (Reader of two bases) → her bases, her five recent questions, no curator or admin region (their JSON keys null); Cara
  (curator) → builds running with percent, a pin due, the Archivist's note, the week's gap clusters and thumbs-down count; the admin → usage by base,
  a failed build, a failed dispatch, open proposals; the builds region polls and answers 286 when the build finishes (and `baseChanged`); JSON
  answers the same keys.
- [ ] **The admin index (`admin.php`)**: every card links to its page (slice 1's settings and templates, slices 7's and 8's pages) with its state
  line; a Curator → 403; the registry carries `model_config_save` and `agents_settings_save` `other`, `question_purge` `deletion`.
- [ ] **Purge**: slice 1's Retention form links to it; the page counts the questions older than 30 days (not the pinned one); purging removes them
  with their citations and feedback, the pin keeps its words; `question.purge` logged with the count; the daily `retention` step purges past the
  setting once, not twice the same day.
- [ ] **Model (`model.php`)**: the page shows the connection from `kb:0` (the secret's name, never a value), a base's namespace config, health;
  saving a model id writes `kb:0` with the secret by name and no token (the fake's log), records the choice, then one config call per live base;
  the fake refusing `kb:0` → nothing else changes and the module's sentence shows; refusing one base → the save stands and that base is listed;
  `kb:0` missing → `model_not_configured` in words; an embedding change without the box → 422, with it → every base's sections stale and "needs a
  rebuild"; **Test** shows the compiled prompt with the section filled and the M5 sentence; a Curator → 403.
- [ ] **Usage and the lists (`usage.php`, `admin_lists.php`)**: usage by base and kind for this month and last; the limit bar; a Curator without
  `costs.read` → 403; admin bases list every base with the private ones marked, opening one the admin is not a member of logs `base.admin_view`
  once (slice 1's rule), the list none; admin keys list every key, a revoke from here → 401 on the next API call.
- [ ] **Agents (`agents.php`)**: the two declared agents with the duty "Mondays at 06:45"; the mirror's agents; naming a human as the expert →
  the trigger's words; naming agent 90 → slice 5's "Ask the expert" appears for an evidence-only question; dispatch counts link to slice 8's page.
- [ ] **Prefs, notifications, tokens (`prefs.php`)**: the kinds list is exactly the CHECK's nine; a notification of each kind links to its record;
  mark all read; a token minted shows once with the two MCP URLs; revoke.
- [ ] **The outbox (`outbox.php`)**: an email sent through the fake MaluMail (`provider_ref` stored); no key → `skipped no_mail_key`; a suppressed
  address → `skipped`; `flaky` → retried with backoff, `failed` after five; a text sent through the fake K6; `opted_out` → `skipped opted_out` while
  the email of the same notice is sent; `no_sender` → `skipped`; a 5xx → backoff; two workers → each row sent once.
- [ ] **Daily steps (`steps.php`)**: a pin past review → its owner told once, again only after Verify again moves the date and it passes again; a
  base at 85 % with the warning at 80 → its curators told once this month; each daily step runs once a day and again the next day; `cron.run` lists
  the steps with their counts.
- [ ] **JSON mode (`json.php`)**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; 422 `{error: {code:
  invalid, fields}}`; `question_purge` without `confirm` → 422.
- [ ] **375 × 740 and 1280 × 800**: Home's regions stack in the phone order, the first-run card alone; the admin tables as cards on a phone; the
  model page's Test output wraps; every control ≥ 44 px, `scrollWidth` = viewport, no console errors; **the registry reads every screen and action
  of `docs/knowledge-action-manifest.md` built**.

## Built and proven
Not built.

## Open questions
