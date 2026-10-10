# Build spec: agents, proposals and feeds (slice 8)

Built by the worker model from this spec, replicating slice 5's files, gates and proof style (`docs/build-specs/ask.md`; slice 5's
"Ask the expert" is the dispatch exemplar). What exists at the end: when a base's first build finishes, the Knowledge Expert is woken —
one chat turn each — to **propose focus areas** from the graph and **draft guidelines** from a sample, and a curator accepts or dismisses what
it made (slice 3's screens); the **Archivist**'s Monday report arrives as **proposals** a curator accepts (with an effect) or dismisses, and as
one Monday note per base; a base **follows a feed** from a sibling (a Spaces space, Help Desk's published articles) through the kernel's K7
reads, each record becoming a `feed` source keyed by the provider's id; the admin sees every dispatch and retries a failed one. Nothing here
calls a model or holds a key: the kernel runs the agents, MaluDB extracts what a feed brings.
Schema: `feeds` (+ `kn_feeds_before`, `sources_feed_fk`, `kn_feed_items_recount`), `agent_dispatches` (+ `kn_agent_dispatches_before`, the
appended `base_id`, `build_id`, `next_attempt_at`, the queue index on `status = 'sent' AND run_id IS NULL`), `archivist_proposals` (+
`kn_archivist_proposals_before`, the one-open index, `kn_proposals_notify`), `notifications`, `kn_notify()`, `kn_base_curator_ids()` (db/012);
`sources.feed_id`, `feed_item_key`, `url_etag`, `superseded_by`, `forgotten_at` (db/007); `focus_areas` (`status` proposed/active,
`proposed_by`, `proposal_note`), `guidelines` (`is_active`, `origin` agent), `vocabulary_terms` (db/008); `builds` (db/009); `base_templates.area_rule`
(db/005); `kn_question_gaps()`, `kn_unassigned_sources()`, `kn_empty_areas()`, `kn_build_estimate()` (db/013); the views `mcp_feeds`,
`mcp_agent_dispatches`, `mcp_archivist_proposals`, `mcp_sources`, `mcp_focus_areas`, `mcp_pinned_answers`, `mcp_members`, `mcp_templates`,
`mcp_activity_log` (db/014). Never modify them. **The database is the referee**: one open proposal per base, kind and subject; a decided
proposal stays decided; a dispatch goes to an agent; a feed's area is an area of its base; one live source per feed item; the proposal and
share notices are the triggers'. PHP shows the refusals in the database's words.

## Divergences from the design
1. **Who the expert and the Archivist are** — slice 5's migration (`kn_settings.expert_member_id`, `archivist_member_id`) is used here; no
   dispatch is made while the expert is not named (the step logs `agent.fail` once per base: "no expert named").
2. **Report items the schema has no kind or subject for** (design §5 against `archivist_proposals`' CHECKs and the one-open index on
   `(base_id, kind, subject_type, subject_id)`):
   - a **failed build** has no kind and no `build` subject type → it is not a proposal (there is nothing to accept: a curator starts another
     build): it is a line of the Monday note, and `build_failed` already told the curators;
   - a source **without text** → kind `failed_source`, `payload.reason = 'no_text'` (a failed extraction: `payload.reason = 'failed'`);
   - a **thumbs-down answer** → kind `gap`, subject the question, `payload.reason = 'thumbs_down'`;
   - a **gap cluster** has no single subject; its subject is the cluster's **newest question** (so several clusters can be open at once);
   - **duplicate subjects** have no record here → kind `merge` with no subject: **one open merge proposal per base**, `payload.groups[]` holding
     every group; accepting applies the groups the curator ticks;
   - **an area with no source** → kind `unassigned`, subject the area (`focus_area`); **a source in no area** → kind `unassigned`, subject the source;
   - an **area** the Archivist suggests has no subject → one open `area` proposal per base at a time.
   - a **dismissal's reason** has no column → kept as `payload.decision_note` (≤ 500 characters), written with the decision.
3. **A drafted guideline must not shape the graph.** The expert saves its drafts with `guideline_save` as `is_active = false`, `origin = 'agent'`;
   db/008's `kn_guidelines_after()` treats every save of an extraction, vocabulary or exclusion guideline as a graph change — even an inactive one
   — so a draft would mark every section stale. **Resolution:** an additive migration (the next free number) that `CREATE OR REPLACE`s
   `kn_guidelines_after()` so that a save which leaves the guideline inactive (an insert with `is_active = false`, or an update where `OLD.is_active`
   and `NEW.is_active` are both false) records its version but calls no `kn_graph_shape_changed()`; activating a draft (false → true) and deactivating
   an active one (true → false) still shape. If slice 3 (guidelines-compile.md) has already ruled on drafts, its ruling stands and this slice ships
   nothing.
4. **A feed item's version** is kept in `sources.url_etag` (the column says "what the origin answered last time" for a URL; a feed's
   `updated_at`/version is the same fact). No new column.
5. **A 202'd dispatch has no `running` status**: it stays `sent` with its `run_id` set — the queue index (`run_id IS NULL`) skips it, the poll step
   takes it.
6. **`notification.send`** is added to the design's event list for the Archivist's `notification` action (the Monday note).
7. **`source.url_changed`** is added to the design's event list: a URL source whose page changed or vanished has only `url_changed_at` to
   say so (no column tells *changed* from *vanished*), so the weekly check logs which (`after.vanished`, `after.status`) and the Archivist's
   `url_changed` proposal reads it from the source's history.

## Screens (usable at 375 px, designed at 1280 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `proposals` | `/bases/{id}/proposals?status=&kind=` | the Archivist's proposals for a base (`mcp_archivist_proposals`, curators) as cards: the kind chip, title, reason, the subject (→ its screen: a source → slice 2's `source-view`, a question → `question-view`, a pin → `pinned`, an area → slice 3's `area-view`, a feed → `feed-edit`, the base → `base-view`), proposed by and when; the payload shown per kind (a gap: the sample question, the count, the cluster's questions as links; a merge: each group with a tick box, ticked; an area: name, description, seed terms, example questions); **Accept** (the per-kind form) and **Dismiss** (an optional note); tabs proposed / accepted / dismissed |
| `admin-proposals` | `/admin/proposals?base=&status=&kind=` | every base's proposals (the admin curates every base — the same view, a base filter) |
| `feeds` | `/bases/{id}/feeds` | the base's feeds (`mcp_feeds`): provider and tool, the arguments in words ("Space: Product"), area, every N minutes, enabled, last sync and its status chip with the last error, items (live sources); **Follow a feed**, per row **Edit**, **Sync now**, **Delete** |
| `feed-add` / `feed-edit` | `/feeds/new?base=`, `/feeds/{id}/edit` | provider and tool (only the index tools of `maludb-os.json` `reads[]`: `spaces.pages_index`, `helpdesk.articles_published`), the arguments (Spaces: the space's key; Help Desk: an optional category), the area, every N minutes (5–10,080; default 60), enabled |
| `dispatches` | `/admin/dispatches?status=&kind=&base=` | every dispatch (`mcp_agent_dispatches`): agent, kind, base, acting person, status chip, attempts, next attempt, the run id (a link to the OS's run when `agents.settings`), created, answered, the reply's excerpt, the detail; **Retry** on a failed or refused one |

## The expert, woken (the worker's steps — `bin/worker.php` gains them; `app/features/agents/dispatch.php`)
1. **`dispatch_after_build`** — for each base whose **first** `extract` or `rebuild_all` build has just reached `done` (no earlier `done` build of
   that base), whose template's `area_rule = 'communities'` (`mcp_templates`), that has **no** `focus_areas` row at all and **no** `agent_dispatches`
   row of kind `propose_areas`: insert `propose_areas` (`record_type 'base'`, `record_id`, `base_id`, `build_id`, `via 'chat'`, `status 'sent'`,
   `agent_member_id = expert_member_id`, `acting_member_id` = the base's owner when an active human, else the first human of
   `kn_base_curator_ids()`, else no dispatch and `agent.fail` "no person to act for"); and, when every guideline of the base still has
   `origin = 'template'`, a `draft_guidelines` dispatch alike. The worker's advisory lock (`bin/worker.php`) makes it the only writer, and the
   NOT EXISTS check inside the same statement makes a second pass insert nothing — there is no unique index on the table and none is needed.
2. **`dispatches`** — in one transaction: `SELECT … FROM agent_dispatches WHERE status = 'sent' AND run_id IS NULL AND via = 'chat' AND
   next_attempt_at <= now() ORDER BY next_attempt_at LIMIT 5 FOR UPDATE SKIP LOCKED`, and for each `next_attempt_at = now() + interval '15
   minutes'` (the lease — a pass that dies mid-call cannot have it re-run before then); commit; then for each `dispatch_turn()`:
   `kernel_call('POST', '/api/v1/agents/chat.php?agent=' . $agentId, ['utterance' => dispatch_utterance($d), 'screen' => 'base-view',
   'context' => ['application' => app_key(), 'entity' => 'base', 'record_id' => $baseId], 'conversation_id' => 'kd-' . $dispatchId, 'wait' =>
   25], ['X-Acting-Member: ' . $acting], 40)`. The utterances (`dispatch_utterance()`):
   - `propose_areas`: "The first build of the knowledge base «name» (base id N) has finished. Following your runbook propose-focus-areas, read its
     most connected subjects and their neighbourhoods with the graph tools (and MaluDB's communities when it computes them), and propose at
     most eight focus areas with focus_area_propose — each with a name, a one-sentence description, five to twelve seed terms and three
     example questions. Do not activate any."
   - `draft_guidelines`: "The first build of the knowledge base «name» (base id N) has finished. Following your runbook
     draft-guidelines-from-a-sample, read five to eight sections across its sources and save draft guidelines with guideline_save, every one
     with is_active false, at most one of each kind; list the vocabulary terms you would add in your reply instead of saving them. A curator
     will review them."
3. **The answer**: **200** `finished` → the run's `status`: `succeeded` → `answered` (`run_id`, `request_id`, `reply_excerpt` 200 characters,
   `answered_at`); `awaiting_approval` → `awaiting_approval` (`detail` = "waiting for approval request <id>"); `failed` → a **retry**. **202** →
   `run_id`, `request_id` set, `status` stays `sent` (divergence 5). **403, 404, 422** → `refused`, `detail` = the kernel's sentence (≤ 500),
   `answered_at`. **409** (the expert busy — the runner allows one run per agent) and **5xx or unreachable** → a **retry**: `attempts = attempts + 1`,
   `next_attempt_at = now() + (2 ^ attempts) minutes` (2, 4, 8, 16), `detail` = the reason; past **five attempts** → `failed` for good.
4. **`dispatch_poll`** — rows `status = 'sent' AND run_id IS NOT NULL`: `GET /api/v1/agents/chat.php?run=<run_id>`; finished → step 3's
   mapping; still running → nothing; created more than 30 minutes ago and still running → `failed`, "the run did not finish".
5. **After an answered `propose_areas`** the base's curators are told once: `kn_notify(curator, 'proposal', 'The Knowledge Expert proposed N
   focus areas for «name»', NULL, 'base', base, 'areas-proposed:' || dispatch || ':' || curator)` where N counts the base's `focus_areas` with
   `status = 'proposed'` and `proposed_by = expert` created after the dispatch; N = 0 → nobody is told and the dispatch's `detail` says so. An
   answered `draft_guidelines` tells them the same way ("drafted N guidelines").
6. Log `agent.dispatch` (on insert and on retry: `dispatch_id`, `agent_member_id`, `kind`, `base_id`, `build_id`), `agent.reply` (`run_id`,
   `request_id`, `reply_length`, `cost`, `currency`), `agent.fail` (`attempts`, `detail` ≤ 200) — source `cron`; **never the utterance or the
   reply**.
7. **A person can ask for it too** — slice 3's areas screen shows **Propose areas** when this slice's handler exists (guidelines-areas.md,
   divergence 4), and the guidelines screen **Draft guidelines from a sample**: `html/agents/propose-areas.php` (`areas_propose_request`) and
   `html/agents/draft-guidelines.php` (`guidelines_draft_request`) — `require_base_right($base, 'base.manage')`, people only, a base with at
   least one `extracted` section, the expert named; insert the dispatch (acting = the person) unless one of that kind for the base is `sent`
   (then "The expert is already on it."), and land on the base's areas or guidelines with "The Knowledge Expert has been asked — its proposals
   appear here." The worker's `dispatches` step makes the call. `agent.dispatch` (`requested: true`).
8. **`dispatch_retry`** (the admin's action): a `failed` or `refused` dispatch → `status = 'sent'`, `run_id`, `request_id` and `detail` cleared,
   `answered_at` NULL, `next_attempt_at = now()`, `attempts` kept.
**K8** (the kernel waking an agent, K16) is owed: when it lands, step 2's call becomes the wake call (`via 'wake'`); nothing else changes.
What the expert creates is written by its own actions through the kernel's Actions MCP (`focus_area_propose`, `guideline_save`) and appears on
slice 3's screens, where a curator accepts or dismisses an area (`focus_area.accept|dismiss`) and activates or deletes a draft.

## The Archivist's proposals
The Archivist's duty (Monday 06:45, `maludb-os.json`) is **the kernel's schedule**: nothing in the worker wakes it. It reads the report tools
(`docs/knowledge-mcp-tool-surface.md`) and writes with `archivist_propose` and `notification`. Its skills (`monday-base-report`,
`merge-duplicate-subjects`) follow this table, which is the contract between the report and the screens:

| Report item (design §5) | `kind` | Subject (`subject_type`, `subject_id`) | `payload` | **Accept** does |
|---|---|---|---|---|
| a source that failed extraction | `failed_source` | `source` | `{reason: 'failed', error}` (≤ 200) | **Retry** (slice 2's `retry_source()`, the writer behind `source_retry`) |
| a source with no text | `failed_source` | `source` | `{reason: 'no_text'}` | lands on `source-view` (Replace / Remove there — OCR is Extended); accepted |
| sources stale against the guideline version | `stale_source` | `base` (`subject_id` = the base) | `{sections, characters}` | **starts a build** of new and stale sections (slice 4's `start_build($pdo, $base, 'extract', $by)`, under the monthly limit — the estimate shown in the accept form); a build already running → left open with "a build is already running" (a field error — ruled by the planning model 2026-10-10) |
| a URL source whose page changed or vanished | `url_changed` | `source` | `{changed_at, vanished: bool}` | lands on `source-view`'s Replace; accepted |
| a feed that stopped | `feed_stopped` | `feed` | `{last_status, since}` | **Sync now** (`feed_sync`) |
| the month's limit nearly spent | `limit_near` | `base` | `{used_chars, limit_chars, pct}` | lands on slice 1's `base-settings` (the limit); accepted |
| a gap cluster | `gap` | `question` (the newest of the cluster) | `{cluster_key, question_count, question_ids[], sample}` (the sample ≤ 120 characters) | lands on slice 2's add screen for the base; accepted |
| a thumbs-down answer | `gap` | `question` | `{reason: 'thumbs_down', has_comment}` | lands on `question-view`; accepted |
| an unverified answer | `unverified` | `question` | `{}` | lands on `question-view`; accepted |
| a pinned answer past review | `review_pin` | `pinned_answer` | `{review_until}` | **Verify again** (slice 5's `review_pin()`) |
| duplicate subjects | `merge` | none (one open per base) | `{groups: [{names[], suggested}]}` (from slice 6's `duplicate_subjects()`) | **edits the vocabulary**: for each ticked group, the base's term whose `canonical_name` is the chosen canonical (case-insensitive) gains the others as `aliases`, else a new term is made (`canonical_name`, `aliases`, `subject_type` from the group when given) — through slice 3's `save_term()`, so the base is marked for a rebuild and `vocabulary.save` is logged |
| an area with no source | `unassigned` | `focus_area` | `{}` | lands on slice 3's area assignment; accepted |
| a source in no area | `unassigned` | `source` | `{}` | lands on slice 2's `source-view` areas; accepted |
| an area worth adding | `area` | none (one open per base) | `{name, description, seed_terms[], example_questions[]}` | **creates the area** (slice 3's `save_area()` with `status = 'active'`, its MaluDB project made by `kn_area_engine_sync()`) with the payload, editable in the accept form first |

- **`archivist_propose`**: the Archivist holds only the role `user`, so no curator right gates it; the gate is **the base's reach** —
  `kn_can_curate_base(base)` for a person, `kn_can_read_base(base)` for an agent (the Archivist reads every base it is shared with; proposing is
  free, design §5); fields `base`, `kind`, `subject_type?`, `subject_id?`, `title` (≤ 300), `reason`, `payload` (a JSON object ≤ 16 KB); the
  subject must be a record of that base (`mcp_*` lookup by type — a source, question, pin, area or feed of another base → 422 "That subject is not in
  this base."); the one-open index → 422 "Already proposed — an open proposal for this exists."; the trigger notifies the curators (`proposal`).
  `recently_dismissed(PDO, int $baseId, int $days = 90): array` serves the tool that keeps the Archivist from proposing again what a curator
  dismissed within 90 days (its skill says so).
- **`archivist_proposal_accept`**: curators of the base with `questions.review`; a `proposed` row only (the trigger's words for a decided one);
  the effect and the decision in **one transaction** where the effect is a database write (vocabulary, area, pin review); where the effect is a
  build start or a feed sync, the decision is written after the effect succeeds and an effect refused by the database (the limit, a running build)
  leaves the proposal open with the refusal as a field error; a "lands on" effect marks it accepted and redirects there.
- **`archivist_proposal_dismiss`**: the same gate; `status = 'dismissed'`, `payload.decision_note` (divergence 2).
- **`notification`** (the Monday note): the Archivist (or the admin); fields `base`, `title` (≤ 200), `body` (Markdown, ≤ 4,000; the bell shows the
  first 500 — `kn_notify()` truncates it); `kind` is always `monday_note` (any other kind → 422 "Only the Monday note is sent this way."); one
  `kn_notify()` per `kn_base_curator_ids(base)` with the dedupe key `'monday:' || base || ':' || to_char(now(), 'IYYY-IW') || ':' || curator`, so a
  second note the same week tells nobody twice. The note's "failed builds" line is the Archivist's (divergence 2).

## Feeds (Knowledge as a consumer of K7)
- **`feed_save`** (`feeds.manage` + curate): provider and tool from `reads[]`' index tools only (another → 422 "Knowledge does not read that
  tool."); `arguments` a JSON object built from the form's fields; area (the trigger's words when it is not the base's); `every_minutes`; enabled. The
  unique index (one feed per base, provider, tool and arguments) → 422 "This base already follows that." A new feed is due at once.
- **`feed_sync`** (the same gate): `next_sync_at = now()` — the next worker pass syncs it; logs `feed.sync` with `after.requested = true`.
- **`feed_delete`** (the same gate; **deletion** for an agent): the feed's live sources are forgotten first (`forget_feed_source()`, below, so
  the engine forgets what they taught), then the row; logs **`feed.delete`** with the sources' count — added to the design's vocabulary
  (`feed.save|sync|fail`) because the kernel's approval policy matches the log event: a delete logged `feed.save` would pause every
  agent's `feed_save` too.
- **The worker's step `feeds`** (`app/features/feeds/sync.php` `feeds_pass(PDO, DateTimeImmutable $now, int $limit = 5)`): feeds `enabled AND
  next_sync_at <= now()`, oldest first, five a pass:
  1. `kernel_call('POST', '/api/v1/apps/read.php', ['provider' => $provider, 'tool' => $tool, 'arguments' => $arguments], [], 15)` with the
     application's token (Knowledge is the consumer; the kernel checks the approved connection).
  2. **200** → `feed_items($result)` normalises the provider's answer: the list is the envelope the kernel's resolvers accept (`rows`, `items`,
     `results`, `candidates`, or a bare list); per item `id` (from `id`, `uuid`, `page_id`, `article_id`), `title` (`title`, `name`), `version`
     (`updated_at`, `version`, `revised_at`), `body` (`markdown`, `body_md`, `body` — when present). An item without an id or title is skipped and
     counted.
  3. For each item (at most 20 a pass; the rest next pass): no live source with `(feed_id, feed_item_key = id)` → **new**; a live one whose
     `url_etag` (divergence 4) differs from `version` → **changed**; otherwise unchanged. For a new or changed item without a body and a provider
     with a markdown tool (`spaces` → `page_markdown` `{page: id}`), a second K7 read fetches it. The text → `kn_extract_pasted_text($markdown,
     $title)` (sectioning.md) is not called here: a feed source is written like slice 2's pasted text (`add_text_source()`'s shape — slice 2
     leaves feed sources to this slice) by `add_feed_source(PDO, array $feed, array $item, string $markdown): int` — INSERT `sources` (`kind`
     `feed`, `title`, `origin = 'feed:<provider>.<tool>#<id>'`, `feed_id`, `feed_item_key`, `url_etag = version`, `added_by` NULL) and
     `source_text` (`extractor` `feed`, the raw Markdown — slice 2's worker cleans and cuts it as it does pasted text), the feed's area in
     `source_focus_areas`; `source.add` (source `cron`, `after.feed_id`). **Changed** → `forget_feed_source()` on the live one **first** (the unique
     index allows one live source per feed item), then `add_feed_source()`, its areas copied from the old, then the old's `superseded_by` = the new
     (slice 2's replace in this order; `source.replace`, source `cron`); a changed item whose text hash equals the live source's only updates
     `url_etag`. `forget_feed_source(PDO, int $sourceId): void` = `status = 'forgotten'` (the trigger forgets its sections; the next pass forgets
     them in the engine) and slice 2's `wipe_source_content()`; `source.forget` (source `cron`). A person's `source_replace` / `source_remove` on
     a fed source stays refused by slice 2 ("A fed source is replaced by its feed").
  4. **Gone**: when the index answered completely (no paging marker in the answer) and a live source of the feed is not in it → `forget_feed_source()`.
  5. The feed row: `last_sync_at = now()` (the trigger moves `next_sync_at`), `last_status`, `last_error`. **403 `no_connection`** →
     `no_connection` with the kernel's sentence ("No approved connection to that tool — a super-admin approves it in the operating system."); **403
     `not_shared` / `people_restricted`, 422** → `refused`; **502 `provider_failed`, 5xx, unreachable** → `failed`. Nothing else retries sooner:
     the next sync is `every_minutes` later, and the Archivist reports a feed that stays failed (`feed_stopped`).
  6. Log `feed.sync` (`new`, `changed`, `gone`, `unchanged`, `skipped`, `items`) or `feed.fail` (`status`, `code`) — source `cron`, never a title or
  text.
- Help Desk's `articles_published` is owed (HD2) and Spaces' `pages_index` / `page_markdown` exist only when Spaces is installed and the
  connection approved: until then a feed shows `no_connection` or `refused` — never an error page.

## URL change checks (design §2 "URL checks"; slice 2 leaves them here)
The worker's step `url_checks` (`app/features/feeds/urls.php` `url_checks_pass(PDO, DateTimeImmutable $now, int $limit = 10)`): live sources of
kind `url` (`status = 'ready'`) whose `url_checked_at` is NULL or more than 7 days old, oldest first, ten a pass, skipped entirely when
`url_fetch_allowed` is false or `KN_URL_FETCH=off`. Each: slice 2's `kn_fetch_url($origin)` (`app/knowledge/fetch.php` — the same public-address,
size, time and type rules as an add); **ok** → an `etag` equal to `url_etag`, or the extracted text's SHA-256 equal to `text_sha256`
(`kn_extract_url_text()` / `kn_extract_text()` as slice 2 reads it) → unchanged: `url_checked_at = now()`; different → `url_changed_at = now()`,
`url_checked_at = now()`, `source.url_changed` (`vanished: false`); **`fetch_status` 404 or 410, or `fetch_failed` on two checks running** →
`url_changed_at = now()`, `source.url_changed` (`vanished: true`, `status`); a `fetch_timeout` or a 5xx → only `url_checked_at` (tried again next
week). Nothing is replaced automatically: the Archivist proposes (`url_changed`) and a curator replaces from `source-view` (slice 2's `source_replace`).
The bytes fetched for a check are discarded. Source `cron`; never the URL's text.

## Files (exactly these)
- `html/bases/proposals.php` (`proposals`, at `/bases/{id}/proposals`) · `html/proposals/save.php` (`archivist_propose`) · `html/proposals/accept.php` · `html/proposals/dismiss.php` · `html/admin/proposals.php` (`admin-proposals`)
- `html/notifications/send.php` (`notification`)
- `html/bases/feeds.php` (`feeds`, at `/bases/{id}/feeds`) · `html/feeds/form.php` (`feed-add`, `feed-edit`) · `html/feeds/save.php` · `html/feeds/sync.php` · `html/feeds/delete.php`
- `html/admin/dispatches.php` (`dispatches`) · `html/admin/dispatches/retry.php` · `html/agents/propose-areas.php` · `html/agents/draft-guidelines.php`
- `app/features/agents/{dispatch,queries,present}.php` (`dispatch_after_build()`, `dispatches_pass()`, `dispatch_turn()`, `dispatch_poll_pass()`, `dispatch_utterance()`, `retry_dispatch()`) · `app/features/proposals/{queries,present,write,effects}.php` · `app/features/feeds/{queries,present,write,sync}.php`
- `app/views/proposals/{index,partials/card,partials/accept-merge,partials/accept-area,partials/accept-build,partials/dismiss-form}.php` · `app/views/feeds/{index,form,partials/feed-row}.php` · `app/views/admin/{dispatches,proposals,partials/dispatch-row}.php`
- `app/features/feeds/urls.php` (`url_checks_pass()`)
- `bin/worker.php` — the steps `dispatch_after_build`, `dispatches`, `dispatch_poll`, `feeds`, `url_checks`
- `db/0NN_draft_guidelines.sql` (divergence 3, only if slice 3 has not ruled)
- `tests/fake_kernel.php` — gains `POST /api/v1/apps/read.php` answering from `state.reads["<provider>.<tool>"]` (a result, or `{status, code}` for a refusal), appending each request to `<state>.reads`; the chat case's GET `?run=` answers `state.chat` as the POST does
- `tests/phase3/slice8/{run.sh,lib.php,dispatch.php,poll.php,proposals.php,effects.php,note.php,feeds.php,sync.php,urls.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `dispatch_after_build(PDO, DateTimeImmutable $now): array` (`['bases', 'inserted']`) · `dispatches_pass(PDO, DateTimeImmutable $now, int $limit = 5): array` (`['called', 'answered', 'running', 'refused', 'retried', 'failed']`) · `dispatch_poll_pass(PDO, DateTimeImmutable $now): array` · `dispatch_turn(PDO, array $d): array` · `dispatch_utterance(array $d): string` · `retry_dispatch(PDO, int $id, int $by): void` · `find_dispatches(PDO, array $f, int $page): array` · `request_dispatch(PDO, int $baseId, string $kind, int $by): array` (`['dispatch_id', 'existing' => bool]`)
- `find_proposals(PDO, ?int $baseId, array $f): array` · `find_proposal(PDO, int $id): ?array` · `make_proposal(PDO, array $fields, int $by): int` · `accept_proposal(PDO, int $id, array $form, int $by): array` (`['effect', 'land', 'record_id']`) · `dismiss_proposal(PDO, int $id, ?string $note, int $by): void` · `recently_dismissed(PDO, int $baseId, int $days = 90): array`
- `send_monday_note(PDO, int $baseId, string $title, string $body, int $by): int` (recipients told)
- `find_feeds(PDO, int $baseId): array` · `find_feed(PDO, int $id): ?array` · `save_feed(PDO, ?int $id, int $baseId, array $fields, int $by): int` · `request_feed_sync(PDO, int $id, int $by): void` · `delete_feed(PDO, int $id, int $by): array` (`['forgotten']`) · `feeds_pass(PDO, DateTimeImmutable $now, int $limit = 5): array` · `add_feed_source(PDO, array $feed, array $item, string $markdown): int` · `forget_feed_source(PDO, int $sourceId): void` · `feed_items(mixed $result): array` · `feed_tools(): array` (the index tools of `reads[]`)

## Handlers (every one: `kn_handler_begin()`; the right; the base's reach; `kn_guard()`; `log_activity`; `emit_action_status()`; `kn_done()`)
- `proposals/save.php` (`archivist_propose`): the gate above; `archivist.propose` (`proposal_id`, `kind`, `subject_type`, `subject_id`) — never the reason or payload text; `HX-Trigger: proposalChanged`.
- `proposals/accept.php` (`archivist_proposal_accept`): `questions.review` + curate; the effect; `archivist.accept` (`proposal_id`, `kind`, `effect`, the record it made or touched); the effect's own event too (`vocabulary.save`, `focus_area.save`, `build.start`, `feed.sync`, `question.pin`); `HX-Trigger: proposalChanged` (+ the effect's).
- `proposals/dismiss.php` (`archivist_proposal_dismiss`): the same gate; `archivist.dismiss` (`proposal_id`, `kind`, `has_note`).
- `notifications/send.php` (`notification`): the Archivist (any agent reading the base) or `settings.manage`; `notification.send` (`base_id`, `kind`, `recipients`) — never the body.
- `feeds/save.php` (`feed_save`): `feeds.manage` + curate; `feed.save` (`feed_id`, `provider`, `tool`, `focus_area_id`, `every_minutes`, `enabled`); location `/bases/{id}/feeds#feed-{id}`. `feeds/sync.php` (`feed_sync`): `feed.sync` (`requested`). `feeds/delete.php` (`feed_delete`): `feed.delete` (`feed_id`, `forgotten`); **deletion**. `HX-Trigger: feedChanged`.
- `admin/dispatches/retry.php` (`dispatch_retry`): `agents.settings`; `agent.dispatch` (`retry: true`); `HX-Trigger: dispatchChanged`.
- `agents/propose-areas.php` (`areas_propose_request`), `agents/draft-guidelines.php` (`guidelines_draft_request`): `base.manage` on the base, `require_human()`; `agent.dispatch` (`requested: true`, `kind`); `HX-Trigger: areasChanged` / `guidelinesChanged`.
- The screens: `proposals` slice 1's `base_from_request()` then `require_base_right($base, 'questions.review')`; `admin-proposals` and `dispatches` `require_any_right('settings.manage|agents.settings')`; `feeds`, `feed-add` and `feed-edit` `base_from_request()` then `require_base_right($base, 'feeds.manage')`; `log_screen_view()` on each.

## Action manifest entries
Screens: `proposals`, `admin-proposals`, `feeds`, `feed-add`, `feed-edit`, `dispatches`. Actions (10): `archivist_propose`, `archivist_proposal_accept`,
`archivist_proposal_dismiss`, `notification`, `feed_save`, `feed_sync`, `feed_delete`, `dispatch_retry`, `areas_propose_request`,
`guidelines_draft_request`. Agent approvals (as `maludb-os.json`
lists them): `feed_delete` `deletion`; the rest none (design §5: `archivist_proposal_*` and `feed_sync` are an agent's to do alone; the
Archivist's grants are `archivist_propose` and `notification`; `areas_propose_request` and `guidelines_draft_request` are people-only). An accepted proposal's effect is a person's act and is not offered to agents
(the Archivist never accepts). `PARTIAL_UPDATE_TARGETS` gains `/feeds/save.php => ['feeds', 'feed', 'mcp_feeds', 'feed_id']`.

## Activity log events
`agent.dispatch`, `agent.reply`, `agent.fail` (source `cron`, or `web` for a retry), `archivist.propose|accept|dismiss`, `notification.send`
(divergence 6), `feed.save`, `feed.delete`, `feed.sync`, `feed.fail`, `source.url_changed` (divergence 7), `source.add|replace|forget` (the feed's sources, through slice 2's
writers), the accepted effects' own events, `screen.view`. **No utterance, reply, reason, payload text, note body, feed title or fed text is in
a payload.**

## Notifications this slice queues (`kn_notify()`; the sender is slice 9's outbox step)
| Step | Who is told | Kind |
|---|---|---|
| a proposal is made | the base's curators (the trigger) | `proposal` |
| the expert proposed areas / drafted guidelines | the base's curators, once per dispatch | `proposal` |
| the Monday note | the base's curators, once a week | `monday_note` |

## Status vocabulary
Dispatch: sent `secondary` ("queued", or "running" with a run id), answered `success`, awaiting_approval `warning`, refused `dark`, failed
`danger`. Proposal kinds: area `feather-target`, merge `feather-git-merge`, gap `feather-help-circle`, stale_source `feather-refresh-cw`,
failed_source `feather-alert-triangle`, review_pin `feather-bookmark`, unassigned `feather-inbox`, unverified `feather-shield-off`,
feed_stopped `feather-rss`, limit_near `feather-bar-chart-2`, url_changed `feather-link`; status proposed `info`, accepted `success`, dismissed
`secondary`. Feed status: ok `success`, failed `danger`, no_connection `warning`, refused `dark`, never synced `secondary`. Ids: `proposal-{id}`,
`proposal-accept-{id}`, `merge-group-{n}`, `feeds-table`, `feed-{id}`, `feed-form`, `dispatch-row-{id}`.

## Out of scope for this slice
The records MCP report tools and the Actions MCP contract (Phase 4); the Archivist's schedule (the kernel's); the kernel's `message_send` to the
admin's assistant (the Archivist's own kernel tool); K8 wakes (owed); slice 3's area and guideline accept/activate screens (they exist — this
slice only wakes the expert); the outbox sender (slice 9); the K7 shares we provide (slice 7).

## Proof (`tests/phase3/slice8/run.sh`: the scratch database `knowledge_dev8`; ports 8501 the app, 8502 the fake kernel (chat in each state, and `apps/read.php`), 8503 the fake engine (slice 5's), 8506 the fake episodes, 8507 the proof's web server for the URL checks; `bin/worker.php` run with `KN_WORKER_NOW`; curl with signed action and run tokens; headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
The world (`lib.php` `agents_world()`): base **Manuals** (id 1, `general` template, Cara owner, a first build just `done`, template guidelines only);
base **Policies** (id 2, a second build done, areas exist); the expert (agent 90) and the Archivist (agent 91) named in `kn_settings`; Priya (Reader),
the admin; Spaces' `pages_index`/`page_markdown` and Help Desk's `articles_published` answered by the fake kernel per test; a URL source checked 8 days ago.
- [ ] **Woken after the first build (`dispatch.php`)**: one pass inserts `propose_areas` and `draft_guidelines` for Manuals (acting Cara) and nothing
  for Policies; a second pass inserts nothing; the `dispatches` step calls the fake kernel with `?agent=90`, `X-Acting-Member: <Cara>`, the
  utterance naming base 1, `wait 25`; 200 → `answered` with the excerpt; with three proposed areas by agent 90 created after it, Cara told once
  "proposed 3 focus areas", none told when 0; `agent.reply` logged with the cost and no words; no expert named → no dispatch, `agent.fail` once.
- [ ] **Asked by a person**: Cara's **Propose areas** on Policies → a `propose_areas` dispatch acting as Cara, called on the next pass; a second
  press while it is `sent` → "The expert is already on it." and no second row; a base with no extracted section → 422; Priya → 403; the same for
  **Draft guidelines from a sample**.
- [ ] **Polling and retries (`poll.php`)**: 202 → `sent` with `run_id`, the queue skips it, the poll step finishes it on the next pass; 409 →
  `attempts 2`, `next_attempt_at` 4 minutes ahead (`2^2`), skipped until due; 500 five times → `failed`; 403 → `refused` with the kernel's sentence;
  `awaiting_approval` → shown with the request id; a run back-dated 31 minutes → `failed` "the run did not finish"; two workers started together →
  one call (the advisory lock and `SKIP LOCKED` — the fake's chat log has one line); the admin's Retry → `sent`, answered next pass; a Member's
  Retry → 403.
- [ ] **Proposals (`proposals.php`)**: the Archivist (run token + relay) proposes one of every kind of the table with its subject; a second open
  `gap` for the same newest question → 422 "Already proposed…"; two gap clusters with different newest questions both open; a second open `merge`
  → 422; a subject of base 2 on a base-1 proposal → 422; Cara and the admin told (`proposal`); Priya sees no proposal (the view) and gets 403 on the
  page; `recently_dismissed` lists a dismissal of 10 days ago and not one of 100.
- [ ] **Effects (`effects.php`)**: accept `area` → an active area with the payload's terms (slice 3's writer, its engine project made by the fake),
  `archivist.accept` + `focus_area.save`; accept `merge` with one of two groups ticked → the term "Zorblat Pump" gains "Zorblat Pumps" as an alias,
  the base marked for a rebuild (`guideline_version` + 1), the unticked group untouched; accept `stale_source` → a build queued; again while it
  runs → the proposal stays open with "a build is already running"; over the monthly limit → the database's/estimate's words, open; accept
  `review_pin` → `review_until` moved; accept `feed_stopped` → the feed due now; a "lands on" kind → accepted and redirected; deciding twice → the
  trigger's words; dismiss with a note → `payload.decision_note`.
- [ ] **The Monday note (`note.php`)**: the Archivist's `notification` → Cara and the admin told `monday_note` (bell + email per prefs); the same
  week again → nobody told twice; `kind: proposal` → 422; Priya (not an agent, no `settings.manage`) → 403; `notification.send` with the count and
  no body.
- [ ] **Feeds (`feeds.php`, `sync.php`)**: Cara follows Spaces `pages_index` `{space: "product"}` on Manuals with area Warranty → due now; the pass
  reads three pages (two with a body via `page_markdown`), makes three `feed` sources keyed by the page ids with `url_etag` = their `updated_at`,
  the area assigned, `feed.sync` new 3; the next sync with one page's `updated_at` moved → one replace (the old source superseded, forgotten); one
  page gone → forgotten (`source.forget` cron); one changed `updated_at` with identical text → only `url_etag` updated; the fake answering 403
  `no_connection` → `last_status no_connection` with the sentence, the feed page shows it, no error; `not_shared` → `refused`; 502 → `failed`; Help
  Desk `articles_published` with bodies inline → sources without a second read; a tool not in `reads[]` → 422; a duplicate feed → 422; Sync now →
  due; Delete → its sources forgotten first, then the row; `feed_delete` pauses for an agent (the category in the registry).
- [ ] **URL checks (`urls.php`)**: through slice 2's seam (`APP_ENV=dev`, `KN_URL_FETCH_TEST_MAP` naming `docs.example.test` → the proof's web server on :8507, whose
  answers the proof flips), an unchanged page →
  only `url_checked_at`; a changed body → `url_changed_at` and `source.url_changed` `vanished false`; a 404 → `vanished true`; one `fetch_failed`
  → nothing, a second in a row → `vanished true`; a 503 → only `url_checked_at`; a source checked 3 days ago is skipped; fetching turned off →
  the step does nothing; nothing is replaced.
- [ ] **Drafts (`dispatch.php`, divergence 3)**: the expert's `guideline_save` of an inactive extraction draft leaves `guideline_version` and every
  section as they were; Cara activating it bumps the version and marks the sections stale.
- [ ] **JSON mode (`json.php`)**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}` with its facts;
  `_partial=1` on `feed_save` keeps the arguments; 422 `{error: {code: invalid, fields}}`; the Archivist's token may `archivist_propose` and
  `notification` and is refused `archivist_proposal_accept` (403, people only).
- [ ] **375 × 740 and 1280 × 800**: proposal cards with their accept forms (the merge groups' tick boxes, the area's editable fields, the build
  estimate); the feeds table as cards on a phone; the feed form; the dispatches table; every control ≥ 44 px, `scrollWidth` = viewport, no console
  errors; the registry reads the six screens and ten actions built.

## Built and proven
**Built 2026-10-10 by a Sonnet 5.5 worker; proven by `tests/phase3/slice8/run.sh`** — woken after the first build, asked by a person and drafts 35, polling and retries 24,
proposals 48, effects 37, the Monday note 15, feeds 33, the worker's feed sync 41, URL checks 20, JSON and agents 31, the browser 110 (375 × 740 and 1280 × 800, JavaScript off) —
394 checks under php -S and the same 394 under a real Apache, against the fake engine and the fake kernel (`state.reads`, the chat in each state), plus the registry and no PHP
warning. The registry reads the 6 screens and 10 actions built. Slices 1–7, Phase 2 and Phase 0 stay green (see the build's report).

Settled in the build:
- **One additive migration, db/017** (`sources.url_failed_checks`): the URL check calls a page vanished on a 404/410 or on a fetch that failed TWO weekly checks running, and nothing in
  db/001–016 holds a streak between two passes a week apart. db/015 had already shipped divergence 3 (drafts shape nothing) and divergence 1 (the named agents), so nothing else was added.
- **Worker order**: forget · purge · recover · sources · builds · `dispatch_after_build` · `dispatches` · `dispatch_poll` · `feeds` · `url_checks` · key_usage — the spec's order, after the
  slice 2 and 4 steps; a fed source is therefore cut by the NEXT pass's sources step (`kind feed` is read as pasted text now: `kn_worker_read_source()` gained the case).
- **The clock**: `bin/worker.php` reads `KN_WORKER_NOW` (an ISO time) when `APP_ENV` is exactly `dev` and hands `$now` to every step; the steps compare against it (a due dispatch, the 30
  minutes, a feed's next sync, the week of a URL check). Production reads the real clock. The proofs move it to prove the 2^n minutes backoff without waiting.
- **The retry arithmetic**: the first call is attempt 1; a retryable failure sets `attempts + 1` and the next call `2^attempts` minutes later (4, 8, 16, 32 — the proof's "attempts 2, 4
  minutes ahead"); the fifth attempt's failure is final. A new dispatch of `dispatch_after_build` is due at the very pass that inserted it (`next_attempt_at` = the pass's clock).
- **The queue and the poll take `propose_areas` and `draft_guidelines` only**: an `ask` dispatch (slice 5) is called by its own request, and the instant between its insert and the kernel's answer is
  not the worker's to call twice. `retry_dispatch` refuses an `ask` ("goes to the expert again from the question itself"); the screen shows Retry on the two kinds only.
- **`dispatch_after_build` window**: only a first build that finished in the last 7 days wakes the expert (an upgrade must not wake the expert for every old base). The draft request is made in the
  same pass as the areas request, under the same base conditions (no area, no earlier `propose_areas`), and only when every guideline is still the template's and none was drafted. `agent.fail` "no expert named" /
  "no person to act for" is logged once per base and reason.
- **Logging**: `agent.dispatch` on insert, on a person's request (`requested`) and on the admin's retry (`retry`, source web); every failed try is an `agent.fail` (`final` false until the last, `attempts`,
  `detail` ≤ 200); `agent.reply` for answered and awaiting-approval runs (status, run, request, length, cost, currency). A 202'd turn logs nothing until it finishes. A cancelled run fails for good.
- **A person's request that finds one live**: 200 with "The expert is already on it." (`existing` true), not a 422; the notice key `already` is on the areas and guidelines screens.
- **The accept form of a stale_source and a running build**: the spec's table says "accepted with 'a build is already running'", its Accept paragraph and its Proof say the proposal stays open with the refusal as a
  field error — the latter two (agreeing) are built, and **the planning model ruled for them (2026-10-10): accepting a proposal that did nothing would
  tell the curator it was done**; the table's cell is corrected: a running build, the month's limit spent (`remaining_chars` 0) and the model not set up leave it open; nothing waiting for a build any more is accepted with no effect.
  The same softness for a moot subject: a failed_source that is no longer failed and a retired pin are accepted with effect `none`; a deleted feed refuses ("dismiss the proposal").
- **The merge form**: the groups are numbered from 1 (`merge-group-{n}`; `merge-group-{proposal}-{n}` on the admin's list, where several bases' proposals share a page); `groups_sent=1` says the form was
  submitted, so no tick is "tick at least one group", and a call with no `groups` at all (an agent's) merges every group. A group whose aliases are already folded writes nothing.
- **`archivist_propose` checks the subject's type against the kind** (the table: a gap is about a question, a merge about none …) and that it is a record of the base through base tables — the Archivist reads the
  base, not every question in it, so the `mcp_*` views would answer it nothing. The gate is the reach: `kn_can_curate_base` for a person, the base's visibility for an agent.
- **Who is told of a proposal**: `kn_base_curator_ids()` (db/012, unchanged) — the owner and the curate members — so the admin is told when named on the base; the proofs give the admin a curate share on the Manuals.
  The Monday note keeps `kn_notify()`'s outbox dedupe and adds the bell's own week (a person with email off would otherwise be told twice).
- **Feeds**: the followable tools are `FEED_TOOL_SPECS` ∩ `maludb-os.json` `reads[]` (page_markdown is the second read, not a feed); a field named on the form wins over a posted `arguments` (a partial update brings the old object);
  the kernel's answer is `{result, …}` and `feed_items()` accepts `rows · items · results · candidates · pages · articles` or a bare list, and a `next`/`next_cursor`/`cursor`/`has_more`/`more`/`truncated` marker means the index
  is not whole (nothing is forgotten as gone); more than 20 changed items in a pass leave the feed due at the next pass. A new version with the same words only moves `url_etag` (compared against the raw and the cut text's SHA-256).
  A person's `source_remove` of a fed source is refused too ("A fed source is removed by its feed" — slice 2 refused only the replace).
- **A record picker `base`** (`app/pickers.php`, `search_base_candidates()`) serves the admin's proposal and dispatch filters; the feed form's area and the proposals' kind are enumerations (selects).
- **Files beyond the list**: a `handler.php` prelude in each of `app/features/{agents,proposals,feeds}` (the kit's shape), `tests/phase3/slice8/web.php` (the proof's web server on :8505 — :8507 is Apache's internal vhost
  in `KN_APP=apache`), the `Propose areas` / `Draft guidelines from a sample` forms on slice 3's screens, the Feeds and Proposals tabs (curators') in `BASE_TABS`, `/feeds/save.php` in `PARTIAL_UPDATE_TARGETS`.
- **The fakes**: `tests/fake_kernel.php` gained `POST /api/v1/apps/read.php` (`state.reads`) and the chat log's `wait`/`conversation_id`/`screen`; `tests/fake_kb_engine.php` gained `GET /v1/memory/config`
  (`state.config`) and a small stateful project store (`state.projects_fake`) — both off unless a proof switches them on. The proofs' cast: the expert is Seamus (40, the Curator role on the Manuals); the Archivist a second agent
  row, 91 "SMOKE Archivist" (role user, a Reader share on both bases).

## Open questions
