# Build spec: the build — sections into the graph (slice 4)

What exists at the end: new sections reach the graph on their own within minutes of being added (auto-extract); a curator sees what a
build would do — how many sections, how many characters, about how many engine calls, against the base's monthly text limit — starts
it, and watches it go section by section; a section whose guidelines changed is forgotten in the engine and extracted again under the
new words; a section that fails is retried, then marked with the engine's reason and left for the next build; a build that would keep
paying for failures stops itself; a curator can cancel; rebuilding everything is a person's decision (an agent's request pauses). The
curators hear when it finishes or fails; the application keeps its own count of the text and calls each base used this month; the
dollars land on the kernel's AI statement through the ledger proxy (K24).
Schema: `builds`, `build_items`, `kn_builds_before()`, `kn_builds_after()`, `kn_build_items_before()`, `kn_build_items_after_row()`,
`kn_build_items_recount()`, `kn_build_queue_items()` (db/009); `sections` (`status`, `extracted_version`, `maludb_document_id`),
`kn_base_recompute_status()` (db/007); `knowledge_bases` (`guideline_version`, `auto_extract`, `monthly_text_mb_limit`, the cached counts,
`last_built_at`), `vocabulary_terms.maludb_subject_id`, `focus_areas.maludb_project_id` (db/006, db/008); `ai_usage`, `kn_usage_add()`,
`kn_notify()`, `kn_base_curator_ids()`, `kn_builds_notify()` (db/012); `kn_build_estimate()`, `kn_build_progress()`, `kn_usage()` (db/013);
`kn_settings.limit_warn_pct`; the views `mcp_builds`, `mcp_build_items`, `mcp_sections`, `mcp_bases`, `mcp_ai_usage`, `mcp_activity_log`
(db/014). The engine: `kn_engine_available()`, `kn_engine_ingest_section()`, `kn_engine_forget_document()`, `kn_engine_seed_subjects()`,
`kn_engine_documents()`, `kn_engine_stats()`, `kn_engine_communities()`; the compile module: `kn_compile_ensure()`, `kn_compile_vocabulary()`,
`kn_compile_section_hints()`, `kn_compile_projects()`, `kn_compile_connection()`, `kn_compile_prompt()`; `kn_area_engine_sync()` (slice 3);
the sectioner's `kn_section_title_for_engine()`, `kn_section_metadata()`. Never modify them. **The database is the referee**: one build per
base at a time; a build records the guideline version it was queued under; an item names a live section of its base; a finished item decides
its section (done → extracted under the build's version, failed → failed with the error) — the worker never sets a section's status by
hand except the one case below; the counters follow the items; a build's status moves forward only; the base's status follows.

## Divergences from the design
1. **No reindex and no embeddings run.** Design §10 lists "reindex and embeddings" in this slice; §0's engine finding supersedes it:
   `reindex` re-extracts with the tenant's generic prompt (never this base's), and the documents route already embeds each edge. The worker
   never calls `kn_engine_reindex()` or `kn_engine_embeddings()`; builds of kind `reindex` or `embed` are never created (the schema keeps the
   kinds); `forget` is base deletion's (slice 1).
2. **An automatic build takes only new sections.** D9: new sources are extracted automatically, rebuilds are a person's.
   `kn_build_queue_items()` takes `queued`, `stale` and `failed` sections — right for a person's build — so the worker's automatic build inserts
   its own items for `queued` sections only.
