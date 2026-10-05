# Knowledge — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-05 · the business's **knowledge bases**: a person **uploads documents**, writes **guidelines**, names the **areas** they
care about, and the application builds a **knowledge graph** in MaluDB that is asked three ways — an **ask-me-anything
window**, a **token API** other applications call, and the kernel's agents (the command bar, a mention in Spaces, a sibling's
K7 read). **The content does not matter** (the owner, 2026-10-05, after first naming laws and fire codes): the application is a
general one, and the fire codes of Chicago and Cook County were only an example for development and specs. That example's
whole design — a code parsed into numbered provisions, amendments, editions, jurisdictions, effective dates — is kept as an
**Extended** module in `structured-documents-extended.md`; version 1 is *upload documents, get a knowledge base you can ask*.
Its own repository `/srv/apps/knowledge` (origin `github.com/maludb/maludb-os-knowledge`), served as `knowledge.<domain>`,
built with `htmx-php-builder` and fitted to `maludb-os-integration` 0.7.0, exactly as HR, Projects, Help Desk, General Ledger,
Consultant Tracking and Spaces were. `/srv/apps/spaces/docs/spaces-design.md` is the nearest plan; `/srv/apps/helpdesk` and
`/srv/apps/consultant_tracking` are the nearest built exemplars. The engine is **MaluDB** — `maludb_core` 0.106.0 and the
MaluDB API (0.3.1 live; **0.4.0 adds the answer route, M1**, built on branch `feat/memory-answer-m1`, §12).

> **What this document is.** The plan, in the kernel's words, and the brief a worker model builds from. Phase 0's first half is
> this document and the owner's answers (§13, recorded in §15); the schema in `db/`, `maludb-os.json`, `os/`, `skills/` and
> `deploy/` complete it. Phase 1 adds `docs/knowledge-mcp-tool-surface.md`, `docs/knowledge-action-manifest.md`,
> `docs/build-specs/guidelines-compile.md`, `docs/build-specs/sectioning.md` and the slice specs. **No feature PHP is written
> until the owner approves them together.**

## 0. The research — what the field calls this, and what MaluDB gives today

**Google NotebookLM** — a notebook of *sources*, every answer grounded and cited inline, a citation opens the passage; a guide
with suggested questions; an answer saved as a note and a note turned into a source. *Kept:* sources as the unit people
understand, citations that open the passage, suggested questions, "pin this answer" turning an answer into a source.
*Dropped:* audio overviews, Google-only sources, the source cap.

**Claude Projects and custom GPTs** — instructions plus knowledge files plus conversations; one text box. It stops working for
hundreds of documents: nothing says *what to extract*, *what the words mean*, or *what never to say*. *Kept:* guidelines as
first-class text a non-technical person writes. *Changed:* **five kinds** of guideline, because they act at different moments,
and **templates** that ship a written set for a kind of corpus.

