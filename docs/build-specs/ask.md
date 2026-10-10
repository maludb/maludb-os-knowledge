# Build spec: ask — THE SECOND EXEMPLAR (slice 5)

Built by the planning-class model. Every later slice that answers a question, shows a citation or polls for an agent replicates its files,
gates, flow and proof style. This is the application's reason to exist: a Reader on a phone picks a base, asks, and gets either an answer
whose every claim carries a quote that opens the section it came from, or a plain "this base holds nothing on this" that a curator sees as a
gap. **`kn_ask()` is the one function behind every door** — the window (this slice), the token API (slice 7), the records MCP `ask` tool and
the K7 share (Phase 4 / slice 7), the expert's turns — so the gate, the history, the engine call, the mapping to sections, the rows and the
log are written once, here.
Schema: `questions` (+ trigger `kn_questions_before`), `citations` (+ `kn_citations_before`), `question_feedback`, `pinned_answers`
(+ `kn_pinned_answers_after`), `kn_pin_answer()`, `kn_purge_questions()` (db/010); `kn_question_statuses()`, `kn_settings` (`evidence_threshold`,
`max_evidence`, `max_question_chars`, `conversation_turns`, `pin_review_months`), `kn_setting_int()` (db/005); `kn_can_read_base()`,
`kn_can_curate_base()`, `kn_base_level()`, `kn_application_base_ids()` (db/006); `sections.maludb_document_id`, `notes`, `sources` (db/007);
`focus_areas.maludb_project_id`, `vocabulary_terms` (db/008); `kn_key_reaches_base()` (db/011); `agent_dispatches`, `kn_notify()`,
`kn_base_curator_ids()`, `kn_usage_add()` (db/012); `kn_questions()`, `kn_question_gaps()`, `kn_significant_words()` (db/013); the views
`mcp_questions`, `mcp_citations`, `mcp_question_feedback`, `mcp_pinned_answers`, `mcp_bases`, `mcp_focus_areas`, `mcp_sections`,
`mcp_agent_dispatches`, `mcp_activity_log` and `kn_visible_question_ids()` (db/014). Never modify them. Engine: `app/knowledge/engine.php` —
`kn_engine_answer()`, `kn_engine_search()`, `kn_engine_expand_aliases()`, `kn_engine_error_message()` (finished; never edited by this slice).
Compile: `kn_compile_answer_system()` in `app/knowledge/compile.php` (`docs/build-specs/guidelines-compile.md`, slice 3). Sections:
`kn_section_metadata()` (`docs/build-specs/sectioning.md`) is what every engine document carries and what this slice reads back.
**The database is the referee**: a citation names a section of the question's base (the trigger), a key asks only its base (the trigger and
`kn_key_reaches_base()`), one rating per person per question (the unique key), one active pin per question, only an answered question is
pinned (`kn_pin_answer()` refuses a question with no `answer_md`, and an evidence-only one has none: "Only an answered question is pinned"), a
retired pin forgets its note source (the trigger). PHP presents those refusals; it decides
nothing twice.