3. **The prompt's hash has no column.** The build writes it as the first line of `builds.log` (`prompt sha256=<hex> guideline_version=<n>
   terms=<k> areas=<m>`) and in `build.start`'s `after.prompt_sha256`.
4. **Retries happen inside a pass.** `build_items` has no attempts column and a finished item stays finished: a retryable failure is tried up to
   three times in the pass; a failed item's section is `failed` and the next build a person starts takes it again.
5. **The counts are the application's.** The engine's `graph/stats` and `god-nodes` are tenant-wide (§0, M3): `documents_count` is the
   engine's count of the base's documents; `statements_count` and `subjects_count` are computed from the edges the engine returned for the
   base's live sections (`build_items.usage`); `communities_count` is the engine's for the namespace (0 until M6).
6. **`kn_notify()`'s dedupe holds only when an email row is queued** (it checks the outbox). `limit_near` is checked by the worker against
   `notifications` (one per member, base and month) before it calls `kn_notify()`.

## Screens (designed at 1280, works at 375; tables for builds and items; no modals)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `builds` | `/bases/{id}/builds` | curators: **the estimate** card (`kn_build_estimate($base)`: sections to build — new · stale · failed —, characters, "about N engine calls" (`build_call_estimate()`), this month used / limit with a bar, "within the limit" or "stops when the limit is reached: X of Y fits"); **Start the build** (disabled with the reason when nothing is to build, a build runs, the month is spent, or the engine's model is not set up); **Rebuild everything** (its own estimate with kind `rebuild_all`, a confirm with the numbers); a running build's card with its progress (polling, below) and a link; auto-extract on/off as a line linking to base-settings; the builds as a table (`mcp_builds`: started, kind — automatic when `started_by` is null —, by, status chip, done · failed · total, characters, duration, the error's words) |
| `build-view` | `/builds/{id}` | the progress (`kn_build_progress()`: a bar, percent, done · failed · queued · running · skipped, elapsed, the ETA), the guideline version and the prompt hash line (`builds.log` first line), the counts (characters, statements added, subjects added), the usage ("N engine calls, X characters — token counts arrive with MaluDB M2; the dollars are on the business's AI statement"), the error in words, the failed sections with their reasons (each a link to `section-view`), the items as a table (`mcp_build_items`: the heading path → `section-view`, status chip, characters, the error's first line; 200 a page), **Cancel** while queued or running; the log lines in a `<details>`. While queued or running the progress region polls `/builds/{id}/progress` every 5 s while the page is visible (HTMX `every 5s [document.visibilityState=='visible']`); the partial answers **286** with its final content once the build is finished, which stops the polling |

**The error words** (`BUILD_ERRORS` in `app/features/builds/present.php`): `monthly_limit` "This month's text limit was reached — N
sections wait for next month or a higher limit (the admin's)." · `too_many_failures` "Five sections in a row failed — the build stopped so it
would not keep paying for failures. The reasons are below." · `engine_subject_ids` "The engine refused new subjects twice (a known MaluDB
defect, M7) — the build stopped. Tell the administrator." · `model_not_configured` "The engine's model is not set up (the admin's model
screen)." · `engine_unavailable` "The engine could not be reached for 30 minutes." · `every_section_failed` "Every section failed — the
reasons are below." · `vocabulary_failed` "The vocabulary could not be given to the engine." · `stopped_guidelines` (a `done` build's note)
"The guidelines changed during the build — N sections were left for the next one."

## Starting, rebuilding, cancelling
- **Start** (`build_start`, `/builds/start.php`): `require_base_right(base, 'builds.run')`; refusals in order, each a 422 sentence: archived
  base (the trigger's); `kn_compile_connection()` not ok → `model_not_configured`'s words; nothing to build ("Nothing to build — every section is
  in the graph under version N."); a build already queued or running (also the database's unique index → "A build is already running");
  `remaining_chars = 0` → "This month's text limit for this base is spent."; **an agent** (a run token) whose estimate is not `within_limit` →
  "An agent's build must fit this month's limit: it needs X characters, Y remain." A person may start a build that will stop at the limit (the
  card says so). One transaction: INSERT `builds` (`kind` extract; the trigger stamps `guideline_version`, `started_by`), `kn_build_queue_items()`.
  `build.start` (`after`: build_id, kind, sections, characters, guideline_version, prompt_sha256 = `kn_compile_prompt()`'s, within_limit, auto
  false). Location `/builds/{id}`.
- **Rebuild everything** (`build_rebuild_all`, `/builds/rebuild-all.php`): the same with `kind` rebuild_all and `confirm=1`; every live section.
  **other** for agents (`maludb-os.json`).
- **Cancel** (`build_cancel`, `/builds/cancel.php`): `builds.run` on the build's base; queued or running → `cancelled` (the trigger skips the
  remaining items); a finished build → the trigger's words. `build.cancel` (`after`: build_id, done, failed, skipped). The item in flight
  finishes in the engine; the worker records its document on the section (below) and the item stays skipped.

## The worker's build step (`app/knowledge/worker/builds.php`; `bin/worker.php` calls `kn_worker_builds($pdo, $deadline)` after slice 2's steps)
The pass's rules are slice 2's: the advisory lock, the 50 s deadline (no new item past it; the item in hand finishes), base tables only, logs
with `source` cron and `base_id`. For build calls the worker sets `MALUDB_KB_API_TIMEOUT=120` in its own environment (a section of 6,000
characters is about four chunks, each a model call inside one request).
1. **Automatic builds** (`kn_build_auto_queue(PDO $pdo): int`): once a pass and only when `kn_engine_available()` answers, for each base
   with `auto_extract`, not archived, holding a `queued` section, with no live build and this month's text not spent: INSERT `builds` (`kind`
   extract, `started_by` NULL) and `INSERT INTO build_items (build_id, section_id, chars) SELECT …, length(text) FROM sections WHERE base_id = …
   AND status = 'queued'` in one transaction; `build.start` (`after.auto` true). Stale and failed sections are never queued automatically.
2. **The builds**, oldest `requested_at` first, until the deadline; at most 10 items per build per pass (a big build never starves a small
   base's new document):
   - **Prepare** (a `queued` build): `kn_engine_available()` — down → leave it queued (step 4's clock). `kn_compile_ensure($pdo, $base)` — the
     engine's stored prompt must hash to the compiled one, else it is re-sent; `model_not_configured` → the build `failed` with that error.
     The vocabulary: `kn_compile_vocabulary()` → `kn_engine_seed_subjects()` in batches of 500 (no model call), each term's
     `maludb_subject_id` written (the trigger ignores that column), `vocabulary.sync` (`after`: terms, seeded); a failure that is not the
     engine being down → `failed` / `vocabulary_failed`. The areas: `kn_area_engine_sync()` for each active area; a failure is a log line, not a
     stop (the section is sent with the areas that are linked). Then `running`, and `builds.log` = the hash line.
   - **Each item** (in the order of the source's `added_at`, then the section's `position`):
     1. Re-read the build: `cancelled` → stop this build. Re-read the base's `guideline_version`: moved past the build's → stop early: the build
        `done` with the `stopped_guidelines` note (the remaining items are skipped by the trigger; their sections stay as they are for the next
        build), `build.done` with `after.stopped = 'guidelines_changed'`.
     2. **The month**: this month's `ai_usage.chars` of kind extract for the base (`kn_build_month_used()`, a direct query — the curator-gated
        `kn_build_estimate()` answers nothing to the worker) plus the item's characters over the base's limit → the build `failed` /
        `monthly_limit`.
     3. **Claim**: `UPDATE build_items SET status = 'running' WHERE id = :i AND status = 'queued'`; the section re-read — `forgotten` (removed
        meanwhile) → the item `skipped`.
     4. **Forget first**: a section holding a `maludb_document_id` (a stale or failed section built before, or one a cancelled build reached) →
        `kn_engine_forget_document()`; `ok` or `not_found` → `UPDATE sections SET maludb_document_id = NULL WHERE id = :s AND maludb_document_id
        = :old`; the engine down → the item back to `queued`, every build waits for the next pass.
     5. **Ingest**: `kn_engine_ingest_section($namespace, kn_section_title_for_engine(['heading_path' => …], $sourceTitle), $text,
        kn_section_metadata($baseId, $sourceId, $sectionId, ['page_no' => …, 'heading_path' => …, 'position' => …]),
        kn_compile_projects($pdo, $sourceId), kn_compile_section_hints($vocabulary, $seedTerms, $text))`.
     6. **Count every attempt that reached the engine's model** (an answer with an HTTP status, or `engine_timeout`):
        `kn_usage_add($base, 'extract', $calls, 0, 0, $chars)` — `$calls` = `data.chunk_count`, else ⌈characters / 1,800⌉ (the route's 2,000-
        character chunks overlapping by 200); `builds.usage` sums `calls`, `chars`, `attempts`, `retries`, `duplicate_key`.
     7. **Done**: `UPDATE sections SET maludb_document_id = :doc WHERE id = :s` **first, in its own statement** (so a document is never lost,
        even when the item can no longer be finished), then `UPDATE build_items SET status = 'done', usage = {edges, chunks, subjects: the
        distinct subject_text values, at most 200} WHERE id = :i AND status = 'running'` (zero rows when the build was cancelled meanwhile —
        no error; the section keeps its new document and is forgotten-then-ingested by the next build); `builds.statements_added` += edges. Then
        **the one hand-set status**: if the base's version moved during the call, `UPDATE sections SET status = 'stale' WHERE id = :s AND status =
        'extracted' AND extracted_version < :base_version` (the save that moved it marked every other extracted section already).
     8. **Failure** — by the result's `code`:
        | Code | Paid? | What the worker does |
        |---|---|---|
        | `engine_unavailable`, `engine_auth`, `engine_forbidden` | no | the item back to `queued`; every build waits for the next pass; a `waiting: <code>` log line once per change |
        | `engine_timeout` | maybe | **ask the engine first**: the namespace's documents (`kn_engine_documents($ns, 50)`, which its docblock calls newest first) holding the section's exact engine title, created after the attempt began → adopt that id as the section's document and finish the item as done (no second payment); else retry |
        | `model_failed` (the provider's error, prose instead of JSON, the K24 cap refused) | yes | retry up to twice more, after 2 s and 8 s, then the item `failed` |
        | `conflict` whose error or `data.engine_code` says *duplicate key* (§0: the subject ids the API allocates outside the sequence) | yes | retry once at once (the sequence has moved); a second refusal → the item `failed`; the build's second such item → the build `failed` / `engine_subject_ids` |
        | `model_not_configured`, `model_api_key_missing` | no | the build `failed` / `model_not_configured` |
        | `invalid_argument` | no | the item `failed` (the sectioner's caps make this unreachable) |
        | anything else (`validation_failed`, `not_found`, `route_not_found`, `engine_error`) | if a status came back | the item `failed` |
        A failed item: `UPDATE build_items SET status = 'failed', error = <the engine's words, scrubbed> WHERE id = :i AND status = 'running'` (the
        trigger marks the section `failed` with the error). **Five failed items in a row** in one build → the build `failed` / `too_many_failures`.
     9. **Progress**: `build.progress` (`after`: done, failed, total) once per build per pass, and every 25 items.
     10. **The limit warning**: when this month's characters cross `limit_warn_pct` of the base's limit, each curator without a `limit_near`
         notification for this base this month → `kn_notify(…, 'limit_near', 'Knowledge base "X" has used 82% of its monthly text', …, 'base', id,
         'limit:{base}:{YYYY-MM}:{member}')`.