**GraphRAG and LightRAG** — entities and relationships extracted by a model, clustered into communities; local questions
(an entity's neighbourhood) answered differently from global ones (themes); prompt tuning writes a domain's extraction prompt.
*Kept:* local/global (a question scoped to a focus area or asked of the whole base; the explorer shows communities, god nodes
and surprises); prompt tuning *is* what guidelines and vocabulary are; **the graph proposes a focus area** (communities once MaluDB computes them). *Changed:*
community reports are written on request by the expert.

**Glean, Guru, Notion AI, and the hosted RAG builders** (OpenAI file search, Pinecone Assistant, Dify, AnythingLLM) — answers
whose permissions follow the source; verified answers re-verified on a schedule; feedback on each; and, for builders, a
workspace, an **API key** per workspace, `POST /ask` with citations, rate limits, a widget. *Kept:* reach by base; pinned
answers with an owner and a review date; thumbs; gaps as a report; the API of exactly that shape. *Extended:* the widget.

**MaluDB — the engine (core 0.106.0; the API in this repository's sibling `maludb-python-api-server`)**:

| Need | MaluDB | Used here as |
|---|---|---|
| Text in, graph out | `POST /v1/memory/documents` (title, text, `namespace`, `projects[]`, `metadata`, chunking) and `/v1/memory/ingest` (`hints[]`, **`preview`**): the model extracts subjects, verbs and statements with source spans; `DELETE /v1/documents/{id}` **forgets** a document and what it taught | **a section is one MaluDB document** in the base's namespace, its metadata naming the section; **a build** is these calls; `preview` is "try my guidelines on this section"; removing a source forgets its sections |
| A corpus's extraction prompt | `POST /v1/memory/config` **per namespace** (model alias, `prompt_template` with `{{chunk}}`, embedding model) | **a base's compiled guidelines ARE its namespace config** — one namespace `kb:<id>` per base |
| Areas | **projects** (`/v1/projects`: a subject of type `project` with `classifier_md` and linked subjects) | **a focus area is a MaluDB project** |
| Vocabulary | subjects with aliases and a type; the extractor is handed the known names | **vocabulary terms are seeded as subjects before a build** |
| Asking | `graph/query`, `memory/recall`, `graph/{neighbors,walk,path,god-nodes,surprises,stats}`, `communities` | the explorer and the evidence step |
| **Answering in words** | **`POST /v1/memory/answer` (M1, API 0.4.0)**: retrieve in a namespace (a project's subjects when named), compose with the host's guidelines, **verify every quote against the evidence it cites**, withhold what cannot be verified, call no model when there is no evidence, report usage | **`kn_ask()`** — the one function behind every door |
| Keeping it fresh | `/v1/memory/embeddings/run` (only with a real embedder) | not needed: the documents route embeds; **reindex is not used** (it re-extracts with the generic prompt) |
| Metering | the provider's base URL can be the kernel's ledger proxy with an application credential (**K24, built**) | every extraction, embedding and answer call on the AI statement |
| Who may read | principals and scopes (`X-MaluDB-Principal`…) | v1 enforces reach in the application; per-member principals Extended |

What the application adds that MaluDB does not have: files and **text extraction**, **sectioning** (so a citation names a
section and a page), the five guideline kinds and their compilation, templates, areas as a person sees them, builds with
progress and a rebuild rule, the question log with gaps and pins, bases and who may read them, API keys with rate limits, the
K7 shares, and the two agents. An application never holds a model key: MaluDB holds the provider key, and the kernel meters.

**What the engine proof found (2026-10-05, `tests/phase0/engine.sh`, 134 checks against the real API on a scratch tenant)** —
where MaluDB differs from what this plan first assumed, and what the design now does about it:

| Finding | Consequence in this design |
|---|---|
| `POST /v1/subjects` and `/v1/projects` allocate ids as `MAX+1` outside the table's sequence; the next extraction then fails with a duplicate key — after the model was already paid for | `engine.php` never calls them: terms and focus-area projects are created by posting a section whose edges the caller supplies (no model call) and forgetting it at once. **API defect, owed upstream (M7).** |
| No route stores **subject aliases** (the column exists, the core resolves it at ingest) | aliases travel in the subject's description and as `ALIAS: x => y` lines in the compiled prompt; the question is expanded with the canonical term (`kn_engine_expand_aliases`). **M7: an `aliases` field on `POST/PATCH /v1/subjects`.** |
| `/v1/memory/documents` expects `{"candidate_edges":[{subject_text, subject_type, verb_text, predicate[], source_span, confidence}]}`, takes **no hints**, and turns each edge's `source_span` into a searchable chunk | the compiled prompt asks for **verbatim spans**; hints become the document's subject tags |
| `/v1/memory/ingest {preview:true}` calls no model and **ignores the namespace prompt** (it returns the generic ingest prompt) | "preview on a section" shows the base's own prompt filled with the section's text (`documents_prompt`) — a dry run of the *prompt*, not of the model. **M5: a documents dry-run that extracts with the namespace prompt and writes nothing.** |
| **No community detector**: communities exist only when written (`graph/import`, `maludb_community_replace`) | "a community proposes a focus area" cannot work yet: **the expert proposes areas from the graph's most connected subjects and the subject clusters it reads through the graph tools**, and communities are used the day MaluDB computes them. **M6: community detection over a namespace.** |
| `graph/query?namespace=` filters by a name prefix, `graph/stats` and `god-nodes` cover the whole tenant, subjects and projects are shared by every base in the memory database | `engine.php` stamps each section with `document_type = kb:<id>` and filters graph results to that base's documents; chunks, recall, answers and communities are isolated by namespace and are proven so. A base's *graph explorer* is therefore filtered in the application until MaluDB scopes these routes (M3) |
| `reindex` re-extracts with the tenant's `extract` task and the **generic** prompt, not the base's | **the worker does not run reindex**; the documents route already embeds; an admin must never set a tenant `extract` model on this memory database |
| `GET /v1/documents/{id}` returns no metadata | the application maps a document back to its section through its own `maludb_document_id`; only the answer route's evidence carries `document.metadata` |
| README §2 omits `GRANT maludb_secret_consumer TO <role>` — without it the namespace's provider secret cannot be read | added to `deploy/ROOT_STEPS.sh` |
| Only the answer route reports usage | as the design said: extraction cost shows through K24's ledger |

## 1. What Knowledge is, and is not

Knowledge **owns the business's curated corpora and what can be asked of them**: a **knowledge base** per subject — product
manuals, policies, contracts, a client's filings, research, any pile of documents — each a set of **sources** with
**guidelines** and **focus areas**, built into a graph in MaluDB, asked by people, agents and other systems.

- **The graph is MaluDB's.** Nothing of extraction, embedding, storage, traversal or answer composition is written here; a gap
  in the engine is fixed upstream (§12). The application is files, sectioning, guidelines, areas, builds, asking, keys and
  who-may-read.
- **The easy path is three steps:** *create a base* (name it, pick a template), *drop documents in* (many at once, paste text,
  add a page), *ask* (the window). Guidelines and focus areas improve the graph and are never required: a base answers from the
  moment its first build finishes, using its template's shipped guidelines.
- **Guidelines are first-class and typed** (§6); three change the graph and mark the base "needs a rebuild", two change only the
  answers. After a first build the expert **proposes** areas from the graph (its most connected subjects and their clusters; MaluDB's
  communities when it computes them) and drafts guidelines from a sample, which a person accepts.
- **Every answer cites or declines.** An answer carries the exact words it relies on, verified against the sources by the
  engine, and a citation that opens the section and page; weak evidence is a plain "the knowledge base holds nothing on this"
  and a logged gap — never a guess.
- **Three doors, one gate.** The window, the API and the agents pass one visibility function and one `kn_ask()`; a sibling
  reads a base through K7 only when the base was shared with it; a key reaches one base.
- **Full MCP coverage** is a requirement (`maludb-os-integration`, `mcp-and-api.md`).
- **Nothing of identity lives here.** The kernel signs people in; a guest is a kernel external; a system holds a key.

Not in version 1: OCR of scanned PDFs and images (flagged `no_text`; Extended); audio and video; crawling a site; the
embeddable widget; community reports written automatically; per-member MaluDB principals; **structured documents** — laws and
codes parsed into numbered provisions with amendments, editions and effective dates (`structured-documents-extended.md`);
fine-tuning or training of any kind.

## 2. Who uses it (the actors, in the OS's words)

"Agent" always means an OS AI agent. A **member** is a kernel member mirrored here; a **guest** a member flagged external
holding Reader; a **knowledge base** ("base") a named corpus with its template, guidelines, areas and graph; a **source** one
document, pasted text, fetched page, note or fed record; a **section** one heading-delimited part of a source (the unit of
citation, and one MaluDB document); a **guideline** one instruction of one kind; a **template** a shipped set of guidelines;
a **focus area** a named area (a MaluDB project); a **build** one extraction run; a **question** one ask with its answer,
evidence and citations; a **key** an API key bound to a base.

| Actor | Who | How they reach the application |
|---|---|---|
| **Reader** | Anyone a base is shared with: asks, reads sources and the graph, rates answers | The launcher → `knowledge.<domain>`; the phone (the window is read at 375 px first); the command bar |
| **Member** | Everyone inside the business holding the base role: a Reader who may also **create a base of their own** and add sources where given curate | The same |
| **Curator** | Owns or curates a base: sources, guidelines, areas, builds, sharing, keys, pinned answers, the question log | A base's settings |
| **Guest** (external) | A client, an auditor: a kernel external holding Reader; sees only the bases shared with them | The launcher, to a one-base home |
| **Knowledge admin** | Every base; settings; the model configuration (read from MaluDB, changed with a pause); limits and retention; every key; costs. A super-admin holds it | The admin area |
| **An agent** | Any hired agent granted Reader (the shipped **expert** and **Archivist** on install): asks and browses like a member | Records MCP (run token) · Actions MCP (relayed) · a chat turn · a duty |
| **An outside system** | Holds a key: asks one base through the token API, rate-limited, logged under the key's label | `POST /api/v1/ask`, `/api/v1/search` (§8) |
| **A sibling application** | Help Desk's expert answering a ticket from the manuals; Spaces' expert; Projects | K7 `ask` / `search` / `bases_index` over an approved connection, only for bases shared with that application |
| **The kernel** | Sign-on, the directory, the agents, approvals, the command bar (chat endpoint), the ledger proxy (K24), the siblings' reads (K7) | — |
| **System** | The worker: text extraction and sectioning, builds, reindex and embeddings, feeds, URL checks, the outbox, retention, rate counters, the Archivist's wake | `source = 'cron'`, every row logged |

Tenancy: the business running the application is the tenant — one installation, one record database, **one memory database of
its own** for the bases (§6, D3) beside the tenant's agent memory, whose episodes still carry this application's activity.

## 3. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`). The base role's key is `user` so the
installer's `--grant-standing-departments` makes every insider a Member when installed that way.

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `reader` | Reader | read | `bases.read` (the bases shared with me: ask, read sources and sections, browse the graph, see my own questions), `answers.rate` |
| `user` | Member | write | Reader's + `bases.create` (a base of my own — I am its curator), `sources.add` (where given curate), `questions.own` |
| `curator` | Curator | write | Member's + on my bases: `base.manage` (name, template, visibility, areas, guidelines, vocabulary, settings), `sources.manage` (add, replace, remove — removing pauses for an agent), `builds.run`, `base.share` (to members and departments; to a guest or an application pauses for an agent), `keys.manage` (pauses for an agent), `answers.pin`, `questions.review`, `feeds.manage` |
| `admin` | Knowledge admin (is_admin) | admin | every right on every base + `settings.manage`, `model.manage` (pauses), `limits.manage`, `retention.manage`, `keys.all`, `costs.read`, `agents.settings`; **sees private bases, logged** `base.admin_view` |

The `curator` role is application-wide capability; who curates *which* base is `base_members.level = 'curate' | 'own'`.

**Reach** (`kn_visible_base_ids()` as ONE PL/pgSQL function returning the caller's set, tested once per statement in every
view — the kernel's db/160 lesson; `kn_can_read_base(id)`, `kn_can_curate_base(id)`):

- **A base is private or internal.** Private: its `base_members`. Internal: every member of the business reads (the `everyone`
  principal). Guests never see an internal base unless named. The admin sees everything, logged. **A new base is private.**
- **`base_members (base_id, principal_kind member|department|agent|application|everyone, principal_id, level
  read|curate|own)`**. An **application** principal (a sibling's catalog key) is what K7 checks.
- **Sources, sections, areas, builds, the graph and the question log follow their base.** A Reader reads sources, sections
  and the graph (that is how an answer is trusted) but **not** guidelines, keys, other people's questions or costs.
- **A question belongs to its asker and its base**; curators see all of the base's; a key's questions show the key's label.
- **The engine is asked only about namespaces the caller may read**; the MaluDB token never leaves `app/knowledge/engine.php`.
- **An agent sees what its member id may see**; the run-facts gate decides the tools; `is_eval` refuses every write.
- **Scope: none.** One business, one installation; departments are principals, never a wall.

## 4. Doors for those who are not inside — guests, keys and the siblings

The kernel's rules: *application users are not OS users*; *never a password, a login form or an account of the application's own*;
for a reach with no account, **a token is the authority**.

**A guest** is a kernel member flagged external, granted Reader by the super-admin; they land on the bases shared with them.

**A key** (`mcp_access_tokens` with `scope = 'api'`, **appended** `base_id`, `focus_area_id`, `rate_per_minute`, `rate_per_day`,
`rotated_from`) is minted by a curator for one base, shown once, hashed; every call is rate-limited (defaults 30/min,
2,000/day), logged `question.ask` with source `api` under the key's label, and answered exactly as the window answers a
Reader. Rotation keeps the old key 24 h. The public widget is Extended.

**A sibling application** reads through **K7**: `ask`, `search` and `bases_index` are `shares[]`; the kernel calls them over an
approved connection; the answer covers only bases with an `application` principal naming the consumer.

**Rejected:** inviting outsiders by email; a base reachable by link alone; the MaluDB token handed to anyone.

## 5. Agents and the application — the OS requirement, in detail

**Asking, browsing, adding sources, writing guidelines and building are free; deleting, sharing out, minting keys and changing
the model are a person's.** For every agent:

| Action | Category | Why |
|---|---|---|
| `base_share_guest` and `base_share_application` (two keys, so a share to a member or a department stays free), `key_mint`, `key_rotate`, `base_visibility_set` (private → internal) | `external_send` | Content reaches someone outside, or a system |
| `base_delete`, `source_remove` (forgets it in the engine), `guideline_delete`, `focus_area_delete`, `question_purge`, `key_revoke` (not its own) | `deletion` | |
| `model_config_save`, `settings_save`, `limits_save`, `retention_set`, `build_rebuild_all` | `other` | Configuration, spend |
| `base_create` (private), `source_add` (file, text, URL), `source_replace`, `guideline_save`, `vocabulary_save`, `focus_area_save`, `focus_area_propose`, `build_start` (new and stale sections only, under the limit), `answer_pin`, `question_feedback`, `base_share` to a member or a department (an agent too), `feed_sync`, `archivist_proposal_*` | — | An agent must do these alone — this is what makes it a curator |

**Working.** (a) **The command bar and Spaces**: a person's turn on the expert through the kernel's chat endpoint; the expert
answers with `ask` and the graph tools, scoped to what the asker may read. (b) **A duty** (cron) for the Archivist. (c) **A
proposal needing a hand** writes an `agent_dispatches` row: "the first build finished — propose areas and draft guidelines"
wakes the expert through one chat turn (K8 when it lands).

**Shipped agents** (`maludb-os.json` `agents[]`; **hired on install**):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — Knowledge Expert | The command bar and the house name: answers from the bases the asker may read, **always cited**, saying which base answered; explains a subject, a community, a path; **proposes focus areas** from the graph's most connected subjects and clusters after a first build (MaluDB's communities once it computes them, M6); **drafts guidelines** from a sample; writes an area summary when asked; suggests questions; never deletes, shares out or mints a key unless the asker says so, and then pauses | every records tool, `ask`, `search`, the graph tools, `record_history` | `focus_area_propose`, `focus_area_save`, `guideline_save`, `vocabulary_save`, `answer_pin`, `build_start`, `source_add` (text), `base_share` (member/department), `key_mint` (paused), `source_remove` (paused) | `key_mint`, `source_remove`, `base_share` to a guest or an application |
| `archivist` — Knowledge Archivist | A duty every Monday 06:45 (`45 6 * * 1`): the **base report** per base — sources that failed, have no text or are stale against the guideline version; URL sources whose page changed or vanished; feeds that stopped; builds that failed; the monthly limit nearly spent; the **answer report** — questions with no evidence (the gaps, clustered: "nine questions about returns; no source covers returns"), thumbs-down answers with their evidence, unverified answers, pinned answers past review, duplicate subjects the vocabulary should merge, areas with no source and sources in no area; each a **proposal** a curator accepts or dismisses; one Monday note per base to its curators and one to the admin's assistant | the report tools of §7, `get_base`, `find_sources`, `question_gaps`, `duplicate_subjects`, `graph_stats` | `archivist_propose`, `notification` to curators, `message_send` (the kernel's, to the admin's assistant) | nothing (it never deletes, shares, mints or rebuilds) |

**Skills** (`skills/`): `knowledge-basics` (what a base, source, section, guideline of each kind, template, focus area, build,
question and key *are*; which tool answers what; always cite, never guess), `writing-guidelines` (the five kinds with examples
for manuals, policies, contracts, research), `asking-well` (local vs global questions, scoping to an area, when to say the base
holds nothing, how to read evidence), `reading-the-graph` (subjects, statements, communities, god nodes, surprises); runbooks
(`kind: runbook`): `propose-focus-areas`, `draft-guidelines-from-a-sample`, `answer-with-citations`, `monday-base-report`,
`merge-duplicate-subjects`.

## 6. Memory model — what Knowledge remembers

Two stores, deliberately apart: **record memory** (PostgreSQL 17, `<tenant>_knowledge`) holds the bases, sources, their text and
anchors, sections, guidelines, areas, builds, questions and keys; **the graph** lives in MaluDB — **a memory database of its
own** (`<tenant>_knowledge_memory`, `maludb_core` enabled, its own API token; D3), **one namespace per base** (`kb:<id>`).
The tenant's memory keeps receiving this application's activity episodes as every sibling's does.

### 6.1 From a file to sections (the worker, `app/knowledge/extract/`)

**Text extraction**: PDF through `pdftotext -layout` per page (a PDF with no text layer is `no_text`); DOCX, PPTX and XLSX as the
XML inside the zip (`ZipArchive` + `DOMDocument`) keeping heading styles, slide titles and sheet names; HTML through a reader
pass (scripts, nav, boilerplate stripped); Markdown and plain text as they are; CSV and JSON flattened to rows of text; a
**URL** fetched server-side (http/https, public hosts only, 10 MB, 20 s, one page); **pasted text** verbatim. Each yields
`source_text.text` and `source_pages (page_no, char_from, char_to)`.

**Sectioning** (`docs/build-specs/sectioning.md`): the text is cut into **sections** by the document's own structure — DOCX
heading styles, HTML `h1`–`h6`, Markdown `#`, PPTX slides, XLSX sheets, a PDF's numbered headings and short all-capitals lines;
a source with no usable structure (a plain text file, a PDF without headings) is cut at paragraph boundaries into parts of
about 4,000 characters. A section has a heading path ("Handbook › Leave › Carry-over"), a level, a parent, a position, its
text and char range, and its first page; a section over 6,000 characters is split at paragraph boundaries into parts. **Each
section is one MaluDB document** whose title is its heading path and whose metadata names it (`{base, source, section, page}`),
so evidence maps straight back to a section and page, an edited source re-ingests only its own sections, and removal forgets
exactly what the source taught. Sectioning is deterministic PHP — never a model.

### 6.2 How a person's words become the engine's (`app/knowledge/compile.php`, on every guideline save)

| The person writes | The application keeps | MaluDB receives |
|---|---|---|
| a **base** from a **template** | `knowledge_bases` (+ `template`) | a namespace `kb:<id>` and its **memory config** (`POST /v1/memory/config`: the admin's model alias, the compiled `prompt_template`, the embedding model) |
| an **extraction** guideline ("pull out every product, version, owner and date; a policy is a subject; a deadline is a statement") | `guidelines` kind `extraction`, versioned | lines in the namespace's `prompt_template` and `hints[]` on every ingest |
| a **vocabulary** guideline + terms ("PG17 = Postgres 17 = PostgreSQL 17, a *technology*") | `guidelines` kind `vocabulary`, `vocabulary_terms` | **subjects seeded with aliases and a type** before the build |
| an **exclusion** guideline ("ignore page headers and footers; never extract personal data about employees; nothing about pricing") | `guidelines` kind `exclusion` | prompt lines; an answer-time filter |
| an **answering** guideline ("two paragraphs, plain English, say when the sources disagree") and an **audience** guideline ("field technicians on a phone") — **apply at once, no rebuild** | `guidelines` kinds `answering`, `audience` | M1's `system` field, and the expert's turn |
| a **focus area** ("Warranty — coverage, exclusions, claims; terms: warranty, claim; ask: how long is the battery warranty?") | `focus_areas` | a **project** (`classifier_md` = the description, linked subjects = the seed terms); a question scoped to it retrieves from the project's subjects |
| a **source** | `sources` + its text, pages and sections | one `POST /v1/memory/documents` per section (title, text, `namespace`, `projects[]`, `metadata`) → `maludb_document_id` kept; **removal** = `DELETE /v1/documents/{id}` (forgets) |
| a **build** | `builds` + `build_items` (one per section) | the ingest calls (the documents route embeds as it goes; **reindex is never run**); counts from the base's own documents, cached |

A change to an **extraction, vocabulary or exclusion** guideline bumps `knowledge_bases.guideline_version`; sections built under
an older version show **stale**; `build_start` re-extracts new and stale sections under the base's monthly limit;
`build_rebuild_all` is a person's. **Preview** shows the base's own compiled prompt filled with one section's text — the try-before-you-spend every guideline
form offers — and, with the engine's own `preview` route, the generic ingest prompt it would otherwise use; a true dry run of the
model's extraction is M5.

**Templates** (`base_templates`, seeded, versioned): **`general`** — a light extraction prompt (entities, relationships,
dates, amounts, owners), default exclusions (headers, footers, page numbers, boilerplate), the answering guideline (answer
from the sources, cite, say when sources disagree, say when they are silent), an audience of "a colleague who has not read the
documents"; areas proposed from communities. A template is **data** (a guideline set, subject types, the area rule), so a
second template — contracts, policies, a code — is a row, not a release; the structured-documents module
(`structured-documents-extended.md`) ships as a template plus parsers when a corpus calls for it. A base may switch template
only while empty.

### 6.3 Asking

`kn_ask()` (`app/knowledge/ask.php`; one function behind the window, the API, the MCP tool, the K7 share and the expert): gate
(base readable, key valid and under its rate, question ≤ 2,000 characters) → the last three turns → **M1** `POST
/v1/memory/answer {namespace, project?, question, history, system: the compiled answering and audience guidelines,
max_evidence, min_similarity}` → `{status, answer_md, citations[{n, quote, chunk_id, document_id, verified}], evidence[{n,
text, similarity, document{title, metadata}}], usage}` → each evidence item and citation mapped by its document's metadata to
a **section** (heading path, page, source) → a `questions` row with `citations` and `evidence`, status `answered |
evidence_only | no_evidence | unverified | refused | failed` → the reply. **M1 verifies every quote** against the evidence it
cites and withholds what it cannot verify (`unverified`); with no usable evidence it calls no model (`no_evidence`) and the
question is a **gap**. **Until M1 is deployed:** `kn_search()` (`graph/query` + `memory/recall`, ranked, mapped to sections,
capped at twelve) answers with **the passages themselves** (`evidence_only`), and the window's "Ask the expert" runs the
kernel's chat endpoint.

**Record memory** (PostgreSQL 17, `<tenant>_knowledge`, db/001–0NN; prefix `kn_` on functions, views `mcp_*`):

| Area | Tables | Notes |
|---|---|---|
| The mirror | `members` (+ `is_agent`, `is_external`), `department_members`, `departments`, `sso_nonces`, `member_sessions`, `directory_sync_state` | the kernel's ids; `members.roles text[]` |
| Roles | `kn_rights`, `kn_roles`, `kn_role_rights`, `mcp_app_roles` | `kn_has_right(right)` |
| Settings | `kn_settings` (one row: default monthly limit (MB of text per base per month, 50), max source bytes (50 MB), URL fetch allowed, evidence threshold, question retention days (365), key defaults (30/min, 2,000/day), review months for pins (6), the model alias and embedding model — **read from MaluDB, changed with a pause**) | seeded |
| Templates | `base_templates` (key, name, version, `guidelines jsonb`, `subject_types text[]`, `area_rule`, `default_visibility`) | seeded: `general` |
| Bases | `knowledge_bases` (id, `key`, name, description, `template`, `visibility` private/internal, `owner_member_id`, `namespace`, `status` empty/building/ready/needs_rebuild, `guideline_version`, `auto_extract`, `monthly_text_mb_limit`, `sensitivity`, `language`, counts cached from the engine, `last_built_at`, `archived_at`), `base_members` (§3) | a base's namespace is set once |
| Sources | `sources` (id, base, `kind` file/text/url/feed/note, title, `origin`, `attachment_id`, `text_sha256`, `char_count`, `page_count`, `language`, `status` uploaded/extracting_text/sectioning/ready/no_text/failed/forgotten, `sections_count`, `error`, `added_by/at`, `superseded_by`, `url_etag`, `url_checked_at`), `source_text`, `source_pages`, `source_focus_areas` | a replaced source's sections are forgotten and re-cut |
| Sections | `sections` (id, base, source, `heading_path`, `heading`, `level`, `parent_id`, `position`, `kind` heading/part/slide/sheet/rows, `text`, `char_from/to`, `page_no`, `status` queued/extracted/stale/failed/forgotten, `extracted_version`, `maludb_document_id`, `extracted_at`, `error`) | the unit of citation and of a build |
| Files | `attachments` (bytes under `storage/sources/<base>/<id>/`) | GL's canonical; `record_type = 'source'` |
| Guidelines | `guidelines`, `guideline_versions`, `vocabulary_terms` (base, canonical_name, aliases, subject_type, description, `maludb_subject_id`) | a graph-shaping save writes a new version |
| Focus areas | `focus_areas` (id, base, name, slug, description, `seed_terms`, `example_questions`, `maludb_project_id`, `status` proposed/active/archived, `proposed_by`, `proposal_note`, position) | an area is a MaluDB project |
| Builds | `builds` (id, base, `kind` extract/rebuild_all/reindex/embed/forget, status, `started_by`, times, `items_total/done/failed`, `chars_processed`, `subjects_added`, `statements_added`, `usage jsonb`, `log`), `build_items` (build, section, status, times, error, usage) | one build per base at a time |
| Questions | `questions` (id, base, `focus_area_id`, `asked_by_member_id`, `token_id`, `application_key`, `conversation_id`, `turn_no`, `door` web/api/mcp/share/agent, question, `answer_md`, `status`, `confidence`, `evidence jsonb`, `guideline_version`, `model`, `latency_ms`, `usage`, `created_at`), `citations` (question, rank, `section_id`, source, page_no, quote, `maludb_chunk_id`, `verified`), `question_feedback`, `pinned_answers` (base, question, title, `answer_md`, `owner_member_id`, `review_until`, `verified_at`, `source_id` (the note), status) | the question text retained per the setting |
| Keys | `mcp_access_tokens` (+ `base_id`, `focus_area_id`, `rate_per_minute`, `rate_per_day`, `rotated_from`), `key_usage` | `kn_rate_ok(token)` |
| Feeds | `feeds` (base, provider, tool, `arguments`, area, `every`, `last_sync_at`, `last_status`, `items`) | Spaces' pages, Help Desk's articles through K7 |
| Agents | `agent_dispatches` (+ `base_id`, `build_id`), `archivist_proposals` (base, `kind` area/merge/gap/stale_source/failed_source/review_pin/unassigned/unverified, payload, status, decided_by/at) | §5 |
| Notifications | `notifications` (build_done, build_failed, limit_near, proposal, feedback, review_due, monday_note), `notification_prefs`, `notification_outbox` | GL's canonical |
| Notes | `notes` (on a base, a source, a section) | GL = CT canonical |
| Usage | `ai_usage` (base, month, `kind` extract/answer/embed, calls, input_tokens, output_tokens, chars) | the application's own count; dollars are the kernel's (K24) |
| Tokens | `mcp_access_tokens` with `scope = 'mcp'` | a person's own |

### 6.4 Tables against the estate — read, reuse, new (the shared-schema rule)

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | **reuse** | the kernel contract (Consultant Tracking `db/001`); `members` **appends** `is_agent`, `is_external` (Spaces') |
| `activity_log`, `activity_ingest_state` | **reuse** | `memory.md` / CT `db/002`; **appends** `base_id` |
| `mcp_access_tokens` | **reuse** | CT `db/003`; **appends** the bound-token columns — proposed to the catalogue |
| `kn_rights`, `kn_roles`, `kn_role_rights`, `mcp_app_roles` | **reuse** (shape) | CT `db/004` (`ct_` → `kn_`) |
| `kn_settings` | **reuse** (skeleton) | the `app_settings` singleton skeleton |
| `attachments` | **reuse** | **GL `db/009` canonical**, verbatim; `record_type` widened to `source` |
| `notes` | **reuse** | GL `db/009` = CT `db/006`; `record_type` widened to `base`, `source`, `section` |
| `notifications`, `notification_prefs`, `notification_outbox` | **reuse** | **GL `db/014` canonical**; `kind` lists ours; every recipient a member (Spaces' substitution) |
| `agent_dispatches` | **reuse** | GL `db/014` = CT `db/013`; `kind` widened; **appends** `base_id`, `build_id` |
| `ai_usage` | **new** (recorded) | an application's own count of engine calls per base per month; GL's `ai_statements` are the kernel's statement imported — nothing close |
| `questions`, `citations`, `question_feedback`, `pinned_answers` | **new** | a logged question with evidence and citations exists nowhere — canonical |
| `knowledge_bases`, `base_members`, `base_templates`, `sources`, `source_text`, `source_pages`, `source_focus_areas`, `sections`, `guidelines`, `guideline_versions`, `vocabulary_terms`, `focus_areas`, `builds`, `build_items`, `key_usage`, `feeds`, `archivist_proposals` | **new** | nothing close (Help Desk's `articles` and Spaces' `pages` are authored content — **read** below); `sources` + `sections` become the canonical "ingested document with text anchors" |
| Help Desk's `articles`, Spaces' `pages`, Projects' `issues`, HR's employment | **read** | a feed through K7 when the sibling shares it; never copied except as a `feed` source keyed by the provider's id |

**Owed to the catalogue**: `mcp_access_tokens`'s bound-token columns; `sources`/`sections`, `questions`/`citations` and
`ai_usage` as Knowledge's canonical tables.

**Views and functions**: `mcp_*` over every table above with `security_barrier` and `kn_visible_base_ids()` once per statement;
the reads that matter are SQL functions — `kn_base_overview(base)`, `kn_source_outline(source)`, `kn_section_markdown(id)`,
`kn_questions(base, filters)`, `kn_question_gaps(base, since)`, `kn_build_progress(build)`, `kn_build_estimate(base)`,
`kn_rate_ok(token)`, `kn_usage(base, month)`; the engine reads are **one PHP module** (`app/knowledge/engine.php`), the only
place the MaluDB token is read.

**Activity memory**: `activity_log` through one `log_activity()`, shipped to the tenant's one MaluDB as `activity` episodes
tagged `knowledge`. **A source's text, a section's text and a question's text are never in a log payload** (ids, titles, counts,
the kind, the status, 120 characters of the question at most). Events: `base.create|update|share|unshare|visibility_set|archive|
delete|admin_view`, `source.add|replace|text_extracted|no_text|sectioned|fail|remove|forget`, `section.stale|extract|fail`,
`guideline.save|delete|version`, `vocabulary.save|sync`, `focus_area.save|propose|accept|dismiss|delete`, `build.start|
progress|done|fail|cancel|rebuild_all`, `question.ask|answer|no_evidence|unverified|refuse|feedback|pin|unpin|purge`,
`key.mint|rotate|revoke|rate_limited`, `feed.save|sync|fail`, `archivist.propose|accept|dismiss`, `agent.dispatch|reply|fail`,
`settings.save`, `model_config.save`, `share.read`, `token.mint|revoke`, `member.sign_on`, `directory.sync`, `screen.view`.
A key is never logged.

## 7. The question inventory — what Knowledge exists to answer

Kind R = record (the records MCP), A = activity (the activity MCP). Who = the lowest role that may ask (every answer is
filtered by what the asker may see). The tool name is fixed here so Phase 1 is a transcription.

**Bases**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| B1 | Which knowledge bases may I read, what is each about, how big, when last built? | R | Reader | `find_bases`, `get_base` |
| B2 | Who may read or curate base X, and why (member, department, everyone, agent, application)? | R | Curator | `base_members` |
| B3 | What are the application's settings, templates and the model in use? | R | Reader / Admin (model) | `get_settings`, `find_templates` |

**Sources and sections**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| S1 | What sources are in base X, by kind, status and area; which are stale, failed or without text? | R | Reader | `find_sources`, `get_source` |
| S2 | What does source X contain — its outline, one section, one page, the text around a citation? | R | Reader | `source_outline`, `get_section`, `source_page`, `source_excerpt` |
| S3 | Which subjects and statements did section or source X contribute to the graph? | R | Reader | `section_graph`, `source_graph` |
| S4 | Which sections mention subject Y? | R | Reader | `sections_for_subject` |
| S5 | What feeds does the base follow, and when did each last sync? | R | Curator | `find_feeds` |

**Guidelines and areas**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| G1 | What are base X's guidelines, by kind, and which version is the graph built under? | R | Curator | `find_guidelines`, `guideline_versions` |
| G2 | What would these guidelines extract from this section (a preview)? | R | Curator | `extraction_preview` |
| G3 | What is the vocabulary — terms, aliases, types — and which terms the graph never met? | R | Curator | `find_vocabulary`, `unused_terms` |
| G4 | What focus areas exist, with their terms, example questions and size; which are proposed and by whom? | R | Reader | `find_focus_areas`, `get_focus_area` |
| G5 | Which sources are in no area, and which areas have no source? | R | Curator | `unassigned_sources`, `empty_areas` |

**Builds**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| L1 | Is a build running on base X, how far, what failed? | R | Curator | `build_progress`, `find_builds` |
| L2 | What would a build do now (how many sections, how many characters, against the monthly limit)? | R | Curator | `build_estimate` |
| L3 | How much text and how many engine calls did base X use this month? | R | Curator | `base_usage` |

**The graph**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| K1 | What is the graph of base X — nodes, edges, communities, top relationships? | R | Reader | `graph_stats` |
| K2 | What are the communities, and what is in community C? | R | Reader | `communities`, `community_members` |
| K3 | What is subject Y — its type, aliases, statements, neighbours, the sections behind it? | R | Reader | `get_subject`, `subject_neighbors`, `subject_statements` |
| K4 | How are Y and Z related (a path)? | R | Reader | `graph_path` |
| K5 | The most connected subjects, and the surprising links between communities? | R | Reader | `god_nodes`, `surprises` |
| K6 | What subgraph answers "…"? | R | Reader | `graph_query` |
| K7 | Which subjects look like duplicates the vocabulary should merge? | R | Curator | `duplicate_subjects` |

**Asking**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| Q1 | **Answer this from base X (or area A), with citations** — the one tool behind every door | R | Reader | `ask` |
| Q2 | What evidence does base X hold on this (passages and statements, no composition)? | R | Reader | `search` |
| Q3 | What questions could base X / area A answer? | R | Reader | `suggested_questions` |
| Q4 | What did I ask, and what was answered? | R | Reader | `my_questions`, `get_question` |
| Q5 | What was asked of base X this week — by whom or which key, answered or not, rated how? | R | Curator | `questions`, `question_feedback` |
| Q6 | What could the base NOT answer (the gaps, clustered)? | R | Curator | `question_gaps` |
| Q7 | Which answers are pinned, by whom, which are due for review? | R | Reader | `pinned_answers`, `pins_due` |

**Keys, agents and proposals**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| A1 | Which keys exist for base X, their limits, last use and today's count? | R | Curator | `find_keys` |
| A2 | Which agents and applications may read which bases? | R | Curator | `base_readers` |
| A3 | What did the Archivist propose, and what was accepted? | R | Curator | `archivist_proposals` |
| A4 | Which dispatches are pending or failed? | R | Admin | `agent_dispatches` |

**Activity**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| ACT1 | Who did what to this base or source, in order? | A | Reader | `record_history` |
| ACT2 | What did person, key or agent X do here this week? | A | Curator | `actor_timeline` |
| ACT3 | What changed since I last looked? | A | Reader | `recent_activity` |
| ACT4 | Which siblings read us, and how often? | A | Admin | `share_reads` |
| ACT5 | Search the activity for a phrase | A | Reader | `activity_search` |

Plus the contract's: `app_roles`, one guarded `search` on each server (the records one IS Q2), `record_history`. Records
tools: about 42; activity tools: 6.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit from Consultant Tracking.
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it and the `shares[]` alone.
- **Agents**: run tokens on the two read servers (the run-facts gate, fail closed); the Actions MCP from the registry; the
  approval hook before every action with a category; the chat endpoint for the command bar and dispatched proposals; the duty
  for the Archivist.
- **The engine**: `MALUDB_KB_API_URL` and `MALUDB_KB_API_TOKEN` (the knowledge memory database's own token, minted at install
  by `deploy/ROOT_STEPS.sh`), read only by `app/knowledge/engine.php`; a missing token makes every build and ask answer
  `engine_unavailable`, never a crash. The model alias and provider live in MaluDB's config; the admin's screen shows them and
  changes them through `POST /v1/memory/config` with a pause.
- **Metering (K24, built)**: MaluDB's provider `base_url` for this memory database points at the kernel's ledger proxy
  (`http://127.0.0.1:8816/openai`, or `/anthropic`) with the application's model credential
  (`bin/application_model_credential.php mint --app knowledge --models … [--budget …]`), so every extraction, embedding and
  answer call is priced, ledgered and stamped with the application and lands on the AI statement. Every model, the embedding
  model too, must be registered with a price in the kernel's model registry.
- **The token API** (bearer = a key; allow-listed in the vhost): `POST /api/v1/ask {question, focus_area?, conversation_id?}` →
  `{question_id, status, answer_md, confidence, citations[{section: {id, heading_path, source, page}, quote, url}], evidence[],
  conversation_id}`; `POST /api/v1/search {query, limit}`; `GET /api/v1/bases`; `GET /api/v1/questions/{id}`; `POST
  /api/v1/questions/{id}/feedback`; `GET /api/v1/health`. 401, 403, 429 with `Retry-After`, 503 `engine_unavailable`. Logged
  `question.ask` with source `api`.
- **K7 — Knowledge is a provider.** Shares, every one a records-server tool, people-free:
  | Tool | For | Document | Content |
  |---|---|---|---|
  | `ask` | Help Desk's expert, Projects' expert, Spaces' expert, Consultant Tracking | `os.knowledge-answer/1` | `{base, question, focus_area?}` → the cited answer, **only for bases shared with the consumer application** |
  | `search` | the same | `os.knowledge-evidence/1` | evidence over the shared bases |
  | `bases_index` | the same | `os.knowledge-bases/1` | the bases shared with the consumer: key, name, areas, last built |
  Reads (`reads[]`): `spaces.pages_index` + `spaces.page_markdown` (a space as a feed, when Spaces is built) and
  `helpdesk.articles_published` (HD2, owed) — absent, a feed shows `no_connection`.
- **Public doors** (allow-listed in the vhost): `/api/v1/*` (key-checked), `/mcp/records`, `/mcp/activity`.
- **Live-ness without a socket**: the ask window posts and waits up to 20 s for M1; the expert path answers 202 and the window
  polls every 3 s; a build's progress polls every 5 s while the page is visible.

## 9. Screens (the nxl look; the ask window and a source are read at 375 px first; the graph explorer is designed at 1280 px and
works at 375; no modals; cards for named things — bases, sources, areas, pinned answers —, tables for the question log, builds,
keys and admin lists; every name a link; every page a way back)

**The shell**: a sidebar at 1280 (Home · Ask · **Bases** (each base: Ask, Sources, Areas, Graph, Guidelines, Builds, Questions,
Keys, Share, Settings) · Pinned · Admin), a bottom tab bar at 375 (Home · Ask · Bases · Me). The command bar at the top of
every screen.

**Home** — my bases as cards (status, size, last built, "needs a rebuild"); builds running with progress; my recent questions;
pins due for review; the Archivist's last note; curators: the week's gaps and thumbs-down; admin: usage by base, failed builds,
pending dispatches. **A new person's home is one button: "Create a knowledge base".**

**Ask** — `/ask` (choose a base, optionally an area; the conversation; each answer with its **citations as chips** that open
`section-view` at the page with the quote highlighted, "show the evidence", thumbs with a comment, "pin" (curators), "ask the
expert" when evidence-only; suggested questions when empty) — the Reader's screen, 375 px first.

**Bases:** `/bases` (cards; **create**: name, template, done) · `base-view` (description, counts, areas, last build, status,
an ask box, the curators, **"Add documents" as the first action of an empty base**) · `base-form` · `base-share` ·
`base-settings`.

**Sources:** `sources` (list with kind, status, sections, area chips, stale/failed/no-text badges; **add**: drop many files at
once, paste text, add a URL, follow a feed) · `source-view` (title, origin, status and errors, the outline, the pages, the
areas, "what it taught the graph", replace, remove) · `section-view` (one section, its heading path, prev/next, the
page) · `feed-form`.

**Guidelines and areas:** `guidelines` (the five kinds; the template's shipped set as the starting point; version and "built
under N"; "needs a rebuild") · `guideline-form` (the kind's examples beside it; **Preview on a section**) · `vocabulary`
(terms; import CSV; "never met"; merges) · `areas` (cards; the **proposed** ones with accept / dismiss; "propose areas" when a
build has finished and none exist) · `area-form` · `area-view`.

**Builds:** `builds` (the estimate against the limit, start, rebuild all paused for agents, the list) · `build-view` (progress,
per-section status and errors, counts, usage, cancel).

**Graph:** `graph` (stats; communities; god nodes; surprises; search) · `subject-view` (type, aliases, statements by verb,
neighbours, the sections behind it, a small SVG neighbourhood at 1280, "ask about this") · `community-view` · `path`.

**Questions:** `questions` (the base's log; filters) · `question-view` · `gaps` · `pinned`.

**Keys:** `keys` (label, base, area, limits, last used, today's count; mint — shown once; rotate; revoke) · `key-form`.

**Admin:** Settings · Templates (read-only) · Model (shown, changed with a pause; "test extraction") · Usage · Bases (all;
private ones logged) · Keys (all) · Agents · Connections · Archivist proposals · My settings · My MCP tokens.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document and the owner's answers; the schema `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`: every actor of §2; a private and an internal base and who sees each for seven actors incl. a guest, an agent and an application principal; a source with pages and sections; a guideline save bumping the version and marking sections stale; a vocabulary term; an area; a build with items and the one-at-a-time rule; a question with citations to sections, feedback and a pin becoming a note source; a key, its counters and a 429; a feed; proposals; retention); the kit proven without a kernel (`tests/phase0/run.sh`); **the sectioner against fixtures** (`tests/sectioning/`: one synthetic document of each kind — DOCX, HTML, Markdown, PPTX, XLSX, CSV, text, PDF text — cut into the expected sections); **the engine proof against the live MaluDB API on a scratch memory database** (`tests/phase0/engine.sh`: configure a namespace, seed subjects, ingest sections, preview, graph query, communities, forget, and **answer** — recording what the API returns, with M1 on its branch); `maludb-os.json`, `os/{expert,archivist}.md`, nine skills (five runbooks), `deploy/` with `ROOT_STEPS.sh` (ports, then the memory database, its token and K24's credential); the installer's `plan` clean | the owner's go |
| 1 | `docs/knowledge-mcp-tool-surface.md`, `docs/knowledge-action-manifest.md`, `mcp/action_registry.json`, `docs/build-specs/guidelines-compile.md`, `docs/build-specs/sectioning.md`, and the slice specs (`sso-shell` and nine slices; **sources-and-sectioning and ask are the exemplars**) | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles`, `kn_has_right()`, the ingest bridge, the shell, `/api/v1/health`, the vhost allow-list, the engine module with `engine_unavailable` | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; 375 and 1280 |
| 3 | the slices, in order: **(1) bases, templates, sharing and settings** (the CRUD exemplar) → **(2) sources and sectioning — THE FIRST EXEMPLAR** (upload many, paste, URL; extraction per kind; sectioning; the outline and section view; pages; replace and remove; no-text and failures) → **(3) guidelines, vocabulary and focus areas** (the five kinds, the template's shipped set, versions, the compile step, preview on a section, areas, assignment) → **(4) the build** (estimate, queue, the worker ingesting section by section with progress, forget-then-ingest for stale ones, reindex and embeddings, counts, failures and retries, cancel, rebuild-all paused, notifications) → **(5) ask — THE SECOND EXEMPLAR** (`kn_ask()` through M1 with the evidence fallback, the window at 375, citations opening the section, the evidence panel, no-evidence and unverified, gaps, conversations, feedback, pins as notes, suggested questions, the expert path with 202 polling) → **(6) the graph explorer** → **(7) the API, keys and K7** → **(8) agents, proposals and feeds** → **(9) home, admin, usage and tokens** | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database and namespace |
| 4 | the two MCP servers with the run-facts gate, `app_roles` and the three `shares[]` admitted to the kernel's token, the registry wrapper, the two agents with their grants and the duty; the Actions-MCP contract proved against the kernel's `application_actions.register()` | the command bar answers a question from a base with two citations; an agent's `key_mint` pauses; the Archivist mints nothing, deletes nothing, shares nothing |
| 5 | the owner's `app_install.php apply` (ports pinned first), the memory database and its token, **the K24 credential in MaluDB's provider config**, the API 0.4.0 merge and restart, DNS and TLS, the hires, **any dozen documents the owner chooses** | **a curator drops a dozen documents (PDF and Word) into a new base on a desktop and watches the build finish; the expert proposes areas from the graph and the curator accepts two; a Reader on a phone asks and gets a two-paragraph answer with citations that open the sections; a question the sources do not cover comes back "nothing on this" and appears in the gaps; a thumbs-down, and a pin that becomes a note source; a key minted and `POST /api/v1/ask` answered from another machine, the 31st call in a minute refused with 429; (once K26 lands) Help Desk's expert reads `ask` over an approved connection and is refused for a base not shared with it; the Monday note names a stale source; the kernel's statement shows the extraction tokens** |

**Extended** (§11). Size: ten specs (sso-shell + nine slices), about 40 screens, 70 actions, 42 records tools, 6 activity tools —
below Help Desk because the engine is MaluDB's. **Three days is the honest estimate**: sources and sectioning one, the build
and the ask window one, the explorer, API and agents one; Phase 5 with the owner the remainder.

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order): **structured documents** — laws and codes parsed into numbered provisions
with defined terms, cross-references, amendments proposed by a parser and confirmed by a curator, editions, jurisdictions and
effective dates, answers as of a date (`structured-documents-extended.md`, the design written for the fire-code example) · the
public widget (a key with allowed origins, a CORS door, a snippet) · OCR (tesseract) · a site crawl · per-member MaluDB
principals · community reports on every build · PageIndex when MaluDB v4 ships it · audio and video as transcripts · answer
export · K8 wakes · a base copied as a template · per-question cost shown to the asker · **evals**: a base's example questions
with expected citations run as an eval suite by the kernel's runner before and after a guideline change · more templates
(contracts, policies, manuals) as the owner names them.

**Later**: a base shared between two installations; training or fine-tuning of anything (never).

## 12. What the kernel, MaluDB and the siblings owe this application

| # | Item | State |
|---|---|---|
| **K24** | **An application's credential at the ledger proxy** — revocable, shown once, models allowlisted, an optional monthly cap, embeddings included; every call stamped with the application and no agent so it lands on the AI statement | **BUILT 2026-10-05** (kernel db/171, `bin/application_model_credential.php`, spec `docs/build-specs/kernel-application-model-credentials.md`, 14 proxy checks). **The runner needs a restart to serve it** (the owner's) |
| **K25** | **Catalog entry `knowledge`** (kind `ours`, Operations, category `documents`, `feather-book-open`) | **BUILT 2026-10-05** (kernel db/170, applied) |
| **M1** | **`POST /v1/memory/answer`** in the MaluDB API | **BUILT 2026-10-05 on branch `feat/memory-answer-m1`** (worktree `/home/maludb/maludb-api-m1`, API 0.4.0, commit 53f01ff): 24 unit tests, the suite (645) and ruff green, a live scratch-tenant proof. **Not merged or deployed — the owner's** (merge, restart `maludb-api`, then set the tenant's `answer` model). Until then the window shows evidence |
| **M2** | Usage on every model-calling response | **partly**: the answer route reports usage; ingest, documents and the reindex sweeps do not yet. K24 makes it nearly moot (the proxy meters) |
| M3 | `recall` and `graph/query` accept a **`project`** filter; `recall` exposes semantic entity search | owed upstream; the answer route already confines to a project's subjects |
| K16 | **K8 (wake an agent from an application)** — owed to four siblings | the chat-turn dispatch until then |
| HD2 | **Help Desk shares `articles_published`**; Spaces' `pages_index` + `page_markdown` are in its plan | a curator uploads |
| HD3 / SP2 / P3 | Help Desk's, Spaces' and Projects' experts gain `reads[]` on `knowledge.ask` / `search` / `bases_index` | nothing reads us yet |
| **M5** | A documents **dry run**: extract one text with the namespace's prompt and model and write nothing | owed upstream; the preview shows the prompt |
| **M6** | **Community detection** over a namespace (communities exist only when written today) | owed upstream; the expert proposes areas from the graph |
| **M7** | **Subject routes**: allocate ids from the table's sequence (today `MAX+1`, which breaks a later extraction) and an `aliases` field on `POST/PATCH /v1/subjects` | owed upstream; `engine.php` works around both |
| **K26** | **K7 tells a provider who is asking**: the kernel sets a reserved `consumer` argument (the caller's catalog key, overwriting anything sent) as it already does for `scope_id`, and allows a **per-share timeout** (today 8 s per MCP post; an `ask` that calls a model needs up to 60 s) | **owed to the kernel (not built).** Until it lands the three shares refuse with `consumer_unknown` and share nothing; the window, the API and the agents are unaffected |
| **API defect** | `DELETE /v1/projects/{id}` leaves the project's subject links, and a reused id inherits them (found proving M1; `docs/memory-answer.md` there) | owed upstream; `engine.php` unlinks first and clears stale links on a reused id (proven) |

## 13. The decisions

The sixteen decisions of the first plan, answered the same day with every recommendation taken, stand with the revisions of
§15 (R1–R3). The four that the fire-code example added — a base per adopted edition, parser-proposed amendments, quote-only
answers as of a date, the owner supplying a licensed corpus — belong to the Extended structured-documents module, and the
licence question is withdrawn: the use case is an example for development and specs.

## 14. Ports, names and files

- Repository `/srv/apps/knowledge` (origin `github.com/maludb/maludb-os-knowledge`); record database `<tenant>_knowledge`
  (`subello_knowledge`); roles `knowledge_rw`, `knowledge_records_ro`, `knowledge_activity_ro`; **memory database
  `<tenant>_knowledge_memory`** (`subello_knowledge_memory`; schema and role `knowledge_mem`; `maludb_core` +
  `enable_memory_schema`; its API token minted by `ROOT_STEPS.sh` into `config/.env` as `MALUDB_KB_API_TOKEN`); the tenant's
  memory (`certstudy_memory`) keeps the activity episodes tagged `knowledge`.
- `knowledge.<domain>` (`vhost.label = "knowledge"`); `APP_INTERNAL_PORT=8187`, `MCP_RECORDS_PORT=8835`,
  `MCP_ACTIVITY_PORT=8836` **all three in `env.required`** and written to `config/.env` before `apply`; `deploy/` files are
  templates (`{{DOMAIN}}`, `{{APP_DIR}}`, `{{APP_KEY}}`, `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`).
- Units: `knowledge-records-mcp`, `knowledge-activity-mcp`, `knowledge-activity-ingest` (+ timer), `knowledge-directory-sync`
  (+ timer), `knowledge-worker` (+ timer, every minute: text extraction and sectioning, builds, reindex and embeddings, feeds,
  URL checks, the outbox, rate-counter pruning, retention, dispatch retries).
- `maludb-os.json`: `catalog_key knowledge`, `name "Knowledge"`, `business_area "Operations"`, `category documents`, `icon
  feather-book-open`, `criticality medium`, `vhost.label knowledge`, no `scopes`, `sso`, `directory {reads: true, writes:
  false}`, `assistant {command_bar: true, agent: expert}`, `agents [expert, archivist]` each `hired_on_install: true`,
  `approvals` (§5), endpoints, services, `shares` (three), `reads` (three).
- Env: the contract's required keys plus `MALUDB_KB_API_URL`, `MALUDB_KB_API_TOKEN`, `MALUMAIL_API_KEY`, `MAIL_FROM`,
  `MAIL_FROM_NAME` (optional), `ATTACHMENT_MAX_BYTES`, `KN_URL_FETCH`, `KN_PDFTOTEXT`.
- Vhost allow-list (public): `/api/v1/`, `/mcp/records`, `/mcp/activity`.
- Kernel files: `mcp/registries/knowledge.json` (made by the installer), db/170 (K25), db/171 (K24).
- Proof scratch ports: 8501–8507. Fixtures: `tests/sectioning/` — synthetic documents written for the proofs; no third-party
  text is committed.

## 15. The owner's answers

Every recommendation of the first plan was taken on 2026-10-05; the later rulings below revise it.

| # | Decision | Where it lives |
|---|---|---|
| D1 | **"Knowledge", key `knowledge`**, `knowledge.<domain>`, databases `<tenant>_knowledge` and `<tenant>_knowledge_memory`, roles `knowledge_rw` / `_records_ro` / `_activity_ro`, prefix `kn_` | `maludb-os.json`; §14 |
| D2 | **Four roles**: Reader, Member (`user`), Curator, Knowledge admin; the admin sees private bases, logged `base.admin_view` | §3; `kn_roles`; `app_roles` |
| D3 | **A memory database of its own**, one namespace `kb:<id>` per base, made by `ROOT_STEPS.sh`. **Owed before Phase 5: settle the pgaudit setting** (it logs every MaluDB query and the disk has filled once) | §6; §14 |
| D4 | **MaluDB does extraction, embedding and answering**; answers through **M1**, metering through **K24** | §6.3; §12 |
| D5 | **Private or internal bases**, shared to members, departments, everyone, agents and applications; guests may be Readers; **a new base is private**; no site scope | §3; `base_members` |
| D6 | **Sources**: files first (PDF with a text layer, Word and Office, HTML, Markdown, text, CSV, JSON), pasted text, one page per URL, notes, feeds; **50 MB per file, a setting**; no OCR in v1 | §6.1; `kn_settings` |
| D7 | **Five guideline kinds, two moments, shipped by template**; every form previews on a section | §6.2; slice 3 |
| D8 | **Focus areas are MaluDB projects**, proposed by the expert from the graph after a first build (communities when MaluDB computes them — M6) | §6.2; slice 3 |
| D9 | **Auto-extract new sources**; a person starts rebuilds after an estimate; **50 MB of text per base per month**; one build per base at a time | §6.2; `builds` |
| D10 | **Every answer cites or declines**; M1 verifies every quote; gaps logged; three turns of conversation; pins become `note` sources | §6.3; slice 5 |
| D11 | **Question log** with asker; curators see their base's; 365 days | §6.3; `questions` |
| D12 | **API and siblings**: keys per base at 30/min and 2,000/day with 24 h rotation overlap; `ask`, `search`, `bases`, feedback; K7 shares `ask`, `search`, `bases_index` | §8; slice 7 |
| D13 | **Expert and Archivist** hired on install; the pause list of §5 | §5; `agents[]` |
| D14 | **No source, section or question text in any log**; sensitivity passed to the engine; the MaluDB token read in one file | §6.4; `engine.php` |
| D15 | **On request, not a default; the repository stays private until the owner says** | §12; the org |
| D16 | **Ports 8187 / 8835 / 8836**, scratch 8501–8507; the Spaces division: the planning model builds the kernel items, Phase 0, Phase 1, Phase 2, slice 1 and the exemplars (slice 2 sources and sectioning, slice 5 ask, with 3 and 4 between); Sonnet 5.5 builds slices 6–9 and Phases 4–5 | §14; `CLAUDE.md` |
| **R1** | **The content does not matter; the product is general** — upload documents, create a knowledge base. The regulatory template, legal parsers, amendments, jurisdictions and as-of answers leave version 1 and are the Extended **structured-documents** module (`structured-documents-extended.md`). The unit is a **section**, not a provision. *Supersedes A1, A2, A3 and D17–D19 of the first revision.* | §1; §6.1; §11 |
| **R2** | **Licences are not a gate**: the fire codes were an example for development and specs. *Withdraws D20 and the D15 and D20 tightenings.* | — |
| **R3** | **K24, then M1, then Phase 0**, in that order (the owner, 2026-10-05) | §12; §16 |

## 16. State

**2026-10-05 — Phase 0 complete and Phase 1 written; AWAITING THE OWNER'S CHECKPOINT.** Plan approved and revised to the general
product (R1); K24 (kernel db/171) and K25 (db/170) built; M1 built on API branch `feat/memory-answer-m1`, awaiting the owner's
merge. Phase 0: the schema (db/001–015; 264 + 80 checks), the engine module (134 checks against the real API), the sectioner
(250), the kit (92, without a kernel), `maludb-os.json`, the agents' files, nine skills, `deploy/`; the installer's `plan` reads
the repository with no errors or warnings. Phase 1: `docs/knowledge-mcp-tool-surface.md` (61 records tools, 6 activity tools),
`docs/knowledge-action-manifest.md` (61 screens, 62 actions, 20 paused for an agent), `mcp/action_registry.json` (123 entries),
the guidelines-compile and sectioning specs and ten slice specs (sso-shell + nine); `docs/phase1-consistency.md` records every
cross-document reconciliation. **Owed to the owner:** approve Phase 1 as a whole; merge M1 and restart `maludb-api`; restart the
runner (K24); settle pgaudit before Phase 5; rule on K26 (the kernel tells a K7 provider who is asking, and a per-share timeout —
until then the three shares refuse). **Next:** Phase 2 (`/sso`, the mirror and the shell on Apache) and slices 1–5.
