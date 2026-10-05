# Knowledge — CLAUDE.md

This repository is the **Knowledge** application of the MaluDB Business OS (`github.com/maludb/maludb-os-knowledge`, private
until the owner says otherwise; local clone and install path `/srv/apps/knowledge`; catalog key `knowledge`; DNS label
`knowledge`). The kernel is `/var/www` (`maludb-os-core`); read its CLAUDE.md first — the kernel owns identity, the directory,
the agents, approvals and the token ledger; this application owns the business's **knowledge bases**: sources (uploaded
documents, pasted text, fetched pages, sibling feeds), **for a code the provision tree with its definitions, references,
amendments and effective dates**, guidelines, focus areas, builds into a **MaluDB knowledge graph**, and the asking of it —
an ask-me-anything window that quotes or declines, a token API with keys, the kernel's agents, and K7 shares for the siblings.
**A general product, with the regulatory template built first** (the owner, 2026-10-05); the proof corpus is the fire codes
of the City of Chicago (IFC 2018 as Title 14F, with Title 15) and of Cook County (IFC 2021 with Chapter 102), with the NFPA
standards they reference.

## Read first
1. `docs/knowledge-design.md` — the plan (Phase 0): the proof corpus and what a code is (§0.0–0.1), the field and what MaluDB
   gives today (§0.2–0.6), what it is and is not (§1), the actors (§2), roles and reach (§3), the doors (§4), the agents and
   what pauses (§5), the memory model (§6 — the parsers and the effective provision, how guidelines and areas compile into
   the engine, `kn_ask()`, the tables, the shared-schema decisions), the question inventory with every tool named (§7), the
   kernel's part, the API and K7 (§8), the screens (§9), the build order (§10), Extended (§11), what the kernel, MaluDB and
   the siblings owe (§12 — K24, K25, M1–M4, HD2), **the twenty decisions (§13), answered 2026-10-05 as D1–D20 (§15)**, ports and files (§14),
   the owner's answers (§15 — A1–A3 and D1–D20), the state (§16).
2. The plugin `maludb-os-integration` (`~/maludb-os-integration`, skill `os-integration` and its references, `shared-schema.md`
   included). The MaluDB API (`~/maludb-python-api-server`: README, `app/routers/memory.py`, `graph.py`, `projects.py`,
   `agent_memory.py`, `attributes.py`) and `~/maludb-core/docs/principal-scoping.md`, `memory-extraction-json-contract.md`.
3. `/srv/apps/helpdesk` and `/srv/apps/consultant_tracking` — the nearest **built** exemplars (kit, slice specs, proof suites,
   `deploy/`); `/srv/apps/spaces/docs/spaces-design.md` — the sibling plan in the same shape.

## Rules that bind here
- `htmx-php-builder` skills govern the PHP (`php-patterns` before any PHP, `design-system` before any markup, `php-session-auth`,
  `mcp-servers`, `chat-actions`, `new-screen`); `os-integration` governs the fit. Where the two conflict, the integration
  contract wins: **no password, no login form, no account, no model key, no Twilio key, no shared tables, no actions server of
  our own, no writes to the directory, no writes to another application** (K7 is reads only).