3. **Finish** (`kn_build_finish(PDO $pdo, int $buildId): void`) — no item `queued` or `running` is left: `items_total > 0 AND items_done = 0` →
   `failed` / `every_section_failed`; else `done` (the trigger sets `last_built_at` and tells the curators). Then the base's counts:
   `documents_count` = `kn_engine_stats($ns).data.documents`; `statements_count` and `subjects_count` from the latest done item of every live
   extracted section (`usage.edges` summed, `usage.subjects` distinct); `communities_count` from `kn_engine_communities($ns)`; an engine failure
   here leaves the counts as they were. `builds.subjects_added` = the new `subjects_count` minus the old. `build.done` (`after`: build_id,
   done, failed, skipped, chars, calls, statements_added, subjects_added, seconds, stopped) or `build.fail` (`after`: build_id, error, done, failed).
4. **The clock**: a `queued` or `running` build untouched (`updated_at`) for 30 minutes while the engine answers `engine_unavailable` → `failed`
   / `engine_unavailable` (the curators told once by the trigger; the automatic queue does not start a new one while the engine is down, so
   nothing repeats).

## What stops a runaway build (the guards, all proven)
1. One build per base (the database). 2. The base's monthly text limit, checked before every item and counting every paid attempt, retries
included. 3. Five failures in a row stop the build. 4. A second duplicate-key refusal stops it (M7). 5. At most three attempts per item, with
backoff; a timeout is resolved by asking the engine before paying again. 6. At most 10 items per build per pass and a 50 s pass. 7. The
automatic queue takes new sections only, never when the engine is down or the month is spent. 8. A guideline change mid-build stops it
rather than mixing two prompts. 9. Rebuild-everything pauses for an agent; an agent's build must fit the month. 10. **K24**: the provider's
base URL in `kb:0` is the kernel's ledger proxy (`http://127.0.0.1:8816/openai`) with the application's credential, which carries the owner's
monthly cap — past it the proxy refuses, the engine answers `model_failed`, and guard 3 stops the build. The application never holds a model
key; `ai_usage` is its own count of characters and calls; the tokens and dollars are the kernel's statement's.

