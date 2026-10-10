# Knowledge — CLAUDE.md

This repository is the **Knowledge** application of the MaluDB Business OS (`github.com/maludb/maludb-os-knowledge`, private
until the owner says otherwise; local clone and install path `/srv/apps/knowledge`; catalog key `knowledge`; DNS label
`knowledge`). The kernel is `/var/www` (`maludb-os-core`); read its CLAUDE.md first — the kernel owns identity, the directory,
the agents, approvals and the token ledger; this application owns the business's **knowledge bases**: sources (uploaded
documents, pasted text, fetched pages, sibling feeds) cut into **sections**, guidelines, focus areas, builds into a **MaluDB
knowledge graph**, and the asking of it — an ask-me-anything window that cites or declines, a token API with keys, the kernel's
agents, and K7 shares for the siblings. **The content does not matter** (the owner, 2026-10-05): the product is general. The
fire-code example (laws parsed into provisions, amendments, editions, effective dates) is the Extended module in
`docs/structured-documents-extended.md`, not version 1.

## Read first
1. `docs/knowledge-design.md` — the plan (Phase 0): what MaluDB gives today (§0), what it is and is not (§1), the actors (§2),
   roles and reach (§3), the doors (§4), the agents and what pauses (§5), the memory model (§6 — sectioning, how guidelines and
   areas compile into the engine, `kn_ask()`, the tables, the shared-schema decisions), the question inventory with every tool
   named (§7), the kernel's part, the API and K7 (§8), the screens (§9), the build order (§10), Extended (§11), what the
   kernel, MaluDB and the siblings owe and the state of each (§12), ports and files (§14), the owner's answers D1–D16 and the
   rulings R1–R3 (§15), the state (§16).
2. The plugin `maludb-os-integration` (`~/maludb-os-integration`, skill `os-integration` and its references, `shared-schema.md`
   included). The MaluDB API (`~/maludb-python-api-server`: README, `app/routers/memory.py`, `graph.py`, `projects.py`,
   `agent_memory.py`; **`docs/memory-answer.md` on branch `feat/memory-answer-m1`**, worktree `~/maludb-api-m1`) and
   `~/maludb-core/docs/principal-scoping.md`, `memory-extraction-json-contract.md`.
3. `/srv/apps/helpdesk` and `/srv/apps/consultant_tracking` — the nearest **built** exemplars (kit, slice specs, proof suites,
   `deploy/`); `/srv/apps/spaces/docs/spaces-design.md` — the sibling plan in the same shape.
4. The kernel's `docs/build-specs/kernel-application-model-credentials.md` (K24 — how the engine's model calls are metered).

## Rules that bind here
- `htmx-php-builder` skills govern the PHP (`php-patterns` before any PHP, `design-system` before any markup, `php-session-auth`,
  `mcp-servers`, `chat-actions`, `new-screen`); `os-integration` governs the fit. Where the two conflict, the integration
  contract wins: **no password, no login form, no account, no model key, no Twilio key, no shared tables, no actions server of
  our own, no writes to the directory, no writes to another application** (K7 is reads only).
- **The engine is MaluDB and nothing of it is re-implemented here.** Extraction, embedding, storage, traversal, communities and
  answer composition (M1) are the engine's; this application is files, text extraction, **sectioning**, guidelines and their
  compilation, areas, builds, asking, keys and who-may-read. The MaluDB token (`MALUDB_KB_API_TOKEN`, the knowledge memory
  database's own) is read in ONE file, `app/knowledge/engine.php`; a missing token answers `engine_unavailable`, never a crash.
  PHP never calls a model: answers come from M1 or from the kernel's expert through the chat endpoint.
- **The section is the unit.** A source is cut deterministically (never by a model) into sections by its own structure;
  each section is one MaluDB document whose metadata names `{base, source, section, page}`, so evidence maps back to a
  section and a page, an edited source re-ingests only its own sections, and removing a source forgets exactly what it taught.
- **Every answer cites or declines.** `kn_ask()` is the one function behind the window, the API, the MCP tool, the K7 share and
  the expert; weak evidence is `no_evidence` and a logged gap, a quote the engine cannot verify withholds the answer
  (`unverified`), never a guess. The evidence panel is open to every Reader.
- **Vocabulary: "agent" means an OS AI agent**, always. A *member* is a kernel member mirrored here; a *guest* an external
  holding Reader; a *knowledge base* ("base") a corpus with its template, guidelines, areas and graph; a *source* one
  document, text, page, note or fed record; a *section* one heading-delimited part of a source; a *guideline* one instruction
  of one of five kinds; a *template* a shipped set; a *focus area* a MaluDB project; a *build* one extraction run; a
  *question* one ask; a *key* an API token bound to a base.
- **The checkpoint gate**: no feature PHP until the owner approves the schema + tool surface + action manifest + the compile
  and sectioning specs + slice specs together (Phase 1). Schema changes after that are numbered additive migrations.
  **Never modify a migration.**
- **The database is the referee**: a base's namespace is set once; one build per base at a time; a graph-shaping guideline save
  marks sections stale; a key reaches one base; a rate counter refuses the 31st call; a private base is its members' alone; a
  removed source's sections are forgotten in the engine within the minute. PHP presents refusals as field errors.
- Every state-changing handler: `require_post()` + `verify_csrf()` (an action token stands in) + the right (`require_right()`,
  SQL `kn_has_right()`) + the base's reach (`kn_can_read_base()`, `kn_can_curate_base()`) + `log_activity()` + `emit_action_status()`;
  a create's `location` ends in the record id. **Source, section and question text are never in a log payload** (ids, titles,
  counts, 120 characters of a question at most). A key is never logged.