## Divergences from the design (and the kit prerequisites)
1. **Who the expert is.** "Ask the expert" writes an `agent_dispatches` row (design §5 kind `ask`, via `chat`), whose `agent_member_id` is
   NOT NULL and must be an agent — but nothing in the mirror says *which* agent member is the expert (both shipped agents hold the role `user`,
   and the kernel's chat endpoint resolves `?agent=expert` without saying who answered). **Resolution:** one additive migration, the next free
   number at build time (`db/0NN_agent_members.sql`), appends `kn_settings.expert_member_id` and `kn_settings.archivist_member_id`
   (`bigint REFERENCES members(id) ON DELETE SET NULL`, a trigger refusing a member whose `member_kind` is not `agent`: "The expert is an agent
   member"), adds both to `mcp_settings` (admin-only columns, like `model_alias`), and nothing else. This slice ships it because it needs it
   first; slice 9's Admin › Agents sets it (`agents_settings_save`); the proofs set it in SQL. Until it is set, "Ask the expert" is not shown
   and the answer card says "No expert is named yet — an admin names it under Admin › Agents." Every call then goes to
   `?agent=<expert_member_id>`, so the row and the call always name the same agent.
2. **The expert's reply is not stored here.** `agent_dispatches.reply_excerpt` keeps 200 characters (the canonical table). The full reply is
   the kernel's run: the window reads it with `GET /api/v1/agents/chat.php?run=<run_id>` (the kernel answers a finished run 200, A6), only
   for a dispatch whose `acting_member_id` is the caller (or a curator of the base); when the kernel no longer answers, the excerpt is shown
   with "the full reply is in the OS's run log".
3. **The window waits up to 60 s, not 20 s.** `kn_engine_answer()` uses the module's model timeout (`KN_ENGINE_MODEL_TIMEOUT`, 60 s) and takes
   no per-call timeout. The window's indicator says "Searching the base…" and, after 20 s, "Still composing — a long answer can take up to a
   minute."
4. **`question.search`** is added to the design's event list (§6.4 names no event for an evidence search; the tool surface logs it for
   `/api/v1/search`, slice 7).
5. **Kit prerequisite — the database's own words.** `kn_guard()` / `db_message()` (`app/handler.php`, `app/db.php`) show a RAISE's message only
   for SQLSTATE `P0001`, and turn every `23505` into "That name is already taken." Knowledge's triggers and functions raise with their own
   words under `23514`, `23505`, `23502` and `P0002` (`kn_pin_answer()`: "This answer is already pinned" is `23505`; "Only an answered question
   is pinned" `23514`; "No such question" `P0002`). Before this slice's handlers are written, `db_message()` must return the RAISE's text for
   `P0001`, `P0002`, `23502`, `23505` and `23514` **when the error carries no `constraint "` reference** (a RAISE, not a bare constraint), and
   `kn_guard()` must answer `P0002` as a 404. Slice 1 (`bases-core.md` — "present a `P0001`/`23505`/`23514` as its sentence") owns the fix;
   this slice needs `P0002` too. If it has not landed, this slice makes it, in those two functions only, and the proof's `guard` check covers it.
6. **Kit prerequisite — Markdown.** An answer is Markdown; the kit has no renderer. This slice adds `app/knowledge/markdown.php`
   `kn_markdown_html(string $md): string`: everything escaped first, then a small safe subset — paragraphs, line breaks, `**strong**`, `*em*`,
   `` `code` ``, fenced code, `>` quotes, `-`/`1.` lists, `#`–`###` headings (rendered one level down: `h4`–`h6`), links only `http(s)://`
   with `rel="noopener nofollow"` and `target="_blank"`, and citation markers `[n]` turned into `<a class="cite-ref" href="#cite-{qid}-{n}">`.
   No raw HTML passes. Later slices reuse it (pinned answers, the expert's reply, the guidelines preview).

7. **Three statuses, as the lead ruled on 2026-10-05 (the tool surface follows).** (a) **`evidence_only` stores no words**: `answer_md` is
   NULL in the row and the reply — the passages are the evidence and the citations, nothing is composed — so an evidence-only answer is **not
   pinnable** (`kn_pin_answer()` refuses an empty `answer_md` in its own words) and its card shows no Pin; "Ask the expert" is how it gets words.
   (b) **The fallback also covers a model that cannot answer** (`model_failed`, `model_not_configured`, `model_api_key_missing` →
   `evidence_only` when the passages are there, with the code kept in `questions.error` and the card's note saying why); `failed` is kept for an
   engine that cannot be reached or errs. Until the owner sets the tenant's answer model after the M1 deploy (design §12), every question would
   otherwise fail although the passages are there. (c) **`refused`** means the base or the area is not built yet (step 2: `base_not_built`,
   `area_not_built`), or the engine would not take the question as sent. There is no pre-match of exclusion guidelines against a question (no
   deterministic rule exists for a guideline sentence): guidelines-compile.md carries the answer-time exclusion as the `LEAVE OUT OF ANSWERS`
   block of the `system` string, which the composer obeys.
8. **The engine bridge** (`/internal/bridge.php`, tool surface § The engine bridge) is how the records MCP server reaches `kn_ask()`; this slice
   builds the PHP side and its ops `ask`, `search`, `suggest` and `admin_view` (slices 3, 6 and 7 add theirs); Phase 4 wires the Python side.

## The flow of `kn_ask()` (`app/knowledge/ask.php`)
`kn_ask(PDO $pdo, array $req): array` — `$req`: `base_id` (int), `question` (string), `focus_area_id` (?int), `conversation_id` (?string),
`door` (`web` | `api` | `mcp` | `agent` | `share`), `token_id` (?int, door `api`), `application_key` (?string, door `share`), `is_eval`
(bool — the bridge passes the run's flag). The caller is
whoever `app.member_id` names (a person, an agent under a run token, a key's minter), or no one (the kernel's token on the share door).
Door rule: `web` the window, `api` a key, `mcp` the records server under a person's own token, `agent` the records server under a run token,
`share` K7. Answers `['ok', 'http', 'question_id', 'status', 'answer_md', 'confidence', 'citations', 'evidence', 'conversation_id',
'turn_no', 'error' => ?['code', 'message'], 'message' => ?string, 'dropped' => int]`.

1. **The gate (no row is written when it refuses):**
   - the base exists and `archived_at IS NULL`, and the caller reaches it: `web`/`mcp`/`agent` → `kn_can_read_base(base)`; `api` →
     `kn_key_reaches_base(token, base)` **and** `kn_can_read_base(base)` (the minter's reach still counts: a minter who lost the base shuts the
     key); `share` → `base ∈ kn_application_base_ids(application_key)`. Otherwise `http 404`, "Knowledge base not found." — the same sentence
     whether the base does not exist or is not the caller's.
   - the question, trimmed, is 1 to `min(kn_settings.max_question_chars, 2000)` characters (the engine's own cap is 2,000): else `422`
     `fields.question` "A question is 1 to 2,000 characters." (the number from the setting).
   - the area, when named (slug or id), is a `focus_areas` row of this base with `status = 'active'`: else `422` `fields.focus_area` "That
     focus area is not active in this base." A key bound to an area (`mcp_access_tokens.focus_area_id`) forces it: a request naming another
     area → `403 area_not_allowed` (the tool surface's code).
   - the conversation, when named, continues only if it has questions of this base asked by the same identity (the same
     `asked_by_member_id` for a person or agent, the same `token_id` — or the key it was rotated from — for a key, the same `application_key`
     for a share); **anyone else's id silently starts a new conversation and never reads their turns** (`docs/knowledge-mcp-tool-surface.md`).
     A new conversation's id is `'c_' . bin2hex(random_bytes(12))`; `turn_no` is the conversation's highest plus one.
   - **Rate.** Nothing in `kn_ask()` charges a rate. A key's call is counted once per HTTP request by the kit's `api_rate_limit()`
     (`kn_rate_ok()`, slice 7) before `kn_ask()` runs; **a person (web, mcp), an agent and a sibling are never rate-limited** — the window and
     the MCP doors never call `kn_rate_ok()`, and `api_rate_limit()` returns at once for a session caller.
2. **Can the base answer at all?** No section of the base is `extracted` or `stale` (nothing is in the engine yet) → status `refused`,
   `error.code = base_not_built`. An active area whose `maludb_project_id` is NULL → `refused`, `area_not_built`. (Written and logged — a
   curator should see that people ask an unbuilt base.)
3. **The last turns** — `kn_ask_history(PDO, string $conversationId, array $identity, int $turns): array`: the conversation's last
   `kn_settings.conversation_turns` (3) questions in `turn_no` order, each as `{role: user, content: question}` then `{role: assistant,
   content: answer_md}` — for a turn with no answer (`evidence_only`, `no_evidence`, `unverified`, `refused`, `failed`) the assistant content is the fixed
   sentence "The knowledge base held nothing on this." so the engine knows the turn existed. At most six entries (the engine keeps six).
4. **The words the engine sees** — `kn_engine_expand_aliases($question, kn_compile_vocabulary($pdo, $baseId)['aliases'])` (guidelines-
   compile.md: the alias ⇒ canonical map, conflicts removed). The question stored is the person's own words, never the expanded ones.
5. **The guidelines** — `$compiled = kn_compile_answer_system($pdo, $baseId, $focusAreaId)` (guidelines-compile.md) and `$system =
   $compiled['system']`: the base's active answering, audience and exclusion guidelines, the area's line and the terms, compiled for M1's
   `system` field — at most 8,000 characters by construction (blocks dropped from the end; `$compiled['truncated']` is copied into
   `usage.system_truncated` so a curator can see it on `question-view`). Nothing is added here.
6. **The engine** — `kn_engine_answer($namespace, $expanded, $project, $history, $system, (int) kn_settings.max_evidence,
   (float) kn_settings.evidence_threshold)`, `$namespace` = `knowledge_bases.namespace`, `$project` = the area's `maludb_project_id` (an int when
   all digits, else the string) or null.
7. **The branch on the engine's result:**

| Engine result | Status | What happens |
|---|---|---|
| ok, `status = answered` | `answered` | map citations and evidence (step 8); if **no** citation maps to a section of this base, the answer is withheld: status `unverified`, `error.code = citations_unmapped` |
| ok, `status = no_evidence` | `no_evidence` | no model was called; a **gap** |
| ok, `status = unverified` | `unverified` | `answer_md` is **never stored or returned**; the evidence is; a **gap** |
| `answer_unavailable` (API before 0.4.0, M1 not deployed), `model_failed`, `model_not_configured`, `model_api_key_missing` | `evidence_only` or `no_evidence` | **the fallback**: `kn_search()` (below); `questions.error` keeps the engine code so the screen can say why (divergence 7) |
| `project_not_found` | `refused` | `area_not_built` |
| `invalid_argument`, `validation_failed` | `refused` | `error.code` = the engine's code (the engine would not take the question as sent — never expected, since the gate and the compiler keep every bound; logged so it is seen) |
| `engine_unavailable`, `engine_timeout`, `engine_auth`, `engine_forbidden`, `route_not_found`, `engine_error`, anything else | `failed` | the row keeps the code in `error`; nothing else |

   **`kn_search(PDO, array $req): array`** (same file; the evidence door of slice 7, the K7 `search` share and the fallback): the same gate
   (door, base, query 1–2,000 characters; `focus_area` accepted and echoed in the document but not applied — `recall` has no project filter
   until M3), `kn_engine_search($expanded, $namespace, $limit)` (`limit` 1–20, default 8; 12 as the fallback), the evidence mapped as in step 8,
   answers the `os.knowledge-evidence/1` document. It writes **no question row**. It logs `question.search` only on door `api` (`token_id`,
   `result_count`, `query_excerpt` ≤ 120 characters); a person's or agent's search is a read and logs nothing; a share logs `share.read` (slice 7).
8. **Mapping to sections** — `kn_ask_map_evidence(PDO, int $baseId, array $evidence): array` then `kn_ask_map_citations(PDO, int $baseId,
   array $citations, array $mapped): array`:
   - an evidence item's section: when `document.metadata.base` equals this base and `document.metadata.section` names a `sections` row with this
     `base_id` → that section; when the metadata is absent (the fallback's evidence carries only `document{title}`) or names no row → the
     section whose `maludb_document_id = (string) document_id` in this base, newest live first; when **`metadata.base` names another base** the
     item is **dropped** — its text never reaches the caller, the row or the log; an item whose section cannot be found, or is `forgotten`, is
     dropped too. Every drop is counted in `dropped` (the document's field, kept in `usage.dropped`).
   - each stored evidence item (at most 12): `{n, text (at most 1,500 characters), similarity, chunk_id, document_id, section_id, source_id,
     source_title, source_kind, heading_path, page_no, subject, verb}` — `page_no` from the metadata's `page`, else the section's.
   - a citation rests on the evidence item with its `n` (as the engine proof reads M1), else the item with its `document_id`; it takes that
     item's section; a citation whose item was dropped is not written (the table needs a section) and is counted in `dropped`. Ranks are 1..k
     (at most 12) in the engine's order and are the document's `n`; `quote` verbatim (at most 1,000 characters), `maludb_chunk_id` =
     `chunk_id`, `verified` = the engine's flag.
   - **evidence-only** answers cite every mapped passage: rank = `n`, `quote` = the passage's first 500 characters, `verified = false`, and
     `answer_md` stays **NULL** (divergence 7a): the API, the expert and the window read the passages from `evidence` and the citations;
     nothing is composed, so nothing is pinned.
9. **The rows** — one transaction: `INSERT INTO questions (base_id, focus_area_id, asked_by_member_id, token_id, application_key,
   conversation_id, turn_no, door, question, answer_md, status, confidence, evidence, model, latency_ms, usage, error)` (the trigger fills the
   asker for a key and the `guideline_version`), then `citations`. `usage` = the engine's `usage` (`input_tokens`, `output_tokens`, `calls`)
   plus `fallback` (the code), `dropped`, `system_truncated`. `latency_ms` = the whole of `kn_ask()`. **Under an eval run (`is_eval`) nothing is
   written** — no question, no citations, no usage row — `question_id` is null in the reply, and only `question.ask` is logged with
   `after.eval = true` (the kernel's "an eval run changes nothing" check, tool surface).
10. **Metering** — when the engine reported `usage.calls > 0`: `kn_usage_add(base, 'answer', calls, input_tokens, output_tokens,
    mb_strlen(question))`. The dollars are the kernel's (K24); this is the application's own count.
11. **The log** (one function, `ask_log()` in `app/features/ask/write.php`): `question.ask` then the outcome — `question.answer` (answered,
    evidence_only, failed), `question.no_evidence`, `question.unverified`, `question.refuse`. Both rows: `entity_type = 'question'`,
    `entity_id`, `base_id`, `token_id` (door api); `source` = `web` | `api` | `mcp` | `agent` | `application` (share). `question.ask` `after`
    (the tool surface's list): `question_id`, `door`, `focus_area_id`, `conversation_id`, `turn_no`, `status`, `citation_count`,
    `verified_count`, `latency_ms`, `error_code`, `question_excerpt` (the first 120 characters — the most of a question any log row carries),
    `key_label` (api), `application` (share), `eval` (when true). The outcome row repeats `status`, `citation_count`, `verified_count` and adds
    `model`, `input_tokens`, `output_tokens`. **Never the answer, a quote, an evidence text or more of the question.**
12. **Gaps** are not written anywhere: a `no_evidence` or `unverified` question **is** a gap — `kn_question_gaps()` reads the statuses. Nobody
    is notified per gap (the Archivist reports them weekly — slice 8).
13. **The reply** — `ask_present_turn()` (`app/features/ask/present.php`) builds **the `os.knowledge-answer/1` document exactly as
    `docs/knowledge-mcp-tool-surface.md` defines it** (one citation shape `{n, quote, verified, section: {id, heading_path, source: {id, title,
    kind}, page}, url}`, one evidence shape, `dropped`, `message` = the status words below, `error_code`), with `url` = slice 2's route
    `APP_URL/sections/{id}?quote=<the quote's first 200 characters>`. The window, the API, the MCP `ask` tool and the share all answer this
    one document; `kn_ask()`'s own array adds only `ok` and `http` for the PHP callers.

## Screens (375 px is the design — a Reader asks on a phone; the log, gaps and pinned pages usable at 375, designed at 1280)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `ask` | `/ask?base=&area=&c=&q=` | **the window**: the base picker (the bases `mcp_bases` gives the caller, not archived; remembered in the session), the area chips (Whole base + the base's active areas — a horizontally scrolling row), the conversation `c` (its turns oldest to newest), the composer at the bottom; `q` prefills the composer (the graph's "Ask about this", a suggested question) and never sends by itself; an empty conversation shows the **suggested questions**; "New conversation" and "Conversations" |
| `conversations` | `/ask/conversations?base=` | my conversations: per conversation the base, the first question's first line, the turns, when last asked; tap → `/ask?base=&c=` |
| `question-view` | `/questions/{id}` | one question as its asker or a curator sees it (`mcp_questions`): the question, the status, the answer, the citations, the evidence (open), the feedback (`mcp_question_feedback`), the door (a key's label, an application, an agent chipped), the guideline version and model, latency; Pin / Unpin; a 404 "Question not found." for anyone else |
| `questions` | `/bases/{id}/questions?status=&door=&key=&area=&asker=&since=&q=&page=` | **the base's log** (curators — `questions.review` and `kn_can_curate_base`): a table — when, asker (or key label / application), area, the question's first line, status chip, citations (verified of total), thumbs up/down, pinned; filters; 50 a page (`kn_questions()`); a row → `question-view` |
| `gaps` | `/bases/{id}/gaps?since=` | what the base could not answer (curators): `kn_question_gaps(base, since)` as cards — the cluster word, "9 questions", the newest question as a sample, last asked, the question ids → each `question-view`; **Add a source** (→ slice 2's add screen for the base) and **Ask again** (→ `/ask?base=&q=<sample>`); below, the **thumbs-down** answers since then (`mcp_question_feedback` rating `down` with their questions) with the comment; `since` 7, 30 (default) or 90 days |
| `pinned` | `/pinned?base=&due=` | pinned answers across the bases I read (`mcp_pinned_answers`, status active) as cards: title, base, owner, the answer rendered, verified when, **review due** chip when `review_due`; a curator of its base: **Verify again**, **Unpin**; the note source it became → slice 2's `source-view` |

### The window at 375 px
- **Header**: the base name (a picker that opens a full-page list of my bases — no modal), the area chips under it. A base with no built
  section shows, instead of the composer, "Nothing in this base can be asked yet." and, for a curator, **Add documents** (slice 2's add) —
  the first-run path (create, add documents, ask) never dead-ends.
- **A turn** (`ask-turn` partial, `id="q-{id}"`): the question as a right-aligned bubble; under it the **answer card** whose body depends on the
  status (table below); a footer of controls, each at least 44 px: thumbs up, thumbs down, Pin (curators), the overflow "Open" (→
  `question-view`).
- **Citations** (`ask-citations` partial): the `[n]` markers in the answer link to their chip; the chips under the answer read "1 · Leave ›
  Carry-over · p. 3" (`cite-{qid}-{n}`), a solid border and `feather-check` when `verified`, dashed when not; a chip opens **slice 2's
  `section-view` — `/sections/{section_id}?quote=<the quote's first 200 characters, URL-encoded>&back=<this URL>`** — which already marks
  the first occurrence (`<mark id="quote">`, scrolled to; a whitespace-collapsed match when the exact one fails; "The quoted words are not
  in this section" when neither — `sources-sections.md`). Nothing of `section-view` changes in this slice. The section is the Reader's to
  read (design §3), so the chip needs no further check; a section of a base the Reader cannot see answers slice 2's 404.
- **Show the evidence** (`ask-evidence` partial): a `<details>` (open by default for `unverified` and `evidence_only`) listing each passage —
  its text (the first 600 characters, "more" → the section), a similarity bar, heading path, source title, page; a passage with no section says
  so. The evidence is every Reader's (design §3: that is how an answer is trusted).
- **Thumbs**: up posts at once; down expands an inline comment box beneath the card (no modal) with Send and Skip; the chosen thumb stays
  pressed (`my_rating`); a second press changes the rating.
- **Pin** (curators of the base, `answers.pin`, `answered` only — an `evidence_only` card shows no Pin): expands an inline title field (prefilled with the question's
  first 120 characters) and Pin; afterwards the card shows "Pinned — becomes a source at the next build".
- **Ask the expert** (`evidence_only` only, a named expert): posts; the card shows "The Knowledge Expert is reading the passages…" with a
  spinner (`aria-live="polite"`); the partial polls (below); the reply renders under the evidence with the expert's name chipped "agent" and a
  note "The expert's reply is not checked against the sources the way an answer is."
- **The composer** (`ask-composer`, sticky at the bottom): a textarea (`maxlength` = the gate's limit, a live counter from 1,800 characters),
  Send; Enter sends, Shift-Enter is a newline (a small script); without JavaScript the form posts and the page re-renders with the new turn at
  the bottom (`#q-{id}`).
- **Waiting**: the form posts with `hx-post="/ask/send" hx-target="#ask-turns" hx-swap="beforeend"`, the question bubble is echoed at once
  with "Searching the base…", after 20 s "Still composing — a long answer can take up to a minute."
- **Suggested questions** (`ask-suggestions`): chips that fill the composer.

### What the card shows for each status (`ask_status_words()` — the same words are the document's `message`)
| Status | Card | Controls |
|---|---|---|
| `answered` | the answer (`kn_markdown_html`), the chips, "Show the evidence" | thumbs, Pin, Open |
| `evidence_only` | `info` banner — when `error` is `answer_unavailable`: "This installation cannot compose answers yet, so here are the passages the base holds on your question."; a `model_*` code: "The model could not be reached, so here are the passages the base holds."; then the passages as quote cards with their chips | thumbs, **Ask the expert**, Open (no Pin: nothing was composed) |
| `no_evidence` | muted: "This knowledge base holds nothing on this." / "It has been noted so a curator can add a source."; with an area: **Ask the whole base** (re-asks without the area); a curator: **Add a source** | thumbs, Open |
| `unverified` | `warning`: "An answer was drafted, but its quotes could not be checked against the sources, so it is withheld. Here is what the base holds." — the evidence open | thumbs, Open |
| `refused` | `dark`, the reason: `base_not_built` "This base has no built documents yet." (+ **Add documents** for a curator); `area_not_built` "This focus area is not in the graph yet — it is added at the next build."; an engine code "The knowledge base could not take this question as it was asked." | Open |
| `failed` | `danger`: "The knowledge base could not be asked just now — " + `kn_engine_error_message(code)` | **Try again** (posts the same question as a new turn), Open |

## Conversations, suggestions, feedback, pins, the expert
- **Conversations** are rows of `questions` sharing a `conversation_id` (the window carries `c`); the history is the last three turns
  (step 3). A conversation belongs to one base: changing the base starts a new one. "New conversation" drops `c`.
- **Suggested questions** — `suggested_questions(PDO, int $baseId, ?int $areaId, int $limit = 6): array`: the active areas' `example_questions`
  (the chosen area's first), then the pinned answers' `question_text` of the base (newest first), de-duplicated, at most `$limit` (the records
  MCP `suggested_questions` tool reads the same function in Phase 4). Both sources are Reader-visible views; no other person's question is ever
  suggested.
- **Feedback** (`question_feedback`): the asker or anyone who sees the question (`mcp_questions`) with `answers.rate`; `rating` up|down, an
  optional `comment` (≤ 2,000); an upsert on `(question_id, member_id)`. A **thumbs-down** tells the base's curators once
  (`kn_notify(curator, 'feedback', 'Thumbs down on "<question's first 60 characters>"', <base name>, 'question', qid, 'feedback:' || qid || ':' ||
  curator)` for each `kn_base_curator_ids()`); a change to up tells nobody.
- **Pins** (`kn_pin_answer(question, me, title)`): the base's curator with `answers.pin`; the function writes the note on the base, the `note`
  source (status `sectioning` — slice 2's worker cuts it, slice 4 builds it, so the graph learns what a person vouched for) and the pin with
  `review_until` = today + `pin_review_months`. **Unpin** retires it (`status = 'retired'`, `retired_at`, `retired_by`); the trigger marks the
  note source forgotten and the worker forgets it in the engine. **Verify again** sets `verified_at = now()`, `verified_by`, `review_until` =
  today + `pin_review_months`.
- **Ask the expert** — `ask_expert_start(PDO, int $questionId, int $by): array` (`app/features/ask/expert.php`): the question must be the
  caller's own (or the caller a curator of its base), `evidence_only`, and the expert named; one live dispatch per question (a second press while
  one is `sent` returns that one). It inserts `agent_dispatches (record_type 'question', record_id, agent_member_id = expert_member_id, kind
  'ask', via 'chat', acting_member_id = me, base_id, status 'sent')`, then `kernel_call('POST', '/api/v1/agents/chat.php?agent=' .
  $expertId, ['utterance' => …, 'screen' => 'ask', 'context' => ['application' => app_key(), 'entity' => 'question', 'record_id' => $qid],
  'conversation_id' => 'kq-' . $qid, 'wait' => 15], ['X-Acting-Member: ' . $me], 25)`. The utterance: "A person asked the knowledge base
  «name» (base id N): «the question». The engine had passages but no composed answer (question id Q). Answer from base N only, using the ask and
  search tools, citing the sections; if the base does not cover it, say so." — the question goes to the kernel (that is the point), never into
  a log row. **200 finished** → `status` `answered` (the run `succeeded`), `awaiting_approval` (the run paused), or `failed` (the run failed);
  `run_id`, `request_id`, `reply_excerpt` (200 characters), `answered_at`. **202** → `run_id` kept, status stays `sent`. **403/404/422** →
  `refused` with the kernel's sentence in `detail`. **409** (the expert busy) → `failed` with "The expert is busy — try again in a minute."
  and the button stays. **Unreachable or 5xx** → `failed`, "The kernel is not reachable right now." (the window does not retry; slice 8's worker
  retries only dispatches it made).
- **The poll** — the expert partial carries `hx-get="/questions/{id}/expert-status?dispatch={did}" hx-trigger="every 3s
  [document.visibilityState=='visible']" hx-swap="outerHTML"`; `ask_expert_poll(PDO, int $dispatchId, int $by): array` loads the dispatch through
  `mcp_agent_dispatches` (the caller acting, or a curator), and while `status = 'sent'` with a `run_id` calls `GET
  /api/v1/agents/chat.php?run=<run_id>`: still running → **204** (the partial stays; HTMX keeps polling); finished → the dispatch updated as above
  and the reply rendered (`kn_markdown_html`), the response without the trigger so polling stops. Ten minutes after `created_at` with no finish →
  `failed`, "The expert did not finish." Reloading the page re-renders a finished dispatch by reading the run again (divergence 2).

## Files (exactly these)
- `app/knowledge/ask.php` (`kn_ask()`, `kn_search()`, `kn_ask_history()`, `kn_ask_map_evidence()`, `kn_ask_map_citations()`, `kn_ask_new_conversation_id()`) · `app/knowledge/markdown.php` (`kn_markdown_html()`)
- `html/internal/bridge.php` (the engine bridge — the tool surface's contract: loopback only, the `X-KN-Bridge` HMAC over `ACTIONS_RELAY_KEY`
  with the `kn-bridge:` prefix and ±30 s, a `member` caller resolved and `app.member_id` set or an `application` caller for the share ops only;
  this slice's ops `ask` → `kn_ask()` (door `mcp` or `agent`, `is_eval` passed), `search` → `kn_search()`, `suggest` →
  `suggested_questions()`, `admin_view` → slice 1's `base.admin_view` rule; any other op 404 until its slice adds it) · `app/knowledge/bridge.php`
  (`kn_bridge_verify()`, `kn_bridge_dispatch()`, the op table later slices extend)
- `html/ask/index.php` (`ask`) · `html/ask/send.php` · `html/ask/conversations.php` (`conversations`)
- `html/questions/view.php` (`question-view`) · `html/questions/feedback.php` · `html/questions/pin.php` · `html/questions/expert.php` · `html/questions/expert-status.php`
- `html/bases/questions.php` (`questions`, at `/bases/{id}/questions`) · `html/bases/gaps.php` (`gaps`, at `/bases/{id}/gaps`)
- `html/pinned/index.php` (`pinned`) · `html/pinned/unpin.php` · `html/pinned/review.php`
- `app/features/ask/{queries,present,write,handler,expert}.php` (`handler.php`: load the question through `mcp_questions`, the gate, `ask_log()`)
- `app/views/ask/{index,conversations,partials/turn,partials/answer,partials/citations,partials/evidence,partials/feedback,partials/pin-form,partials/expert,partials/composer,partials/suggestions}.php`
- `app/views/questions/{view,log,gaps,partials/question-row,partials/gap-card,partials/thumbs-down-row}.php` · `app/views/pinned/{index,partials/pin-card}.php`
- `db/0NN_agent_members.sql` (divergence 1 — additive)
- `tests/fake_kb_engine.php` (the fake engine, below) · `tests/phase3/slice5/{run.sh,lib.php,ask.php,map.php,fallback.php,expert.php,feedback.php,pins.php,log.php,gaps.php,visibility.php,json.php,guard.php,bridge.php,browser.mjs}`

## Query functions (signatures fixed)
- `kn_ask(PDO, array $req): array` · `kn_search(PDO, array $req): array` (above)
- `ask_bases(PDO): array` (`mcp_bases`, not archived, name order, with `status`, `section_count`, `my_level`) · `ask_areas(PDO, int $baseId): array` (`mcp_focus_areas`, active) · `suggested_questions(PDO, int $baseId, ?int $areaId, int $limit = 6): array`
- `conversation_turns(PDO, string $conversationId, int $memberId): array` (`mcp_questions` + `mcp_citations`, `turn_no` order; only the caller's own) · `my_conversations(PDO, int $memberId, ?int $baseId, int $limit = 30): array`
- `find_question(PDO, int $id): ?array` (`mcp_questions`; null = not the caller's) · `question_citations(PDO, int $id): array` (`mcp_citations`) · `question_feedback_rows(PDO, int $id): array` (`mcp_question_feedback`)
- `base_questions(PDO, int $baseId, array $f, int $page): array` (`kn_questions()`) · `base_gaps(PDO, int $baseId, string $since): array` (`kn_question_gaps()`) · `thumbs_down(PDO, int $baseId, string $since): array`
- `rate_question(PDO, int $questionId, int $memberId, string $rating, ?string $comment): array` (`['rating', 'changed']`) · `pin_question(PDO, int $questionId, int $by, ?string $title): int` (`kn_pin_answer()`) · `unpin(PDO, int $pinId, int $by): void` · `review_pin(PDO, int $pinId, int $by): void`
- `find_pins(PDO, ?int $baseId, bool $dueOnly): array` (`mcp_pinned_answers`) · `find_pin(PDO, int $id): ?array`
- `ask_expert_start(PDO, int $questionId, int $by): array` · `ask_expert_poll(PDO, int $dispatchId, int $by): array` · `expert_member_id(PDO): ?int`

## Handlers (every one: `kn_handler_begin()`; `require_right()`; the base's reach; `kn_guard()`; `log_activity`; `emit_action_status()`; `kn_done()`)
- `ask/send.php` (`question_ask`): `bases.read`; fields `base`, `question`, `area?`, `c?`; `kn_ask(door web)`; a gate refusal → the field error (422) or 404; any status is a success (`emit_action_status(true, ['did' => <status words>, 'record_id' => qid, 'question_id', 'status', 'conversation_id'])`); HTMX gets the turn partial, a browser lands on `/ask?base=&c=#q-{id}`, JSON gets the reply shape. Logging is `kn_ask()`'s. `HX-Trigger: questionChanged`.
- `questions/feedback.php` (`question_feedback`): `answers.rate`; the question visible (`find_question`); `rating` up|down, `comment` ≤ 2,000; `question.feedback` (`rating`, `has_comment`, `changed` — never the comment); the thumbs-down notice; location `/questions/{id}`. `HX-Trigger: questionChanged`.
- `questions/pin.php` (`answer_pin`): `answers.pin` and `kn_can_curate_base(base)`; `title?` ≤ 300; `kn_pin_answer()` (its words as 422); `question.pin` (`pinned_answer_id`, `source_id`, `note_id`, `review_until`); location `/pinned#pin-{id}`. `HX-Trigger: questionChanged, sourceChanged`.
- `pinned/unpin.php` (`answer_unpin`): `answers.pin` and curate of the pin's base; an active pin; `question.unpin` (`pinned_answer_id`, `source_id`); location `/pinned`. **Not granted to agents** (the expert's Actions grants name `answer_pin` only), so it needs no approval category.
- `pinned/review.php` (`answer_pin_review`): `answers.pin` and curate; `question.pin` with `after.review = true`, the new `review_until`.
- `questions/expert.php` (`question_expert`): `bases.read`; the question the caller's own or curated, `evidence_only`, an expert named (else 422 "No expert is named yet — an admin names it under Admin › Agents."); `ask_expert_start()`; `agent.dispatch` (`dispatch_id`, `agent_member_id`, `kind ask`, `question_id`), then `agent.reply` (`run_id`, `request_id`, `reply_length`, `cost`, `currency`) or `agent.fail` (`detail` ≤ 200) when the first answer is final. People only (`require_human()`): an agent asks the base itself.
- `questions/expert-status.php` (GET, `question-view`'s partial): the dispatch visible; 204 while running; logs `agent.reply` / `agent.fail` once, when it finishes.
- The screens: `ask` `require_right('bases.read')`; `conversations` the same; `question-view` the question visible; `questions` and `gaps` slice 1's `base_from_request()` then `require_base_right($base, 'questions.review')` (the right and a curator's level on the base); `pinned` `bases.read`. `log_screen_view()` on `ask` (`after.base_id`), `question-view`, `questions`, `gaps`, `pinned`.

## Action manifest entries
Screens: `ask`, `conversations`, `question-view`, `questions`, `gaps`, `pinned`. Actions (6): `question_ask`, `question_feedback`, `answer_pin`,
`answer_unpin`, `answer_pin_review`, `question_expert`. Agent approvals: none — asking,
rating and pinning are free for an agent (design §5); `answer_unpin` and `question_expert` are not offered to agents (people-only). An agent
asks through the records MCP `ask` tool (Phase 4), which calls `kn_ask()` with door `agent`; the window's `question_ask` handler is the
command bar's way to open an answer. `PARTIAL_UPDATE_TARGETS` gains nothing (no record here is edited in parts).

## Activity log events (every row: `base_id`; `entity_type` `question`, `pinned_answer` or `agent_dispatch`)
`question.ask`, `question.answer`, `question.no_evidence`, `question.unverified`, `question.refuse` (kn_ask), `question.search` (kn_search,
divergence 4), `question.feedback`, `question.pin` (pin and review), `question.unpin`, `agent.dispatch`, `agent.reply`, `agent.fail`,
`screen.view`. **No row carries an answer, a quote, an evidence passage, a feedback comment, the expert's reply or more than 120 characters of a
question.** A key is never logged (its `token_id` column and its label are).

## Notifications this slice queues (`kn_notify()`; the sender is slice 9's outbox step)
| Step | Who is told | Kind |
|---|---|---|
| a thumbs-down | the base's curators (`kn_base_curator_ids()`), once per question each | `feedback` |

## Status vocabulary
Question status chips: answered `success`, evidence_only `info` ("passages"), no_evidence `secondary` ("nothing on this"), unverified `warning`
("withheld"), refused `dark`, failed `danger`. Citation chip: verified solid + `feather-check`, unverified dashed. Door chips: web none, api
`feather-key` + the key's label, mcp `feather-cpu`, agent the agent chip, share `feather-share-2` + the application. Pin: active `feather-bookmark`,
review due `warning`. Dispatch: sent `secondary` ("working" with a run), answered `success`, awaiting_approval `warning`, refused `dark`, failed
`danger`. Ids: `ask-turns`, `q-{id}`, `answer-{id}`, `cite-{qid}-{n}`, `evidence-{id}`, `thumbs-{id}`, `pin-form-{id}`, `expert-{id}`,
`ask-composer`, `ask-suggestions`, `question-row-{id}`, `gap-{cluster}`, `pin-{id}`.

## Out of scope for this slice
The token API, keys and the K7 shares (slice 7 — they call `kn_ask()`/`kn_search()` as written here); the records MCP `ask`, `search`,
`suggested_questions`, `questions`, `question_gaps` tools (Phase 4, over the same functions); the Archivist's gap report (slice 8); the outbox
sender (slice 9); question retention and `question_purge` (slice 9); extracting the pin's note source (slice 2's worker) and building it (slice 4);
the graph explorer (slice 6). Nothing here calls a model: M1 or the kernel's expert answers.

## Proof (`tests/phase3/slice5/run.sh`: the scratch database `knowledge_dev5`; ports 8501 the app (`php -S … tests/dev_router.php`), 8502 the fake kernel, **8503 the fake engine** (slice 2 runs the real MaluDB API on that port; from this slice on the proofs use the fake), 8506 the fake MaluDB episodes + MaluMail; members through dev hand-offs (`bin/dev_handoff.php`); curl with signed action and run tokens; headless Chromium at 375 × 740 and 1280 × 800 (`browser.mjs`); the registry `--check`)
**The fake engine seam** — the proof never calls a model and never touches the live MaluDB: `MALUDB_KB_API_URL=http://127.0.0.1:8503` and
`MALUDB_KB_API_TOKEN` (a scratch value) are written to `$KN_DEV_ENV`, so `app/knowledge/engine.php` runs **unchanged** against
`tests/fake_kb_engine.php` (`php -S 127.0.0.1:8503 tests/fake_kb_engine.php`). The fake reads `$FAKE_KB_STATE` (JSON the proof rewrites between
steps): `answer_route` (true → `POST /v1/memory/answer` answers; false → 404 `{"detail":"Not Found"}`, which the module maps to
`answer_unavailable`), `answer_mode` (`ok` | `model_502` | `slow` — sleeps past `MALUDB_KB_API_TIMEOUT=3`), `scripts` (a list of `{match: <a word in
the question>, answer: <the M1 reply>}` — the first match wins, no match = `{status: "no_evidence"}`), `recall` (rows for `POST /v1/memory/recall`),
`documents` (`{namespace: [ids]}` for `GET /v1/documents?document_type=eq.<ns>` and the titles); it answers `GET /health`, `GET /v1/whoami`,
`GET /v1/graph/query` (seeds from `recall`), refuses a wrong bearer with 401, and appends every request (method, path, query, body) as one JSON
line to `$FAKE_KB_STATE.log`, so the checks read what the engine was sent. Stopping it is how `engine_unavailable` is proven.
The world (`tests/phase3/slice5/lib.php` `ask_world()`): base **Handbook** (id 1, internal, Cara curator) with source "Staff handbook" and
sections "Handbook › Leave › Carry-over" (p. 3, `maludb_document_id` 501) and "Handbook › Leave › Sick days" (p. 4, 502), both `extracted`; an area
**Leave** (active, `maludb_project_id` 77) with example questions; a vocabulary term "PTO" ⇐ "paid time off"; an answering guideline and an
audience guideline; base **Private notes** (id 2, private, Cara's) with one section (document 601); base **Empty** (id 3, no section); Priya
(Member), Gus (guest, Reader on Handbook only), Cara (Curator), Dee (Member, no share to base 2), the admin, the expert (agent 90) named in
`kn_settings.expert_member_id`.
- [ ] **Ask (`ask.php`)**: Priya asks Handbook "How does carry-over work?" → `answered`, two citations to the two sections (pages 3 and 4,
  verified), `confidence` stored, `model` stored; the fake's log shows `namespace kb:1`, `max_evidence` and `min_similarity` from `kn_settings`,
  `system` equal to `kn_compile_answer_system($pdo, 1, null)['system']` byte for byte, no `project`; with the area → `project 77` and the area's `FOCUS AREA` line in `system`; "paid time off" reaches the engine
  as "paid time off (PTO)" while the row keeps her words; a 2,001-character question → 422 `fields.question`, no row, no engine call; an archived
  base, base 2 and a made-up id → the same 404 sentence, no row; an area of another base and a proposed area → 422; a key bound to Leave naming another area → 403 `area_not_allowed`; base 3 → `refused`
  `base_not_built` (row written, `question.refuse`); an active area with no project id → `refused` `area_not_built`; a compiled answer system that
  was truncated is noted in `usage.system_truncated`.
- [ ] **Conversations**: three more turns in one conversation → the fourth call's `history` holds exactly the last three turns (six entries, the
  `no_evidence` turn's assistant line is the fixed sentence); `turn_no` 1..4; another person naming Priya's `c` → a new conversation (`c_` + 24 hex)
  whose history is empty — her turns never reach the engine for him; a new base drops `c`; `my_conversations` lists hers only.
- [ ] **Mapping (`map.php`)**: a reply whose evidence carries metadata → sections by metadata; a citation whose evidence has no metadata →
  mapped by `maludb_document_id`; an evidence item whose metadata names base 2 → dropped (its text in no response, row or log; `dropped` counts it);
  an item whose section is forgotten → dropped; a citation resting on a dropped item → not in `citations`, counted in `dropped`; a citation
  joined by `document_id` when its `n` names no item; at most 12 evidence items of at most 1,500 characters; an
  `answered` reply none of whose citations map → stored `unverified` with `citations_unmapped` and no `answer_md`; the citation trigger refuses a
  forged insert naming base 2's section.
- [ ] **Statuses and the fallback (`fallback.php`)**: a `no_evidence` script → `no_evidence`, no usage row added; `unverified` → `unverified`,
  `answer_md` NULL in the row and the reply even though the fake sent one; `answer_route false` → `evidence_only` from `kn_engine_search()`
  (recall rows mapped), `error = answer_unavailable`, `answer_md` NULL in the row and the reply, citations rank = n with `verified false`; `model_502` →
  `evidence_only` with `error = model_failed`; recall empty → `no_evidence`; the fake stopped → `failed` `engine_unavailable`, the row written, the
  card's sentence; `slow` → `failed` `engine_timeout`; `kn_usage_add` counted the answered call's tokens and `chars` once.
- [ ] **The log (`log.php`)**: every ask writes `question.ask` + exactly one outcome row with `base_id`, `entity_id`, the door's `source`;
  `question_excerpt` is at most 120 characters of a 1,500-character question; no row's JSON contains the answer, a quote, a passage, the expanded
  question or characters 121+ of the question (a sentinel word placed in each is searched for across `activity_log`); a `kn_search()` call alone
  writes no question; on door `api` it logs `question.search` with the count and the excerpt only; on door `web`/`mcp` it logs nothing.
- [ ] **Feedback and gaps (`feedback.php`, `gaps.php`)**: Priya thumbs-down with a comment → one row, Cara (curator) told once in-app (and an
  outbox email per her prefs), the comment absent from the log; down again changes nothing for Cara; up → `changed`, nobody told; Gus rates his own
  question; Gus cannot rate Priya's (404); `gaps` for Cara shows the `no_evidence` and `unverified` questions clustered ("carry" cluster of 2 when two
  questions share it), the thumbs-down list with the comment; Priya → 403 in words; the `since` filter.
- [ ] **Pins (`pins.php`)**: Cara pins the answered question → the pin, a note on the base, a `note` source in `sectioning` with the question and
  the answer as its text, `review_until` = today + 6 months, `question.pin` logged; pinning again → "This answer is already pinned" (the database's
  words, a 422 — the kit prerequisite); pinning a `no_evidence` question → "Only an answered question is pinned"; an `evidence_only` one → the same words (no
  `answer_md`), and its card shows no Pin; Priya (Member) → 403; Verify again moves `review_until`; Unpin → retired, the note source `forgotten`, `question.unpin`; the
  `pinned` page shows due chips (a pin back-dated past review); `suggested_questions` includes the area's examples then the pin's question.
- [ ] **The expert (`expert.php`)**: on the `evidence_only` question the fake kernel answers 200 finished → the dispatch `answered` with the run id
  and a 200-character excerpt, the chat log shows `?agent=90`, `X-Acting-Member: <Priya>`, the utterance naming base 1 and the question; 202 then
  (state flipped) finished → the poll answers 204 while running, then the reply partial, the dispatch answered once (`agent.reply` once); the reply
  re-rendered on reload from `GET ?run=`; 403 → `refused` with the kernel's sentence; 409 → `failed`, "The expert is busy…"; 500 → `failed`;
  `awaiting_approval` → shown "waiting for a person's approval"; a second press while one is sent returns it; Dee polling Priya's dispatch → 404;
  no expert named → the button hidden and the handler's 422; an answered (not evidence-only) question → 422; ten minutes back-dated → `failed`.
- [ ] **Who sees (`visibility.php`)**: Priya sees her questions only (`question-view` of Dee's → 404); Cara sees every Handbook question and
  every question on base 2 (she owns it); Dee sees no question of base 2 and cannot pick it; Gus sees Handbook only, never base 2 in the picker; the admin sees all;
  `questions` and `gaps` → 403 for Priya; the citation highlight for a citation of a question she cannot see → the section without a mark.
- [ ] **The rate guard**: Priya asks 40 times in one minute through the window → 40 rows, no 429, no `key_usage` row; a key's 31st call in a
  minute is slice 7's proof (named here so the guard is proven from both sides).
- [ ] **The bridge (`bridge.php`)**: a signed `ask` for Priya → the same document as the window with door `mcp` (and `agent` with a `run_id`);
  `is_eval: true` → the document with `question_id: null`, no row, no usage, `question.ask` with `eval: true`; a stale timestamp, a bad HMAC, the
  relay key's actions-prefix signature and a non-loopback address (`REMOTE_ADDR` forged in the dev router is not trusted — the check is the
  server's) → 401; an unknown member → 403 `unknown_member`; an `application` caller on `ask` (not `share_ask`) → refused; an unknown op → 404.
- [ ] **The database's words (`guard.php`)**: through `kn_guard()`, a `23505` RAISE shows its own text (not "That name is already taken."), a
  `23514` its text, a `P0002` a 404, a bare constraint violation the generic sentence.
- [ ] **JSON mode (`json.php`)**: `ask/send.php` under a signed action token answers the reply shape with `{ok, did, record_id, location}`;
  `question_feedback`, `answer_pin`, `answer_pin_review` the same; 422 `{error: {code: invalid, fields}}`; `answer_unpin` and `question_expert`
  refused to an agent token (403, people only).
- [ ] **375 × 740 and 1280 × 800 (`browser.mjs`)**: the base picker and area chips; a question sent → the bubble at once, the answer with two chips;
  a chip opens the section with the quote in slice 2's `<mark id="quote">` scrolled into view and a way back to the conversation; the evidence disclosure;
  thumbs down expands the comment box (no modal); Pin expands the title field; an `evidence_only` turn with Ask the expert → the thinking row, then
  the reply after the fake flips; a `no_evidence` card with "Ask the whole base"; a `failed` card with Try again; the empty base's "Add documents";
  suggested chips fill the composer; JavaScript off: the form posts and the page shows the turn; every control ≥ 44 px, `scrollWidth` = viewport,
  no console errors; the `questions` table and `gaps` cards at 1280 and as cards at 375.

## Built and proven
**Built 2026-10-10 by the planning model; proven by `tests/phase3/slice5/run.sh`** — ask and conversations and the rate guard 28, mapping 10,
statuses and the fallback 11, the expert 20, feedback 10, pins 14, the log 10, gaps and the base's log 11, who sees 9, JSON and agents 10, the
database's words 5, the bridge 14 (15 under Apache: the public vhost's 404 on /internal/), the browser 80 (375 × 740 and 1280 × 800, JavaScript off)
— 231 checks under php -S and 232 under a real Apache, against the fake engine (`tests/fake_kb_engine.php`) and the fake kernel, plus the
registry and no PHP warning. The registry reads the 6 screens and 6 actions built. Slices 1–4, Phase 2 and Phase 0 stay green.

Settled in the build:
- **Divergence 1 had already landed** in db/015 (`kn_settings.expert_member_id` / `archivist_member_id` with the agent-only trigger), so this slice
  adds no migration.
- **The answer's `[n]` markers are renumbered to the citations' ranks** when the row is written (`kn_ask_renumber()`): the engine numbers its
  evidence, the rows are ranked 1..k, and a marker, a chip (`cite-{qid}-{rank}`) and the API's citation `n` must agree. A marker whose passage was
  dropped is removed; no word of the answer changes. The marker is the citation's own `n` (one joined by its document keeps its marker).
- **The cast** of the proof is the fixture's: Cara → Marco (Curator), Gus → Ann (a guest), Dee → Bea (Member), the expert → Seamus (agent 40);
  the scratch database is `kn_dev5`. The world sets the two named sections' documents (501, 502) and statuses in SQL — the fake engine builds nothing.
- **A key's door** is proven through `kn_ask()` in-process (`php_kn()` in the proof's lib): the token API is slice 7's.
- **Window controls swap their turn**: a thumbs, Pin or Ask-the-expert form posted by HTMX with `partial=turn` answers the re-rendered turn
  (`ask_turn_or_done()`); JSON and a browser land as `kn_done()` says. The send handler answers HTMX with the turn and the conversation id out of
  band (`#ask-c`).
- **The base's log and gaps tell a reader why** ("The questions asked of a base are its curators' to review.", 403) instead of the base write
  rule's sentence.
- **Cancelling-style guards on pins**: unpinning or verifying a retired pin is refused ("This answer is no longer pinned.").
- The window's small script is inline in the composer partial (Enter sends, the echo, the counter, the suggestion chips), guarded so a swap
  never binds it twice.
- `tests/fake_kernel.php` gained the chat endpoint's 202 (`state.chat_http`) and `GET ?run=` (`state.chat_run`); the fake engine is freed by its
  port (`fuser`), never by a command-line pattern.

## Open questions