## Files (exactly these)
- `html/bases/builds.php` (`builds`) · `html/builds/view.php` (`build-view`) · `progress.php` (the polling partial) · `start.php` ·
  `rebuild-all.php` · `cancel.php`
- `app/features/builds/{queries,present,write,handler}.php` (`handler.php`: `build_from_request()` — the build through `mcp_builds` and its base
  through `base_from_request()` —, `build_log()` with `base_id`)
- `app/knowledge/worker/builds.php` (`kn_worker_builds()`, `kn_build_auto_queue()`, `kn_build_prepare()`, `kn_build_item()`, `kn_build_classify()`,
  `kn_build_finish()`, `kn_build_month_used()`, `kn_build_limit_warn()`) · `bin/worker.php` (the step added)
- `app/views/builds/{index,view,partials/estimate,partials/progress,partials/build-row,partials/item-row}.php`
- `tests/phase3/slice4/{run.sh,lib.php,estimate.php,start.php,worker.php,failures.php,limits.php,cancel.php,auto.php,json.php,browser.mjs}`;
  `tests/phase0/engine/fake_provider.py` gains one marker, `SLOWPROVIDER` (sleep 3 s before answering an extraction) — its other contracts unchanged

## Query functions (signatures fixed)
- `build_estimate(PDO, int $baseId, string $kind = 'extract'): ?array` (`kn_build_estimate()` decoded) · `build_call_estimate(PDO, int $baseId,
  string $kind = 'extract'): int` (`SUM(CEIL(char_count / 1800.0))` over `mcp_sections` of the kind's statuses)
- `find_builds(PDO, int $baseId, int $limit = 50): array` (`mcp_builds`) · `find_build(PDO, int $id): ?array` · `build_progress(PDO, int $id): ?array`
  (`kn_build_progress()`) · `build_items(PDO, int $buildId, int $page = 1, int $per = 200): array` (`mcp_build_items`) · `build_log_lines(PDO, int $id): array`
- `base_month_usage(PDO, int $baseId): array` (`kn_usage()` this month)

## Writes
`start_build(PDO, int $baseId, string $kind, int $by): array` (`['build_id', 'sections', 'characters']`) · `cancel_build(PDO, int $buildId, int $by): array`.
The worker's writes are in `app/knowledge/worker/builds.php` and touch only `builds`, `build_items`, `sections.maludb_document_id` (and the one
stale case), `vocabulary_terms.maludb_subject_id`, `focus_areas.maludb_project_id` (through `kn_area_engine_sync()`), the base's cached counts,
`ai_usage` (through `kn_usage_add()`), `notifications` (through `kn_notify()`), `activity_log`.