- **The engine is MaluDB and nothing of it is re-implemented here.** Extraction, embedding, storage, traversal, communities and
  (M1) answer composition are the engine's; this application is files, text extraction, **the code parsers**, guidelines and
  their compilation, areas, builds, asking, keys and who-may-read. The MaluDB token (`MALUDB_KB_API_TOKEN`, the knowledge
  memory database's own) is read in ONE file, `app/knowledge/engine.php`; a missing token answers `engine_unavailable`, never
  a crash. PHP never calls a model: answers come from M1 or from the kernel's expert through the chat endpoint.
- **The provision is the unit.** For a code, every numbered provision is its own row, citation and MaluDB document; **the
  graph learns effective provisions only** (`kn_effective_provision(base, number, as_of)` — confirmed overrides applied by
  layer within their dates); a deleted or not-adopted provision is never ingested; the original of an amended section stays
  readable and is never quoted as current. A plain document's unit is its section.
- **A parser is deterministic PHP** (`app/knowledge/parse/<family>.php`: `icc`, `nfpa`, `municipal_title`, `ordinance_sections`,
  `cfr`, `document`) — patterns and a numbering state machine, never a model; it emits the tree, the defined terms, the
  references and, for an amendment, **proposed overrides with a confidence that a curator confirms**. Each family has a fixture
  in `tests/parsers/` — **short excerpts only; no licensed code text is ever committed**; the real corpus lives in `storage/`.
- **Every answer quotes or declines.** `kn_ask()` is the one function behind the window, the API, the MCP tool, the K7 share
  and the expert; a regulatory answer quotes the provision verbatim with number, edition and jurisdiction, lists exceptions,
  flags amendments, keeps thresholds exact, asks for missing facts (`needs_facts`), ends with the AHJ line; weak evidence is
  `no_evidence` and a logged gap, never a guess. The evidence panel is open to every Reader.
- **Vocabulary: "agent" means an OS AI agent**, always. A *member* is a kernel member mirrored here; a *guest* an external
  holding Reader; a *knowledge base* ("base") a corpus with its template, guidelines, areas, graph and, for a code, its
  jurisdiction and edition; a *source* one document with a *role* (model code, amendment, own chapter, standard, document) and
  a *layer*; a *provision* one numbered unit; an *amendment* a source whose provisions change another's; a *guideline* one
  instruction of one of five kinds; a *template* a shipped set; a *focus area* a MaluDB project; a *build* one extraction run;
  a *question* one ask as of a date; a *key* an API token bound to a base.
- **The checkpoint gate**: no feature PHP until the owner approves the schema + tool surface + action manifest + the compile
  and parser specs + slice specs together (Phase 1). Schema changes after that are numbered additive migrations. **Never
  modify a migration.**
- **The database is the referee**: a base's namespace is set once; one build per base at a time; a graph-shaping guideline save
  or an amendment decision marks provisions stale; a key reaches one base; a rate counter refuses the 31st call; a private
  base is its members' alone; a removed source is forgotten in the engine within the minute. PHP presents refusals as field
  errors.
- Every state-changing handler: `require_post()` + `verify_csrf()` (an action token stands in) + the right (`require_right()`,
  SQL `kn_has_right()`) + the base's reach (`kn_can_read_base()`, `kn_can_curate_base()`) + `log_activity()` + `emit_action_status()`;
  a create's `location` ends in the record id. **Source, provision and question text are never in a log payload** (ids,
  numbers, titles, counts, 120 characters of a question at most). A key is never logged.