- The kernel's rules copied here: activity `source` is `web` for the UI, `api` for a key, `agent` under a run token, `cron` for
  the worker; `action` is `entity.verb`; `mcp_*` views are the only thing the read roles see, with `kn_visible_base_ids()`
  computed ONCE per statement (the kernel's db/160 lesson); `app.member_id` is set before any query; an unknown member id is
  refused, never created. A record's history is the activity log.
- Email is MaluMail (optional `MALUMAIL_API_KEY`); texts only through the kernel (K6).
- **Every port env (`APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT`) is in `maludb-os.json` `env.required`** and
  pinned in `config/.env` before the installer's `apply` (8187 / 8835 / 8836). Never put a `config/.env` here before `apply`
  except that port pin (`deploy/ROOT_STEPS.sh` step 0); proofs read `$KN_DEV_ENV`.
- Phone first: the ask window and a section are read at 375 px; the graph explorer is designed at 1280 px and works at 375; no
  modals (the one exception: **the record picker**, `app/picker.php` — a form that chooses a record, a base, a source, a person, uses `form_picker()` / `picker_field()` and a source in `app/pickers.php`, never a `<select>` of every row; an enumeration stays a select; it only chooses and closes, never holds a form); full-page create/edit; cards for named things (bases, sources, areas, pinned answers), tables for the question log,
  builds, keys and admin lists; every name a link, every page a way back. **The first-run path is three steps — create a base,
  add documents, ask — and an empty base's first action is "Add documents".**
- Commit after every finished step on `main`, in the sibling repos' message style (`Knowledge: …`); never push unless the owner
  asks. Smokes and fixtures are named `SMOKE <run>`; proofs run on a scratch database and a scratch namespace and never touch
  the installed application; the installer's `plan` (`php /var/www/bin/app_install.php plan /srv/apps/knowledge`) runs at the
  end of every phase. **No third-party text is committed: fixtures are synthetic.**

## Build order and the handoff (design D16, decided 2026-10-05)

The Spaces division: a **planning-class model** builds what the database enforces, the sectioner against its fixtures, the
engine proof, the specs and the exemplars; a **worker model (Sonnet 5.5)** replicates every other slice from a spec, stopping
and escalating on any ambiguity.

**Done:** K25 (db/170), K24 (db/171), M1 (`POST /v1/memory/answer`, MaluDB API 0.4.0 — merged as PR #18 and live on :8000 since 2026-10-09; the `answer` model is chosen per memory database at install, `PUT /v1/llm/models/answer`); **Phase 0 complete** (schema db/001–015, engine module, sectioner, kit, manifest, agents, skills, deploy); **Phase 1 APPROVED by the owner 2026-10-06** (tool surface, action manifest and registry, the compile and sectioning specs, ten slice specs, `docs/phase1-consistency.md`); **Phase 2 built and proven 2026-10-06** (the placeholders, `tests/phase2/run.sh` — now 198 checks; Phase 0's 92 green; `plan` clean); **slice 1 built and proven 2026-10-09** (bases, templates, sharing, settings — `tests/phase3/slice1/run.sh`, 231 checks under php -S and Apache; the kit's `kn_guard_fields()` names the database's refusal on its field); **slice 2 built and proven 2026-10-09 — THE FIRST EXEMPLAR** (sources, the worker, the fetcher, sections; `tests/phase3/slice2/run.sh`, 192 checks under php -S and Apache against the REAL MaluDB API on a scratch memory database — `tests/phase3/engine.sh`). **slice 3 built and proven 2026-10-09** (guidelines, vocabulary, focus areas, `app/knowledge/compile.php` byte-exact to its golden files; 208 checks; found M12 — the engine refused the template's subject types — fixed upstream in MaluDB API 0.4.1, PR #19, merged and live 2026-10-10). **slice 4 built and proven 2026-10-10** (the build: the estimate, start/rebuild/cancel, the worker's build step with its guards, polling build-view; `tests/phase3/slice4/run.sh`, 173 checks under php -S and Apache against the real engine). **slice 5 built and proven 2026-10-10 — THE SECOND EXEMPLAR** (ask: `kn_ask()` behind every door, the window at 375, citations, gaps, pins, the expert, the bridge's PHP side; `tests/phase3/slice5/run.sh`, 231 checks under php -S and 232 under Apache against the fake engine) — **THE HANDOFF POINT**. **Slices 6–9 built by Sonnet 5.5 workers 2026-10-10, each verified and committed by the planning model — PHASE 3 COMPLETE** (graph 274, API/keys/K7 254–259 with db/016, agents/proposals/feeds 394 with db/017, home/admin 416; 61/61 screens, 62/62 actions). **Next:** Phase 4 (the MCP servers) by a Sonnet 5.5 worker; Phase 5 the owner's. K26 built in the kernel 2026-10-06 (db/173): the headers `X-OS-Consumer` / `X-OS-Consumer-Agent` and `shares[].timeout_seconds` (ask and search declare 60).

**Before the handoff:** (1) **Phase 0, second half**: `db/001`–`0NN`, the read functions and `mcp_*` views,
`db/proof/phase0_proof.sql`, the kit from Consultant Tracking proven without a kernel, the sectioner's fixtures,
`tests/phase0/engine.sh` against the live MaluDB API on a scratch memory database, `maludb-os.json`, `os/{expert,archivist}.md`,
the skills, `deploy/` with `ROOT_STEPS.sh` (ports, then the memory database, its token and the K24 credential); (2) **Phase 1**
for the owner's approval; (3) **Phase 2**, **slice 1**; (4) **slice 2 (sources and sectioning) — THE FIRST EXEMPLAR**, slices 3
and 4, then **slice 5 (ask) — THE SECOND**, each proven at 375 and 1280.

**The handoff point** = the commit after slice 5 with design §16 saying "exemplars built; slices 6–9 open to workers".

**After the handoff (worker model):** slices 6 → 9 in order, one commit each; then **Phase 4** (the two MCP servers over the same
query functions and engine module; `app_roles` and the three `shares[]` admitted to the kernel's token; the registry wrapper;
the agents' grants and the Archivist's duty — Help Desk's `tests/phase4/run.sh` is the pattern), and **Phase 5** with the owner.

**A worker's rules:** read this file, design §3, §5, §6 (and the compile and sectioning specs), §15 and the slice's spec before
touching a file; reads through `mcp_*` views, the read functions and `engine.php`; writes to base tables after the gate; the
database's refusal shown as a field error, never re-implemented; a question the spec does not answer stops the slice and is
written into the spec's "Open questions" for the owner — never guessed.

## Environment facts
- Install path `/srv/apps/knowledge`; record database `subello_knowledge` (roles `knowledge_rw`, `knowledge_records_ro`,
  `knowledge_activity_ro`) and memory database `subello_knowledge_memory` (`maludb_core` 0.106.0, schema/role `knowledge_mem`)
  on the office's PostgreSQL 17 — neither created yet. MaluDB API 0.3.1 live on `127.0.0.1:8000`; 0.4.0 (M1) on its branch.
- Vhost `knowledge.subello.com`; ports to pin: `APP_INTERNAL_PORT=8187`, `MCP_RECORDS_PORT=8835`, `MCP_ACTIVITY_PORT=8836`
  (the block after Spaces' 8186/8833/8834). Proof scratch ports: 8501–8507.
- `pdftotext` (poppler-utils) and PHP's `zip`, `xml`, `gd` are installed; tesseract is not (OCR is Extended).
- pgaudit logs every MaluDB query and the root disk has filled once: settle it before Phase 5.
- Nothing is installed yet; `plan` only, never `apply`, from this clone (the owner runs `apply`).