## Handlers (every one: `kn_handler_begin()`; `require_base_right($base, 'builds.run')`; `kn_guard()`; `build_log()`; `emit_action_status()` + `kn_done()`; `HX-Trigger: buildsChanged`)
- `builds/start.php` (`build_start`): `build.start`. `builds/rebuild-all.php` (`build_rebuild_all`, **other**): `build.rebuild_all` (the same
  facts). `builds/cancel.php` (`build_cancel`): `build.cancel`.

## Action manifest entries
Screens: `builds`, `build-view`. Actions (3): `build_start`, `build_rebuild_all`, `build_cancel`. Agent approvals: `build_rebuild_all`
(`other`) — reconciled with `maludb-os.json`; `build_start` free under the month's limit (the expert holds it), `build_cancel` free.

## Activity log events (every row: `base_id`; entity `build` unless named)
`build.start|rebuild_all|cancel` (web, agent), `build.start` (cron, `after.auto`), `build.progress|done|fail` (cron), `vocabulary.sync` (cron,
entity base), `screen.view` (`build-view`). **No row carries a section's text, an edge, a subject name or a quote** — ids, counts, characters, a
status, an error code; the engine's error words go to `build_items.error` and `sections.error`, never to the log.

## Notifications
| Step | Who is told | Kind |
|---|---|---|
| a build finishes or fails (`kn_builds_notify()` trigger) | the base's curators | `build_done` / `build_failed` (dedupe `build:{id}:{status}:{member}`) |
| the month crosses the warning | the base's curators, once a month | `limit_near` |
A cancelled build tells nobody. The first finished build's dispatch to the expert ("propose areas and draft guidelines") is slice 8's.