- The kernel's rules copied here: activity `source` is `web` for the UI, `api` for a key, `agent` under a run token, `cron` for
  the worker; `action` is `entity.verb`; `mcp_*` views are the only thing the read roles see, with `kn_visible_base_ids()`
  computed ONCE per statement (the kernel's db/160 lesson); `app.member_id` is set before any query; an unknown member id is
  refused, never created. A record's history is the activity log.
- Email is MaluMail (optional `MALUMAIL_API_KEY`); texts only through the kernel (K6).
- **Every port env (`APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT`) is in `maludb-os.json` `env.required`** and
  pinned in `config/.env` before the installer's `apply` (8187 / 8835 / 8836 — design §13.16). Never put a `config/.env` here
  before `apply` except that port pin (`deploy/ROOT_STEPS.sh` step 0); proofs read `$KN_DEV_ENV`.
- Phone first: the ask window and a provision are read at 375 px; the code tree and the graph explorer are designed at 1280 px
  and work at 375; no modals; full-page create/edit; cards for named things (bases, sources, areas, pinned answers), tables
  for the code tree, the question log, builds, keys, amendments and admin lists; every name a link, every page a way back.
- Commit after every finished step on `main`, in the sibling repos' message style (`Knowledge: …`); never push unless the owner
  asks. Smokes and fixtures are named `SMOKE <run>`; proofs run on a scratch database and a scratch namespace and never touch
  the installed application; the installer's `plan` (`php /var/www/bin/app_install.php plan /srv/apps/knowledge`) runs at the
  end of every phase.

## Build order and the handoff (proposed — design D16, decided 2026-10-05)

The Spaces division: a **planning-class model** builds what the database enforces, the parsers against their fixtures, the
engine proof, the specs and the exemplars; a **worker model (Sonnet 5.5)** replicates every other slice from a spec, stopping
and escalating on any ambiguity.

**Before the handoff (planning-class model):** (1) **K25** in the kernel (the `knowledge` catalog row, db/170 on the db/169
pattern) and **K24** (the ledger proxy's application credential); (2) **Phase 0, second half**: `db/001`–`0NN`, the read
functions and `mcp_*` views, `db/proof/phase0_proof.sql`, the kit from Consultant Tracking proven without a kernel, **the parser
fixtures** (`tests/parsers/`, excerpts the owner supplies), **`tests/phase0/engine.sh` against the live MaluDB API on a scratch
memory database**, `maludb-os.json`, `os/{expert,archivist}.md`, the skills, `deploy/` with `ROOT_STEPS.sh` (ports, then the
memory database and its token); (3) **Phase 1** for the owner's approval (incl. `guidelines-compile.md` and `code-parsers.md`);
(4) **Phase 2**, **slice 1**; (5) **slice 2 (sources and the code parsers) — THE FIRST EXEMPLAR**, slices 3 and 4, then **slice
5 (ask) — THE SECOND**, each proven at 375 and 1280.

**The handoff point** = the commit after slice 5 with design §16 saying "exemplars built; slices 6–9 open to workers".

**After the handoff (worker model):** slices 6 → 9 in order, one commit each; then **Phase 4** (the two MCP servers over the same
query functions and engine module; `app_roles` and the three `shares[]` admitted to the kernel's token; the registry wrapper;
the agents' grants and the Archivist's duty — Help Desk's `tests/phase4/run.sh` is the pattern), and **Phase 5** with the owner
on the proof corpus (design §10).

**A worker's rules:** read this file, design §3, §5, §6 (and the compile and parser specs), §15 and the slice's spec before
touching a file; reads through `mcp_*` views, the read functions and `engine.php`; writes to base tables after the gate; the
database's refusal shown as a field error, never re-implemented; a question the spec does not answer stops the slice and is
written into the spec's "Open questions" for the owner — never guessed.

## Environment facts
- Install path `/srv/apps/knowledge`; record database `subello_knowledge` (roles `knowledge_rw`, `knowledge_records_ro`,
  `knowledge_activity_ro`) and memory database `subello_knowledge_memory` (`maludb_core` 0.106.0, schema/role `knowledge_mem`)
  on the office's PostgreSQL 17 — neither created yet. MaluDB API 0.3.1 on `127.0.0.1:8000`.
- Vhost `knowledge.subello.com`; ports to pin: `APP_INTERNAL_PORT=8187`, `MCP_RECORDS_PORT=8835`, `MCP_ACTIVITY_PORT=8836`
  (the block after Spaces' 8186/8833/8834). Proof scratch ports: 8501–8507.
- `pdftotext` (poppler-utils) and PHP's `zip`, `xml`, `gd` are installed; tesseract is not (OCR is Extended).
- The proof corpus (IFC 2018, IFC 2021, Title 14F and Title 15 exports, Cook County Chapter 102 and its ordinance, NFPA 13
  and 72) is **the owner's to supply from the licences held**; American Legal Publishing and Municode refuse automated fetches.
- Nothing is installed yet; `plan` only, never `apply`, from this clone (the owner runs `apply`).