## Status vocabulary
Build: queued `secondary`, running `info`, done `success`, failed `danger`, cancelled `dark`; automatic `light` chip. Item: queued
`secondary`, running `info`, done `success`, failed `danger`, skipped `light`. Ids: `build-estimate`, `build-start`, `build-rebuild-all`,
`builds-table`, `build-row-{id}`, `build-progress`, `build-items`, `item-row-{id}`, `build-cancel`, `build-log`.

## Out of scope for this slice
Asking (5); the graph explorer (6); the expert's dispatch after the first build (8); the admin's usage across bases (9); a per-question cost;
reindex and embedding sweeps (divergence 1); community detection (M6).

## Proof (`tests/phase3/slice4/run.sh`: the scratch database `kn_dev4`, the app on :8501, the fake kernel on :8502, **the real MaluDB API
0.4.0 on a scratch memory database on :8503 with the fake provider on :8504** (`tests/phase3/engine.sh`; `kb:0` configured with the fake
provider's connection), the fake MaluDB + MaluMail on :8506; the worker run by the test (`php bin/worker.php`) pass by pass; headless
Chromium at 375 × 740 and 1280 × 800; the registry and approvals `--check`)
The world: slice 3's "SMOKE Field Manuals" with sources read and cut, vocabulary terms with aliases, the area "SMOKE Warranty" with two
sources; Marco and Priya its curators; a second base for the "a big build never starves" check.
- [ ] **Estimate**: the card's sections, characters and calls equal `kn_build_estimate()` and `SUM(CEIL(n/1800))`; month used 0, limit 50 MB;
  Reid → 403; the start button disabled with each reason in turn (nothing to build, running, spent, no `kb:0`).
- [ ] **Start and the worker**: Priya starts → items for every queued section, `build.start` with the prompt hash; pass 1: the namespace
  configured (`kn_compile_engine_sha()` = the hash), the terms seeded (subject ids written; the fake provider's extract count unchanged by
  seeding), the area linked, the build `running`, ≤ 10 items done; next passes finish it: each section `extracted` at the build's version with a
  `maludb_document_id`; each document in the namespace with `document_type = kb:N`, its metadata naming base, source, section, page (read back
  through `kn_engine_answer()`'s evidence or the scratch database), the Warranty sections tagged with the project, the hints as subject tags;
  `ai_usage` this month = the characters sent and the calls = the chunks; the base `ready`, `last_built_at` set, the counts filled; Marco and
  Priya told `build_done` once; `build.done` with the counts; no section text in any log row.
- [ ] **Stale and forget-then-ingest**: an extraction guideline saved → those sections stale; a build → each stale section's old document
  forgotten (`GET /v1/documents/{old}` 404) and a new one recorded; a guideline saved between two passes of a running build → the build `done`
  with `stopped_guidelines`, the in-flight section `stale` again, the rest left for the next build.
- [ ] **Failures**: a section whose text holds `FAILPROVIDER` → three attempts (`retries` 2) then `failed` with the engine's words on the
  section, the build `done` with one failed; `BADJSON` the same; five such sections in a row → `too_many_failures`, the rest skipped, the
  curators told `build_failed`; the subject sequence rewound in the scratch memory database (the engine proof's `setval`) → a duplicate-key
  refusal retried once, then on a second item the build `failed` / `engine_subject_ids`; every paid attempt counted in `ai_usage`; the API
  stopped mid-build → the item back to `queued`, nothing counted, the build waits; restarted → it resumes; left 31 minutes (`updated_at`
  moved back by the test) → `failed` / `engine_unavailable`, one notification; `kb:0` removed → `model_not_configured`.
- [ ] **Timeouts**: `MALUDB_KB_API_TIMEOUT=1` and a section holding `SLOWPROVIDER` (the fake provider sleeps 3 s) → the attempt times out, the engine stores the document
  anyway, the worker finds it by title and adopts it — one document, no second ingest.
- [ ] **Limits**: the base's limit set to 1 MB with 1.2 MB queued → the build `failed` / `monthly_limit` with the remaining sections still
  queued, the curators told `limit_near` once at 80% and `build_failed` once; the next pass queues no automatic build; an agent's start of a
  build over the remaining → the sentence; a person's start allowed and stopping at the limit; `ai_usage` of last month (written by the test)
  does not count.
- [ ] **Cancel**: cancel a running build between passes → `cancelled`, the remaining items skipped, nobody told, `build.cancel` logged; an item
  in flight when cancelled (a `SLOWPROVIDER` section; the test cancels while the worker waits on it) → its section keeps the new document id and is forgotten then
  re-ingested by the next build; cancelling a finished build → the trigger's words.
- [ ] **Automatic**: with auto-extract on, a new source read and cut → the next pass queues a build of its `queued` sections only (stale ones
  untouched) and logs `build.start` with `auto`; off → nothing queued; a second base's new source is built while the first base's 300-section
  build runs (10 items each per pass); with the engine down no automatic build is queued.
- [ ] **Rebuild everything**: `build_rebuild_all` without `confirm` → refused; with it → every live section forgotten then ingested; the expert's
  request is listed `other` in the registry (the pause is proven in Phase 4).
- [ ] **JSON mode and agents**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; 422 `{error: {code:
  invalid, fields}}`; the expert (curate share, Curator role) starts a build within the limit as `source agent`.
- [ ] **375 × 740 and 1280 × 800**: the estimate card and the start button; build-view's bar moving under polling (requests every 5 s while
  visible, none while hidden, none after the 286); the items table scrolls within the page; the failed sections link to their sections; every
  control ≥ 44 px; no console errors; JavaScript off starts and cancels (a full page, no polling); the registry reads 2 screens and 3 actions of
  this slice built.

## Built and proven
**Built 2026-10-10 by the planning model; proven by `tests/phase3/slice4/run.sh`** — the estimate and the start button's reasons 21, starting 13,
the worker (prepare, the passes, what lands in the engine, the counts, stale sections, a guideline saved mid-build, rebuild everything) 32, failures
and timeouts 29, the monthly limit 12, cancel 12, automatic builds 11, JSON and agents 9, the browser 34 (375 × 740 and 1280 × 800, JavaScript off)
— 173 checks under php -S and under a real Apache against the REAL MaluDB API 0.4.1 on a scratch memory database, plus the registry and no PHP
warning. The registry reads the 2 screens and 3 actions built. Slices 1–3, Phase 2 and Phase 0 stay green; `plan` clean.

Settled in the build:
- **"The month spent" for the automatic queue** means not even the base's smallest queued section fits what is left — with "used ≥ limit" a build
  that stopped at the limit with a few thousand characters left would be queued again every pass, fail again and tell the curators every minute.
- **Cancelling a finished build** is refused before the write with the trigger's own sentence ("A finished build stays cancelled."): re-cancelling
  a cancelled build changes no status, so the trigger would not raise.
- **Cancel lives inside the polled progress region**, so it leaves the page with the 286 when the build finishes.
- The error words take the count of sections left (`{n}` in `BUILD_ERRORS`, from the skipped and queued items).
- `KN_BUILD_BACKOFF=0` in the worker's environment drops the 2 s / 8 s waits — for the five-in-a-row proof only (it would wait 50 s a pass); the
  single-failure proof measures the real backoff (≥ 10 s).
- One file beyond the list: `tests/phase3/slice4/call.php` runs one worker step on its own (prepare, or one item) under the CLI bootstrap, so a
  proof can stop between prepare and the items and call an item while the engine is down.
- The proofs' choices: the limit is proven by writing this month's use near the 1 MB limit rather than queueing 1.2 MB (the same arithmetic, in
  seconds); the 300-section base has sections of at least 200 characters (the sectioner merges smaller ones); the duplicate-key rewind looks for a
  run of taken subject ids (the API's own allocations leave gaps); BADJSON is proven on a one-section base, so that build is also the
  `every_section_failed` proof.
- Slice 3's proof world now turns auto-extract off: with this slice the worker would build the sections that world marks by hand.
- `base_month_usage()` is written to the spec's signature and is read by nothing yet (the admin's usage, slice 9).

## Open questions
