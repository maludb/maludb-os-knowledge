# Knowledge — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-05 · the business's **knowledge bases**: a person uploads documents, sets guidelines, names the areas they care
about, and the application builds a **knowledge graph** in MaluDB that is asked three ways — an **ask-me-anything window**,
a **token API** other systems call, and the kernel's agents (the command bar, a mention in Spaces, a sibling's K7 read).
Its own repository `/srv/apps/knowledge` (origin `github.com/maludb/maludb-os-knowledge`), served as `knowledge.<domain>`
(`knowledge.subello.com` here), built with `htmx-php-builder` (how it is built) and fitted to `maludb-os-integration` 0.7.0
(how it fits), exactly as HR, Projects, Help Desk, General Ledger, Consultant Tracking and Spaces were.
**`/srv/apps/spaces/docs/spaces-design.md` is the nearest plan** (the same shape, the same owner's rules; the same day);
`/srv/apps/helpdesk` and `/srv/apps/consultant_tracking` are the nearest **built** exemplars (their kit, specs and proof
suites are what this repository copies). The engine is **MaluDB** — `maludb_core` 0.106.0 and the MaluDB API 0.3.1 as they
run on this server today (§0.6) — and the kernel's own agent memory (`docs/build-specs/agent-memory.md`, `memory-screen.md`
in `maludb-os-core`) and Help Desk's small knowledge base (`articles`, `kb_sections`) are the prior art this design keeps or
overturns by name.

> **What this document is.** The plan for the application, in the kernel's words, for the owner's go, and the brief a
> worker model builds from. **Checkpoint.** This document and the owner's answers (§13, recorded in §15) are Phase 0's
> first half; the schema in `db/`, `maludb-os.json`, `os/`, `skills/` and `deploy/` complete it. Phase 1 adds
> `docs/knowledge-mcp-tool-surface.md`, `docs/knowledge-action-manifest.md` and the slice specs in `docs/build-specs/`.
> **No feature PHP is written until the owner approves them together.** The decisions this document needs from the owner
> are §13; §15 records the answers when they come.

## 0. The research — what the field calls this, product by product, and what this design does with each

The owner's words: *upload documents, set guidelines, build a knowledge graph, specify the areas of focus, ask it anything
in a window or through an API from other applications.* Six products and one engine were held against those words.

### 0.1 Google NotebookLM — "a notebook of sources, answered with citations"
A notebook holds up to 50 **sources** (PDF, Docs, Slides, websites, pasted text, audio); every answer is **grounded in the
sources and cited inline**, and a citation opens the exact passage; a "notebook guide" offers a summary, suggested
questions, an FAQ and a briefing; an answer can be **saved as a note**, and a note can be **turned into a source**; a
notebook is shared read-only or as a collaborator. No graph, no API, no guidelines beyond the question. **Kept here:**
sources as the unit people understand, inline citations that open the passage (page and section), suggested questions per
area, "pin this answer" turning an answer into a source (kind `note`), sharing a base. **Dropped:** audio overviews (Extended),
YouTube and Google-only sources, the 50-source cap.

### 0.2 Claude Projects and custom GPTs — "instructions plus knowledge"
A project is **instructions** (how to answer, what you are, what to refuse) plus **knowledge files** plus the conversations
had in it. That is the simplest statement of the owner's "guidelines" — and it is one text box, which is where it stops
working for a corpus of hundreds of documents: nothing says *what to extract*, *what the words mean*, or *what never to
say*. **Kept here:** guidelines as first-class text a non-technical person writes; conversations scoped to a base.
**Changed:** guidelines have **five kinds** (extraction, vocabulary, exclusion, answering, audience — §6) because they act
at different moments: three shape the graph when it is built, two shape every answer.

### 0.3 Microsoft GraphRAG, LightRAG and their kin — "entities, relationships, communities"
GraphRAG extracts entities and relationships with a model, clusters them into **communities** (Leiden), writes a **community
report** for each, and answers two kinds of question differently: **local** (an entity and its neighbourhood: "what does the
warranty say about batteries") and **global** ("what are the main themes of these contracts" — a map-reduce over community
reports). Its **prompt tuning** generates a domain-specific extraction prompt from a sample of the corpus. LightRAG keeps
the lesson at a tenth of the cost: retrieve at two levels, specific entities and high-level themes. **Kept here:** the
local/global distinction (a question scoped to a focus area or asked of the whole base; the graph explorer shows communities,
god nodes and surprises); **prompt tuning is what guidelines and focus areas are** — the extraction prompt a base uses is
compiled from them; **a community proposes a focus area** (the expert reads the communities after the first build and
proposes areas a person confirms in one click). **Changed:** community reports are written on request by the expert, never
for every community on every build (the cost is the owner's to say yes to).

### 0.4 Glean, Guru, Notion AI, Confluence — "enterprise answers whose permissions follow the source"
The enterprise tools answer across the company's systems and show a person only what they may already see; Guru adds
**verified answers** — a card an expert re-verifies on a schedule — and **feedback** on every answer. **Kept here:** a base
is read by exactly who it is shared with (a member, a department, everyone, an agent, a sibling application), and the
engine never answers from a base the asker may not read; a curator **pins** an answer (verified, with an owner and a review
date, as Spaces' wiki pages have); thumbs on every answer; the questions nobody could answer are a report. **Dropped:**
crawling other systems — a sibling's content arrives as a **feed** through K7 (Spaces' pages, Help Desk's articles, §8),
never by connector.

### 0.5 The hosted RAG builders (OpenAI file search, Pinecone Assistant, Dify, AnythingLLM, Open WebUI) — "a workspace with
documents, a system prompt and an API key"
The shape every developer expects: a workspace, documents in, a system prompt, an **API key** per workspace, `POST /ask`
answering text plus citations, rate limits, and an **embeddable widget**. **Kept here:** the API exactly that shape (§8),
keys bound to one base (and optionally one focus area), rate limits per key, the question log per key. **Extended:** the
widget (a public ask door behind a key with allowed origins).

### 0.6 MaluDB — the engine this application is a face for (as installed on this server: core 0.106.0, API 0.3.1)
The owner maintains MaluDB; this application re-implements nothing of it. What it gives today, by route:

| Need | MaluDB today | How this design uses it |
|---|---|---|
| Text in, graph out | `POST /v1/memory/documents` (title, text, `namespace`, `projects[]`, `subjects[]`, `verbs[]`, chunking) and `POST /v1/memory/ingest` (text, **`hints[]`**, `namespace`, **`preview`**) — the model extracts **subjects, verbs and statements** with source spans (the SVPOR graph); `DELETE /v1/documents/{id}` **forgets** a document and everything it taught (0.106.0) | a **source** becomes one MaluDB document in the base's namespace; **a build** is these calls; `preview` is "try my guidelines on this one source"; removing a source forgets it |
| A domain's extraction prompt | `POST /v1/memory/config` **per namespace**: the model alias, `prompt_template` (with `{{chunk}}`), `generation_params`, `default_subject_type`, the embedding model | **a base's compiled extraction guidelines ARE its namespace config** — one namespace per base, so two bases extract differently |
| Areas | **projects** (`/v1/projects`: a subject of type `project` with `classifier_md`, `subjects[]`, `verbs[]`, `documents[]`) | **a focus area is a MaluDB project**: its description is the classifier, its seed terms its subjects; a source assigned to an area is ingested with `projects: [area]` |
| Vocabulary | subjects with **aliases** and a **subject type** (`/v1/subjects`, the type catalog); the extractor is handed the known subjects and verbs and only creates what is absent | **vocabulary terms are seeded as subjects before the first build** so "PG17", "Postgres 17" and "PostgreSQL 17" are one node |
| Asking | `GET /v1/graph/query` (lexical seeds + a bounded walk, `namespace`-scoped) · `POST /v1/memory/recall` (several namespaces, subject candidates proposed from the question) · `/v1/graph/{neighbors,walk,path,god-nodes,surprises,stats}` · `/v1/communities` (+ members) · `GET /v1/memory/notes` (free text parsed against the verb catalog) | the **evidence** step of every answer, and the whole of the graph explorer |
| Answering in words | **none** — the API calls a model for extraction, parsing and skill judgements, never to compose an answer | **M1** (§12): `POST /v1/memory/answer` — retrieve + compose with the namespace's model, answer, citations, usage; until it exists the window shows evidence and offers the expert |
| Who may read | principals and scopes (`X-MaluDB-Principal`, `-Scopes`, `-Readonly`; a document lives in the namespace of its first edge; sensitivity ceilings) | v1 enforces reach in the application (one visibility function); principals per member are Extended (§11) |
| Keeping it fresh | `/v1/memory/reindex/run`, `/v1/memory/embeddings/run` (entity-card embeddings — the "semantic spine"), the memory-maintenance routes | the worker runs both after a build |
| Long documents | PageIndex (a tree of summaries per document, "vectorless" navigation) is MaluDB's **version 4 plan**, not shipped | v1 keeps a source's **pages and headings** itself so a citation says *page 12, "Warranty"*; when PageIndex ships, the tree is the engine's |

What the application adds and MaluDB does not have: files and their **text extraction** (PDF, Office, HTML, Markdown — §6),
page and section anchors for citations, the five kinds of guideline and their compilation, focus areas as a person sees
them, builds with progress, cost and a rebuild rule, the question log with feedback and gaps, bases and who may read them,
API keys with rate limits, the K7 shares, and the two agents. The owner's rule that *an application never holds a model
key* holds: MaluDB holds the extraction model's key as it does today for the tenant's memory, and the kernel meters the calls
through its ledger proxy (K24, §12).

## 1. What Knowledge is, and is not

Knowledge **owns the business's curated corpora and what can be asked of them**: a **knowledge base** per subject matter —
the product manuals, the policies and contracts, a client's regulatory filings, a research pile — each a set of **sources**
with **guidelines** and **focus areas**, built into a graph in MaluDB, and asked by people, by agents and by other systems.
It is NotebookLM with a graph, a Claude Project with an API, and GraphRAG with a face, with the differences that matter here:

- **The graph is MaluDB's, and MaluDB is the owner's.** Nothing of extraction, embedding, storage or traversal is written
  here; a gap in the engine is fixed upstream (§12 names the four this plan needs). The application is files, guidelines,
  areas, builds, asking, keys and who-may-read.
- **Guidelines are first-class and typed.** A person writes, in words, what to pull out of the documents, what the words
  mean, what never to extract or say, how to answer and for whom. Three kinds change the graph (and mark the base "needs a
  rebuild"); two change only the answers (and apply at once). `preview` lets them try a guideline on one source before
  spending on a build (§6).
- **Focus areas are the person's map and the engine's projects.** An area has a name, a description, seed terms and example
  questions; it steers extraction and scopes questions; the first build's communities **propose** areas a person confirms.
- **Every answer cites or declines.** An answer carries its evidence (statements and passages) and citations that open the
  source at the page; weak evidence is a plain "the knowledge base holds nothing on this" and a logged gap — never a guess.
- **Three doors, one gate.** The window, the API and the agents all pass one visibility function and one `ask()`; a sibling
  application reads a base through K7 only when the base was shared with it; a key reaches one base.
- **Full MCP coverage is a requirement**, not a feature: every question a screen answers is a records tool, every button an
  action tool (`maludb-os-integration`, `mcp-and-api.md`) — and every MCP tool that *asks* returns the same cited answer.
- **Nothing of identity lives here.** The kernel signs people in; the application mirrors the directory; a guest is a kernel
  member flagged external; a system with no account holds a key.

Not in version 1: OCR of scanned PDFs and images (flagged "no text"; Extended); audio and video transcripts; crawling a
whole website (one page per URL in v1; a sitemap crawl is Extended); the embeddable public widget; per-community reports
written automatically; MaluDB principals per member (the application enforces reach); an answer written by a model **until
M1 ships** (the window shows evidence and offers the expert meanwhile); fine-tuning or training of any kind.

## 2. Who uses it (the actors, in the OS's words)

**A word about words.** "Agent" always means an OS AI agent. A **member** is a kernel member mirrored here; a **guest** is a
member flagged external holding the Reader role; a **knowledge base** ("base" in table and function names) is a named corpus
with its guidelines, areas and graph; a **source** is one document, pasted text, fetched page or fed record; a **guideline**
is one instruction of one kind; a **focus area** is a named area of the base; a **build** is one extraction run; a
**question** is one ask with its answer, evidence and citations; a **key** is an API key bound to a base.

| Actor | Who | How they reach the application |
|---|---|---|
| **Reader** | Anyone a base is shared with: asks, browses the sources and the graph of the bases they may read, rates answers | The launcher → `knowledge.<domain>`; the phone (the window is used at 375 px first); the command bar |
| **Member** | Everyone inside the business holding the base role: a Reader who may also **create a base of their own** and add sources to bases where given curate | The same |
| **Curator** | Owns or curates a base: its guidelines, focus areas, sources, builds, sharing, keys, pinned answers, the question log | A base's settings |
| **Guest** (external) | A client whose base an agency built for them, an auditor: a kernel external holding Reader; sees nothing but the bases shared with them (db/159 opens the launcher to them) | The launcher, to a one-base home |
| **Knowledge admin** | Every base; settings; the model configuration (read from MaluDB, changed with a pause); limits and retention; every key; costs. A super-admin holds it | The admin area |
| **An agent** | Any hired agent granted Reader (the shipped **expert** and **Archivist** on install, any other by a super-admin): asks and browses the bases shared with it like a member; the expert answers the command bar and Spaces mentions from them | Records MCP (run token) · Actions MCP (relayed) · a chat turn · a duty |
| **An outside system** | Holds a key: asks one base through the token API, rate-limited, every question logged under the key's label | `POST /api/v1/ask`, `/api/v1/search` (§8) |
| **A sibling application** | Help Desk's expert answering a ticket from the product manuals; Spaces' expert; Projects | K7 `ask` / `search` / `bases_index` over an approved connection, only for bases shared with that application |
| **The kernel** | Signs people in, delivers the directory, hires and runs the agents, pauses their actions, answers the command bar (the chat endpoint), meters MaluDB's model calls (K24), carries the siblings' reads (K7) | — |
| **System** | The worker: text extraction, builds, reindex and embeddings, feeds, the outbox, retention, rate counters, the Archivist's wake | `source = 'cron'`, every row logged |

Tenancy: the business running the application is the tenant — one installation, one record database, **one memory database
of its own** for the bases (§6, §13.3) beside the tenant's agent memory, whose episodes still carry this application's activity.

## 3. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`; ordered as shown). The base role's key is
`user` so the installer's `--grant-standing-departments` makes every insider a Member when the owner installs it that way.

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `reader` | Reader | read | `bases.read` (the bases shared with me: ask, browse sources and the graph, see my own questions), `answers.rate` |
| `user` | Member | write | Reader's + `bases.create` (a base of my own — I am its curator), `sources.add` (where given curate), `questions.own` |
| `curator` | Curator | write | Member's + on my bases: `base.manage` (name, visibility, areas, guidelines, vocabulary, settings), `sources.manage` (add, replace, remove — removing pauses for an agent), `builds.run`, `base.share` (to members and departments; to a guest or an application pauses for an agent), `keys.manage` (mint, rotate, revoke — pauses for an agent), `answers.pin`, `questions.review` (every question asked of my bases, who asked, the ratings, the gaps), `feeds.manage` |
| `admin` | Knowledge admin (is_admin) | admin | every right on every base + `settings.manage`, `model.manage` (the MaluDB namespace config — pauses), `limits.manage`, `retention.manage`, `keys.all`, `costs.read`, `agents.settings`; **sees private bases, logged `base.admin_view`** |

The `curator` role is application-wide capability; who curates *which* base is `base_members.level = 'curate' | 'own'`. A
Member who creates a base owns it without holding the role; the role exists so the super-admin can name people who may
curate bases they did not create and so the ladder is visible in the kernel's claims.

**Reach** (`kn_visible_base_ids()` as ONE PL/pgSQL function returning the caller's set, tested once per statement in every
view — the kernel's db/160 lesson; `kn_can_read_base(id)`, `kn_can_curate_base(id)`):

- **A base is private or internal.** Private: read by its `base_members` only. Internal: read by every member of the
  business (the `everyone` principal), curated by its members with `curate`/`own`. Guests never see an internal base unless
  named. The admin sees everything (logged).
- **`base_members (base_id, principal_kind member|department|agent|application|everyone, principal_id, level read|curate|own)`**.
  A department share reaches its members live (the mirror). An **application** principal (a sibling's catalog key) is what
  K7 checks: a sibling reads only bases shared with it. An agent is a member principal like any other.
- **Sources, guidelines, areas, builds, the graph and the question log follow their base.** A Reader sees sources and the
  graph (that is how they trust an answer) but **not** the guidelines, the keys, other people's questions or the costs.
- **A question belongs to its asker and its base**: the asker sees their own; the base's curators see all of the base's;
  a question asked through a key shows the key's label as the asker.
- **The engine is asked only about namespaces the caller may read**: `ask()` and every retrieval pass the base ids through
  `kn_visible_base_ids()` first; the MaluDB token never reaches PHP outside `app/knowledge/engine.php`.
- **An agent sees what its member id may see** — the same functions, no special case; the run-facts gate decides which tools
  it is offered; `is_eval` refuses every write.
- **Scope: none.** One business, one installation. Departments are principals; never a wall.

## 4. Doors for those who are not inside — guests, keys and the siblings

The kernel's rules: *application users are not OS users*; *never a password, a login form or an account of the
application's own*; for a reach with no account, **a token is the authority**.

**A guest is a kernel member flagged external**, granted Reader by the super-admin: the client an AI agency built a base
for, an auditor given the compliance base. The launcher opens the application to them; they land on the one base (or the
few) shared with them: the window, the sources, the graph, their own questions. A curator shares a base to a guest
(`base.share` — pauses when an agent does it).

**A key** (`mcp_access_tokens` with `scope = 'api'`, **appended** `base_id`, `focus_area_id`, `rate_per_minute`,
`rate_per_day`, `label`) is minted by a curator for one base (and optionally one area), shown once, hashed; every call
is rate-limited (defaults 30/min, 2,000/day — settings), logged `question.ask` with source `api` under the key's label,
and answered exactly as the window would answer a Reader of that base. A key never lists bases it is not bound to.
Rotation keeps the old key for 24 h. **The public widget** (a key with allowed origins, a CORS door, a snippet) is Extended.

**A sibling application** (Help Desk's expert answering a ticket: "what does the manual say about error E42") reads through
**K7**: `ask`, `search` and `bases_index` are `shares[]` on the records server; the kernel calls them over an approved
connection; the answer covers only bases with an `application` principal naming the consumer. No member identity crosses
(K7's rule): a sibling's ask is logged as the sibling.

**Rejected:** inviting outsiders by email; a base reachable by link alone; the MaluDB token handed to anyone (it is
tenant-wide; the base is the application's idea, enforced here).

## 5. Agents and the application — the OS requirement, in detail

**Asking, browsing, adding sources, writing guidelines and building are free; deleting, sharing out, minting keys and
changing the model are a person's.** For every agent:

| Action | Category | Why |
|---|---|---|
| `base_share` to a guest or an application, `key_mint`, `key_rotate`, `base_visibility_set` (private → internal) | `external_send` | Content reaches someone outside, or a system |
| `base_delete`, `source_remove` (forgets it in the engine), `guideline_delete`, `focus_area_delete`, `question_purge`, `key_revoke` (not its own) | `deletion` | |
| `model_config_save`, `settings_save`, `limits_save`, `retention_set`, `build_rebuild_all` (a whole base re-extracted — the costly one) | `other` | Configuration, spend |
| `base_create` (private), `source_add` (file, text, URL), `source_replace`, `guideline_save`, `vocabulary_save`, `focus_area_save`, `focus_area_propose`, `build_start` (new and stale sources only, under the base's monthly limit), `answer_pin`, `question_feedback`, `base_share` to a member or department, `feed_sync`, `archivist_proposal_*` | — | An agent must do these alone — this is what makes it a curator |

**Working — how an agent learns there is work.** (a) **The command bar and Spaces**: a person's turn on the expert through
the kernel's chat endpoint; the expert answers with `ask` (the same cited answer) and the graph tools, scoped to what the
asker may read (`X-Acting-Member`). (b) **A duty** (cron) for the Archivist. (c) **A proposal needing a hand** writes an
`agent_dispatches` row (Spaces' shape): "the first build of *Compliance* finished — propose focus areas" wakes the expert
through one chat turn; when K8 lands, a wake. (d) **A sibling's expert** never runs here — it reads through K7.

**Shipped agents** (`maludb-os.json` `agents[]`; **hired on install** at the owner's word — §13.13):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — Knowledge Expert | The command bar and the house name: answers any question from the bases the asker may read, **always cited**, saying which base answered; explains a subject, a community, a path ("how are the two products related"); **proposes focus areas** from the communities after a first build (one chat turn, dispatched); **drafts guidelines** from a sample ("these look like legal contracts — extract parties, dates, obligations, amounts; here is a vocabulary"); writes a **community report** or an area summary when asked; suggests questions a base can answer; never deletes, shares out or mints a key unless the asker says so, and then pauses | every records tool, `ask`, `search`, the graph tools, `record_history` | `focus_area_propose`, `focus_area_save`, `guideline_save`, `vocabulary_save`, `answer_pin`, `build_start`, `source_add` (text), `base_share` (member/department), `key_mint` (paused), `source_remove` (paused) | `key_mint`, `source_remove`, `base_share` to a guest or an application |
| `archivist` — Knowledge Archivist | A duty every Monday 06:45 (`45 6 * * 1`): the **base report** per base — sources that failed or have no text, sources stale against the guideline version, URL sources whose page changed (a HEAD/ETag check) or vanished, feeds that stopped syncing, builds that failed, the monthly limit nearly spent; the **answer report** — questions with no evidence (the gaps, grouped by what they asked for: "nine questions about returns; no source covers returns"), thumbs-down answers with the evidence they used, pinned answers past their review date, duplicate subjects the vocabulary should merge ("PG17" and "Postgres 17" as two nodes), areas with no source and sources in no area; each a **proposal** a curator accepts or dismisses; one Monday note per base to its curators and one to the admin's assistant | the report tools of §7, `get_base`, `find_sources`, `questions_gaps`, `duplicate_subjects`, `graph_stats` | `archivist_propose` (rows), `notification` to curators, `message_send` (the kernel's, to the admin's assistant) | nothing (it never deletes, shares, mints or rebuilds) |

**Any other agent** hired by the kernel reads the bases shared with it when a super-admin grants it the Reader role and a
curator shares a base to it. **Help Desk's expert, Spaces' expert and Seamus** reach bases through K7 or through this
application's records MCP when granted — they never hold the MaluDB token.

**Skills** (`skills/`): `knowledge-basics` (what a base, a source, a guideline of each kind, a focus area, a build, a
question and a key *are*; which tool answers what; always cite, never guess), `writing-guidelines` (the five kinds with
examples per domain — contracts, manuals, policies, research; how a vocabulary term is written; what an exclusion must
say), `asking-well` (local vs global questions, scoping to an area, when to say the base holds nothing, how to read
evidence), `reading-the-graph` (subjects, statements, communities, god nodes, surprises — what each means for a corpus);
runbooks (`kind: runbook`): `propose-focus-areas`, `draft-guidelines-from-a-sample`, `monday-base-report`,
`answer-with-citations`, `merge-duplicate-subjects`.

## 6. Memory model — what Knowledge remembers

Two stores, deliberately apart: **record memory** (PostgreSQL 17, `<tenant>_knowledge`) holds the bases, sources, their
text and anchors, guidelines, areas, builds, questions and keys; **the graph** lives in MaluDB — **a memory database of its
own** (`<tenant>_knowledge_memory`, `maludb_core` enabled, its own API token; §13.3), **one namespace per base** (`kb:<id>`).
The tenant's memory (`certstudy_memory` here) keeps receiving this application's activity episodes as every sibling's does.

**How a person's words become the engine's** (the compile step, `app/knowledge/compile.php`, run on every guideline save):

| The person writes | The application keeps | MaluDB receives |
|---|---|---|
| a **base** | `knowledge_bases` row | a namespace `kb:<id>` and its **memory config** (`POST /v1/memory/config`: the model alias from the admin's settings, the compiled `prompt_template`, `default_subject_type`, the embedding model) |
| an **extraction** guideline ("pull out every party, date, obligation and amount; a clause is a subject") | `guidelines` kind `extraction`, versioned | lines in the namespace's `prompt_template` (the contract's `{{chunk}}` kept) and `hints[]` on every ingest call |
| a **vocabulary** guideline + terms ("PostgreSQL 17 = PG17 = Postgres 17, a *technology*") | `guidelines` kind `vocabulary`, `vocabulary_terms` | **subjects seeded with aliases and a type** before the build (`POST /v1/subjects`, `PATCH` aliases) — the extractor is handed the known names and only creates what is absent |
| an **exclusion** guideline ("never extract personal data about employees; ignore page headers and footers; nothing about pricing") | `guidelines` kind `exclusion` | lines in the prompt template; **and** an answer-time filter (the answering prompt repeats it; an evidence item matching a named exclusion is dropped before composing) |
| an **answering** guideline ("answer in two paragraphs, plain English, cite the clause; if the sources disagree, say so") and an **audience** guideline ("field technicians on a phone; assume no legal training") | `guidelines` kinds `answering`, `audience` — **apply at once, no rebuild** | the system prompt of the answer route (M1) and of the expert's turn |
| a **focus area** ("Warranty — coverage, exclusions, claims; terms: warranty, claim, coverage period; ask: how long is the battery warranty?") | `focus_areas` row | a **project** (`POST /v1/projects` with `classifier_md` = the description, `subjects[]` = the seed terms); sources assigned to the area are ingested with `projects: [area]`; a question scoped to the area seeds retrieval from the project's subjects |
| a **source** | `sources` + `source_text` + `source_pages` + `source_sections` | one `POST /v1/memory/documents` (title, the text, `namespace`, `projects[]`, `metadata {source_id, pages}`) → `maludb_document_id` kept; **removal** = `DELETE /v1/documents/{id}` (forgets) |
| a **build** | `builds` + `build_items` | the ingest calls above for every queued or stale source; then `/v1/memory/reindex/run` and `/v1/memory/embeddings/run`; counts from `/v1/graph/stats` cached on the base |

A change to an **extraction, vocabulary or exclusion** guideline bumps `knowledge_bases.guideline_version`; sources
extracted under an older version show **stale** and the base shows "needs a rebuild"; `build_start` re-extracts new and
stale sources (forget, then ingest) under the base's monthly limit; `build_rebuild_all` is a person's. **`preview`** runs
one source's first chunk through `POST /v1/memory/ingest {preview: true}` with the compiled prompt and shows the
subjects and statements it would make — the try-before-you-spend every guideline form offers.

**Text extraction** (the worker, `bin/worker.php`, PHP CLI, every minute): PDF through `pdftotext -layout` per page
(poppler is on the server; a PDF with no text layer is `no_text`); DOCX, PPTX and XLSX read as the XML inside the zip
(`ZipArchive` + `DOMDocument`, both installed) with a heading map; HTML through a reader pass (`DOMDocument`, scripts,
nav and boilerplate stripped); Markdown and plain text as they are (headings mapped); CSV and JSON flattened to rows of
text; a **URL** fetched server-side (http/https only, public addresses only, 10 MB, 20 s, one page, no scripts run);
**pasted text** verbatim. Each produces `source_text.text`, `source_pages (page_no, char_from, char_to)` and
`source_sections (heading, level, char_from, char_to)` so a citation names a page and a heading. Files keep their bytes
(`attachments`, 50 MB default — §13.6).

**Asking** (`kn_ask()` in PHP, `app/knowledge/ask.php`; one function behind the window, the API, the MCP tool, the K7
share and the expert): gate (base readable, key valid and under its rate, question ≤ 2,000 characters) → the last three
turns of the conversation → **M1** `POST /v1/memory/answer {namespace, project?, question, history, system: the compiled
answering prompt, limits}` → `{answer_md, confidence, citations[{document_id, chunk, quote}], evidence[statements]}` →
documents mapped back to sources, chunks to pages and headings → a `questions` row with `citations` and `evidence`,
`status answered | no_evidence | refused | failed` → the reply. **Until M1 exists:** `kn_search()` (`graph/query` +
`memory/recall` over the base's namespace, ranked, mapped, capped at twelve evidence items) answers the window and the API
as **evidence** with `status evidence_only`, and the window's "Ask the expert" button runs the kernel's chat endpoint (the
expert's turn reads the same evidence and writes the answer — slower, one at a time, ledgered by the kernel). Weak
evidence (below the base's threshold, setting) is `no_evidence`: the reply says so, offers the nearest subjects, and the
question is a **gap** for the Archivist. Every question is logged whatever the door.

**Record memory** (PostgreSQL 17, `<tenant>_knowledge`, db/001–0NN; prefix `kn_` on functions, views `mcp_*`):

| Area | Tables | Notes |
|---|---|---|
| The mirror | `members` (+ `is_agent`, `is_external`), `department_members`, `departments`, `sso_nonces`, `member_sessions`, `directory_sync_state` | the kernel's ids; `members.roles text[]` from the claims and `access[]` |
| Roles | `kn_rights`, `kn_roles`, `kn_role_rights`, `mcp_app_roles` | `kn_has_right(right)` |
| Settings | `kn_settings` (one row: default visibility, default monthly limit (MB of text per base per month, 50), max source bytes (50 MB), URL fetch allowed, evidence threshold, answer language mode (asker's / base's), question retention days (365), key defaults (30/min, 2,000/day), review months for pinned answers (6), the MaluDB model alias in use and the embedding model — **read from MaluDB, shown, changed with a pause**) | seeded |
| Bases | `knowledge_bases` (id, `key` slug, name, description, `visibility` private/internal, `owner_member_id`, `namespace` = `'kb:'||id`, `status` empty/ready/building/needs_rebuild, `guideline_version`, `auto_extract` (new sources are queued at once; default on), `monthly_text_mb_limit`, `sensitivity` internal/restricted, `language`, counts cached from the engine (`documents`, `subjects`, `statements`, `communities`), `last_built_at`, `archived_at`), `base_members` (§3) | a base's namespace is set once |
| Sources | `sources` (id, base, `kind` file/text/url/feed/note, title, `origin` (filename, URL, feed ref, the pinned question id), `attachment_id`, `text_sha256`, `char_count`, `page_count`, `language`, `status` uploaded/extracting_text/ready/no_text/queued/extracting/extracted/stale/failed/forgotten, `maludb_document_id`, `extracted_version` (the guideline version it was built under), `error`, `added_by/at`, `extracted_at`, `superseded_by` (a replaced source keeps its history), `url_etag`, `url_checked_at`), `source_text` (source, text — its own table for size), `source_pages`, `source_sections`, `source_focus_areas` (source × area) | a replaced source is forgotten in the engine and a new one ingested |
| Files | `attachments` (the uploaded bytes under `storage/sources/<base>/<id>/`) | GL's canonical; `record_type = 'source'` |
| Guidelines | `guidelines` (id, base, `kind` extraction/vocabulary/exclusion/answering/audience, title, body Markdown, position, enabled, `created_by/at`, `updated_by/at`), `guideline_versions` (base, version_no, `compiled_prompt_template`, `compiled_answer_prompt`, `compiled_hints jsonb`, `terms_snapshot jsonb`, `created_at`, `created_by`), `vocabulary_terms` (base, canonical_name, aliases text[], subject_type, description, `maludb_subject_id`) | a save of a graph-shaping kind writes a new version; versions are never rewritten |
| Focus areas | `focus_areas` (id, base, name, slug, description (the classifier), `seed_terms text[]`, `example_questions text[]`, `maludb_project_id`, `status` proposed/active/archived, `proposed_by` (an agent, nullable), `proposal_note` (which community, how many subjects), position) | an area is a MaluDB project |
| Builds | `builds` (id, base, `kind` extract/rebuild_all/reindex/embed/forget, `status` queued/running/done/failed/cancelled, `started_by`, `started_at`, `finished_at`, `sources_total/done/failed`, `chars_processed`, `subjects_added`, `statements_added`, `usage jsonb` (tokens when the engine reports them), `log text`), `build_items` (build, source, status, started/finished, error, usage) | one build runs per base at a time (unique partial index) |
| Questions | `questions` (id, base, `focus_area_id`, `asked_by_member_id` (nullable), `token_id` (a key, nullable), `application_key` (a K7 consumer, nullable), `conversation_id`, `turn_no`, `door` web/api/mcp/share/agent, question, `answer_md`, `status` answered/evidence_only/no_evidence/refused/failed, `confidence numeric`, `evidence jsonb`, `guideline_version`, `model`, `latency_ms`, `usage jsonb`, `created_at`), `citations` (question, rank, source, page_no, section, quote, `maludb_chunk_id`, `maludb_statement_ids bigint[]`), `question_feedback` (question, by, `rating` up/down, comment, at), `pinned_answers` (base, question, title, `answer_md` (editable copy), `owner_member_id`, `review_until`, `verified_at`, `source_id` (the note it became), status) | a pinned answer becomes a source of kind `note` and is re-ingested as any source; the question text is retained per the setting |
| Keys | `mcp_access_tokens` (+ `base_id`, `focus_area_id`, `rate_per_minute`, `rate_per_day`, `rotated_from`, `allowed_origins text[]` (Extended's widget)), `key_usage` (token, minute bucket, count; day bucket, count) | `kn_rate_ok(token)` |
| Feeds | `feeds` (base, `provider` catalog key, tool, `arguments jsonb`, focus area, `every` hours, `last_sync_at`, `last_status`, `items`) | a sibling's share read through K7: Spaces' `pages_index` + `page_markdown`, Help Desk's `articles_published` (HD2) — each item a source of kind `feed` keyed by `origin` |
| Agents | `agent_dispatches` (a base or a build, the agent, `kind` propose_areas/draft_guidelines/report, run id, status, attempts), `archivist_proposals` (base, `kind` area/merge/gap/stale_source/failed_source/review_pin/unassigned, payload jsonb, status proposed/accepted/dismissed, decided_by/at) | §5 |
| Notifications | `notifications` (build_done, build_failed, limit_near, proposal, feedback, review_due, monday_note), `notification_prefs`, `notification_outbox` | GL's canonical (email; K6 texts for a failed build if the curator chose them) |
| Notes | `notes` (a curator's note on a base or a source) | GL = CT canonical |
| Usage | `ai_usage` (base, month, `kind` extract/answer/embed, calls, input_tokens, output_tokens, chars) | the application's own count; **dollars are the kernel's** (K24) |
| Tokens | `mcp_access_tokens` with `scope = 'mcp'` | a person's own, read-only |

### 6.1 Tables against the estate — read, reuse, new (the shared-schema rule, 2026-10-05)

The estate has one data model in many databases (`maludb-os-integration` 0.7.0, `shared-schema.md`). Every table above was
decided against the eight sibling applications' `db/*.sql`; the definition of every reused table lives in THIS repository's
migrations, so the installer creates it whether or not the sibling is installed.

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | **reuse** | the kernel contract (Consultant Tracking `db/001`); `members` **appends** nothing beyond Spaces' `is_agent`, `is_external` |
| `activity_log`, `activity_ingest_state` | **reuse** | `memory.md` / CT `db/002`; **appends** `base_id` (every row of a base's history in one index) |
| `mcp_access_tokens` | **reuse** | CT `db/003`; **appends** `base_id`, `focus_area_id`, `rate_per_minute`, `rate_per_day`, `rotated_from`, `allowed_origins` — a key is a token bound to a base; proposed to the catalogue as the optional "bound token" columns |
| `kn_rights`, `kn_roles`, `kn_role_rights`, `mcp_app_roles` | **reuse** (shape) | CT `db/004` (`ct_` → `kn_`); the four roles of §3 |
| `kn_settings` | **reuse** (skeleton) | the `app_settings` singleton skeleton; the columns are this application's |
| `attachments` | **reuse** | **GL `db/009` canonical**, verbatim; `record_type` widened to `source` |
| `notes` | **reuse** | GL `db/009` = CT `db/006`, verbatim; `record_type` widened to `base`, `source` |
| `notifications`, `notification_prefs`, `notification_outbox` | **reuse** | **GL `db/014` canonical**; `kind` lists this application's; every recipient is a member, so the outbox's external-party column is absent (Spaces' substitution, recorded) |
| `agent_dispatches` | **reuse** | **GL `db/014` = CT `db/013` canonical**; `kind` widened; **appends** `base_id`, `build_id` |
| `ai_usage` | **new** (recorded) | GL's `ai_statements`/`ai_statement_lines` are the kernel's statement *imported*; CT's usage lines are pass-through billing; this is an application's own count of engine calls per base per month — nothing close; becomes canonical for "an application's own model-usage count" |
| `questions`, `citations`, `question_feedback`, `pinned_answers` | **new** | the kernel's `agent_runs`/`prompt_ledger` are runs, not questions; Help Desk's `articles` carry `helpful_count` only; a logged question with evidence and citations exists nowhere — canonical |
| `knowledge_bases`, `base_members`, `sources`, `source_text`, `source_pages`, `source_sections`, `source_focus_areas`, `guidelines`, `guideline_versions`, `vocabulary_terms`, `focus_areas`, `builds`, `build_items`, `key_usage`, `feeds`, `archivist_proposals` | **new** | nothing close: the kernel's retired `documents` is gone; Help Desk's `articles`/`kb_sections` are its own authored articles (**read**, below); Spaces' `pages` are authored pages (**read**). A `sources` table becomes the canonical "ingested document with text anchors" |
| Help Desk's `articles`, Spaces' `pages`, Projects' `issues`, the ledger's parties, HR's employment | **read** | another application's data: a feed through K7 when the sibling shares it (SP `pages_index`/`page_markdown` are in Spaces' plan; HD2 `articles_published` is owed); Knowledge never copies a row except as a **source** it was fed, keyed by the provider's id and refreshed by the feed |

**Owed to the catalogue** (`shared-schema.md` §4): `mcp_access_tokens` gains the optional bound-token columns; `sources` (with
`source_text`, `source_pages`, `source_sections`), `questions`/`citations` and `ai_usage` are Knowledge's canonical tables.

**Views and functions**: `mcp_*` over every table above with `security_barrier` and `kn_visible_base_ids()` once per
statement; the reads that matter are SQL functions — `kn_base_overview(base)` (counts, last build, areas, status),
`kn_source_page(source, page)` (the text of one page, for a citation), `kn_source_outline(source)`, `kn_questions(base,
filters)`, `kn_question_gaps(base, since)` (no-evidence questions clustered by their nouns), `kn_build_progress(build)`,
`kn_rate_ok(token)`, `kn_usage(base, month)`; the engine reads (`graph/query`, `recall`, `communities`, `neighbors`, `walk`,
`path`, `god-nodes`, `surprises`, `stats`, `projects`, `subjects`) are **one PHP module** (`app/knowledge/engine.php`) with a
typed function per route, the only place the MaluDB token is read, so a screen, a tool and an export never disagree.

**Activity memory**: `activity_log` through one `log_activity()`, `entity.verb` events, shipped to the tenant's one MaluDB as
`activity` episodes tagged `knowledge`. **A source's text and a question's text are never in a log payload** (ids, titles,
counts, the kind, the status, the number of citations are; a question's first 120 characters at most). Events: `base.create|
update|share|unshare|visibility_set|archive|delete|admin_view`, `source.add|replace|text_extracted|no_text|queue|extract|
fail|remove|forget`, `guideline.save|delete|version`, `vocabulary.save|sync`, `focus_area.save|propose|accept|dismiss|delete`,
`build.start|progress|done|fail|cancel|rebuild_all`, `question.ask|answer|no_evidence|refuse|feedback|pin|unpin|purge`,
`key.mint|rotate|revoke|rate_limited`, `feed.save|sync|fail`, `archivist.propose|accept|dismiss`, `agent.dispatch|reply|fail`,
`settings.save`, `model_config.save`, `share.read` (a sibling read us), `token.mint|revoke`, `member.sign_on`,
`directory.sync`, `screen.view`. A key is never logged.

## 7. The question inventory — what Knowledge exists to answer

Kind R = record (the records MCP), A = activity (the activity MCP). Who = the lowest role that may ask (every answer is
filtered by what the asker may see). The tool name is fixed here so Phase 1 is a transcription.

**Bases**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| B1 | Which knowledge bases may I read, what is each about, how big, when last built? | R | Reader | `find_bases`, `get_base` |
| B2 | Who may read or curate base X, and why (member, department, everyone, agent, application)? | R | Curator | `base_members` |
| B3 | What are the application's settings and the model in use? | R | Reader (settings) / Admin (model) | `get_settings` |

**Sources**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| S1 | What sources are in base X, by kind, status and area; which are stale, failed or without text? | R | Reader | `find_sources`, `get_source` |
| S2 | What does source X say — its outline, one page, the text around a citation? | R | Reader | `source_outline`, `source_page`, `source_excerpt` |
| S3 | Which subjects and statements did source X contribute to the graph? | R | Reader | `source_graph` |
| S4 | Which sources mention subject Y? | R | Reader | `sources_for_subject` |
| S5 | What feeds does the base follow, and when did each last sync? | R | Curator | `find_feeds` |

**Guidelines and areas**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| G1 | What are base X's guidelines, by kind, and which version is the graph built under? | R | Curator | `find_guidelines`, `guideline_versions` |
| G2 | What would these guidelines extract from this source (a preview)? | R | Curator | `extraction_preview` |
| G3 | What is the vocabulary — terms, aliases, types — and which terms the graph never met? | R | Curator | `find_vocabulary`, `unused_terms` |
| G4 | What focus areas exist, with their terms, example questions and size; which are proposed and by whom? | R | Reader | `find_focus_areas`, `get_focus_area` |
| G5 | Which sources are in no area, and which areas have no source? | R | Curator | `unassigned_sources`, `empty_areas` |

**Builds**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| L1 | Is a build running on base X, how far, what failed? | R | Curator | `build_progress`, `find_builds` |
| L2 | What would a build do now (how many sources, how many characters, against the monthly limit)? | R | Curator | `build_estimate` |
| L3 | How much text and how many engine calls did base X use this month? | R | Curator | `base_usage` |

**The graph**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| K1 | What is the graph of base X — nodes, edges, communities, top relationships? | R | Reader | `graph_stats` |
| K2 | What are the communities of base X, and what is in community C? | R | Reader | `communities`, `community_members` |
| K3 | What is subject Y — its type, aliases, statements, neighbours, the sources behind it? | R | Reader | `get_subject`, `subject_neighbors`, `subject_statements` |
| K4 | How are Y and Z related (a path)? | R | Reader | `graph_path` |
| K5 | What are the most connected subjects, and the surprising links between communities? | R | Reader | `god_nodes`, `surprises` |
| K6 | What subgraph answers "…" (seeds and a bounded walk)? | R | Reader | `graph_query` |
| K7 | Which subjects look like duplicates the vocabulary should merge? | R | Curator | `duplicate_subjects` |

**Asking**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| Q1 | **Answer this from base X (or area A), with citations** — the one tool behind every door | R | Reader | `ask` |
| Q2 | What evidence does base X hold on this (statements and passages, no composition)? | R | Reader | `search` |
| Q3 | What questions could base X / area A answer (suggested questions)? | R | Reader | `suggested_questions` |
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

Plus the contract's: `app_roles`, one guarded `search` on each server (the records one IS Q2), `record_history`. Every
question is a named tool; the reads are the same SQL functions and engine module the screens call. Records tools: about
44; activity tools: 6.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit copied from Consultant Tracking. The mirror
  carries `is_agent` and `is_external`.
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it and to the `shares[]` alone.
- **Agents**: run tokens on the two read servers (the run-facts gate, fail closed); the Actions MCP from the registry; the
  approval hook before every action with a category; the chat endpoint for the command bar and for dispatched proposals;
  the duty for the Archivist.
- **The engine**: `MALUDB_KB_API_URL` and `MALUDB_KB_API_TOKEN` (the knowledge memory database's token, minted at install by
  `deploy/ROOT_STEPS.sh` — the tenant's agent-memory token is **not** reused), read only by `app/knowledge/engine.php`; a
  missing token makes every build and ask answer `engine_unavailable`, never a crash. The model alias and provider live in
  MaluDB's config; the admin's settings screen shows them and changes them through `POST /v1/memory/config` with a pause.
- **Metering (K24)**: MaluDB's provider `base_url` for this memory database points at the kernel's ledger proxy with an
  **application credential** so every extraction and answer call is ledgered, priced and stamped with the application
  (`application_id` on the ledger row, no run) — the AI statement then shows "Knowledge — extraction, 1.2M tokens" beside
  the agents. Until K24: MaluDB's own provider key and this application's `ai_usage` counts (tokens where the engine reports
  them, characters always).
- **The token API** (the application's own, bearer = a key; allow-listed in the vhost): `POST /api/v1/ask {question,
  focus_area?, conversation_id?}` → `{question_id, status, answer_md, confidence, citations[{source, title, page, section,
  quote, url}], evidence[], conversation_id}`; `POST /api/v1/search {query, limit}` → evidence; `GET /api/v1/bases` → the key's
  base and areas; `GET /api/v1/questions/{id}`; `POST /api/v1/questions/{id}/feedback`; `GET /api/v1/health`. 401 a bad key,
  403 a base not bound, 429 over the limit (with `Retry-After`), 503 `engine_unavailable`. Every call logged `question.ask`
  with source `api`.
- **K7 — Knowledge is a provider.** Shares (`shares[]`), every one a records-server tool, people-free:
  | Tool | For | Document | Content |
  |---|---|---|---|
  | `ask` | Help Desk's expert ("what does the manual say about E42"), Spaces' expert, Projects, Consultant Tracking | `os.knowledge-answer/1` | `{base, question, focus_area?}` → the cited answer, **only for bases shared with the consumer application** (an `application` principal); the answer's citations carry source titles and pages, never the text beyond the quotes |
  | `search` | the same | `os.knowledge-evidence/1` | evidence items for a query over the shared bases |
  | `bases_index` | the same | `os.knowledge-bases/1` | the bases shared with the consumer: key, name, description, areas, last built |
  Reads (`reads[]`): `spaces.pages_index` + `spaces.page_markdown` (a space as a feed — when Spaces is built) and
  `helpdesk.articles_published` (HD2, owed) — each declared with its `why`; absent, a feed shows `no_connection`.
- **Public doors** (allow-listed in the vhost; everything else needs a session): `/api/v1/*` (key-checked), `/api/v1/health`,
  `/mcp/records`, `/mcp/activity`. Each is token-checked, rate-limited and logged.
- **Live-ness without a socket**: the ask window posts and waits up to 20 s for M1 (the engine answers in seconds); the
  expert path answers 202 and the window polls every 3 s (Spaces' pattern); a build's progress bar polls every 5 s while the
  page is visible.

## 9. Screens (the nxl look; the ask window and a source are read at 375 px first; the graph explorer is designed at 1280 px
and works at 375; no modals; cards for named things — bases, sources, areas, pinned answers —, tables for the question log,
builds, keys and admin lists; every name a link; every page a way back)

**The shell**: a sidebar at 1280 (Home · Ask · **Bases** (each base: Ask, Sources, Areas, Graph, Guidelines, Builds,
Questions, Keys, Share, Settings) · Pinned · Admin), a bottom tab bar at 375 (Home · Ask · Bases · Me). The command bar (the
kernel's chat endpoint) at the top of every screen.

**Home** — my bases as cards (status, size, last built, a "needs a rebuild" badge); builds running now with progress;
my recent questions; pinned answers due for review (curators); the Archivist's last Monday note; for a curator: the gaps
of the week and the thumbs-down answers; for the admin: the month's usage by base, failed builds, pending dispatches.

**Ask** — `/ask` (choose a base — or "everything I may read" —, optionally an area; the conversation; each answer with its
confidence, its **citations as chips** that open `source-page` at the page with the quote highlighted, "show the evidence"
(the statements and passages, with the subjects linked into the graph), thumbs up/down with a comment, "pin this answer"
(curators), "ask the expert" when evidence-only; suggested questions when the conversation is empty; the base's answering
language) — the one screen a Reader lives on, at 375 px first.

**Bases:** `/bases` (cards by visibility; create) · `base-view` (overview: description, counts, areas as cards, last build,
status, an ask box, the curators) · `base-form` (name, key, description, visibility, language, sensitivity, auto-extract,
monthly limit) · `base-share` (members, departments, everyone, agents, applications; levels; the guest share) · `base-settings`.

**Sources:** `sources` (list with kind, status, pages, area chips, stale/failed/no-text badges; filters; **add**: drop files
(many at once), paste text, add a URL, follow a feed) · `source-view` (title, origin, status and errors, the outline, the
pages, the areas, "what it taught the graph" — its subjects and statements —, replace, remove) · `source-page` (one page
of text, a citation's quote highlighted, prev/next) · `feed-form`.

**Guidelines and areas:** `guidelines` (the five kinds as sections; version and "graph built under version N"; "needs a
rebuild") · `guideline-form` (kind, title, body with the kind's examples beside it; **Preview on a source**) · `vocabulary`
(terms table: canonical, aliases, type; import CSV; "never met" badge; merge from the Archivist's proposals) · `areas`
(cards: name, terms, sources, example questions; the **proposed** ones with "accept" / "dismiss"; "propose areas" when a
build finished and none exist) · `area-form` · `area-view` (its sources, its subjects, its suggested questions, ask it).

**Builds:** `builds` (the estimate — sources, characters, against the monthly limit —, start, rebuild all (paused for
agents), the list) · `build-view` (progress, per-source status and errors, counts, usage, cancel).

**Graph:** `graph` (stats; communities as cards with their top subjects; god nodes; surprises; a search box →
`subject-view`) · `subject-view` (type, aliases, statements grouped by verb, neighbours, the sources — each a link to the
page —, a small SVG neighbourhood at 1280, "ask about this") · `community-view` · `path` (two subjects → the path).

**Questions:** `questions` (the base's log: when, who or which key, door, status, confidence, rating; filters; open one) ·
`question-view` (the question, the answer, the citations, the evidence, the feedback, "pin") · `gaps` (no-evidence questions
clustered; "add a source about…") · `pinned` (cards; review dates; verify; unpin).

**Keys:** `keys` (table: label, base, area, limits, last used, today's count; mint — shown once; rotate; revoke) · `key-form`.

**Admin:** Settings (limits, retention, thresholds, defaults) · Model (the MaluDB alias, provider and embedding model —
shown, changed with a pause; a "test extraction" button) · Usage (by base and month; the kernel's statement linked when
K24 exists) · Bases (every one; private ones marked and logged when opened) · Keys (all) · Agents (which agents read what,
dispatches — read-only; hiring links to the kernel) · Connections (which siblings read us — read-only) · Archivist proposals
(all) · My settings (notifications; texts for failed builds) · My MCP tokens.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document and the owner's answers (§13); the schema `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`: every actor of §2; a private and an internal base and who sees each for seven actors incl. a guest, an agent and an application principal; sources of every kind with pages and sections; a guideline save bumping the version and marking sources stale; a vocabulary term with aliases; an area; a build with items and the one-at-a-time rule; a question with citations, feedback and a pin becoming a note source; a key, its rate counters and a 429; a feed; proposals; retention); the kit (Consultant Tracking's identity, roles, sign-on, MCP common files, HTTP helper, attachments and sync) proven without a kernel (`tests/phase0/run.sh`); **the engine module proven against the live MaluDB API on a scratch memory database** (`tests/phase0/engine.sh`: config a namespace, seed subjects, ingest one document with projects and hints, preview, graph query, recall, communities, forget — recording exactly what 0.3.1 returns, including whether usage is reported — M2); `maludb-os.json` (every port env in `env.required`, the three `shares[]`, the two `reads[]`), `os/{expert,archivist}.md`, nine skills (five runbooks), `deploy/` templates and `ROOT_STEPS.sh` (the port pin first, the memory database and its token second); the installer's `plan` clean | the owner's go on §13 |
| 1 | `docs/knowledge-mcp-tool-surface.md` (every question of §7 a tool; the resolve table; the log-payload rules; the share documents' shapes; the API's JSON), `docs/knowledge-action-manifest.md` (screens and actions with their categories — §5), `mcp/action_registry.json`, the compile rules (`docs/build-specs/guidelines-compile.md`: exactly how each kind becomes prompt lines, hints, subjects and the answering prompt — worked examples for contracts, manuals, policies), and the slice specs in `docs/build-specs/` (`sso-shell` and nine slices; **the build and the ask are the exemplars**) | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles`, `kn_has_right()`, the ingest bridge, the shell (sidebar at 1280, tab bar at 375, the command bar through the chat endpoint), `/api/v1/health`, the vhost with the public allow-list, the engine module wired with `engine_unavailable` | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; 375 and 1280 |
| 3 | the slices, in order: **(1) bases, sharing and settings** (create, visibility, members by kind, the admin view, settings — the CRUD exemplar) → **(2) sources** (upload many, paste, URL fetch, the worker's text extraction for every kind, pages and sections, outline, replace and remove, no-text and failures) → **(3) guidelines, vocabulary and focus areas** (the five kinds and their forms, versions, the compile step writing the namespace config and seeding subjects, preview on a source, areas as projects, assignment of sources) → **(4) the build — THE FIRST EXEMPLAR** (estimate against the limit, queue, the worker ingesting source by source with progress, forget-then-ingest for stale ones, reindex and embeddings after, counts cached, failures and retries, cancel, rebuild-all paused, notifications) → **(5) ask — THE SECOND EXEMPLAR** (`kn_ask()` through M1 with the evidence fallback, the window at 375, citations opening the page, the evidence panel, no-evidence and gaps, conversations, feedback, pins becoming notes, suggested questions, the expert path with 202 polling) → **(6) the graph explorer** (stats, communities, subjects, neighbours, paths, god nodes, surprises, the SVG neighbourhood, "ask about this", duplicate subjects) → **(7) the API, keys and K7** (keys with limits and rotation, `/api/v1/*`, rate counters and 429, the three shares with the application principal, the question log per key) → **(8) agents, proposals and feeds** (the expert's proposals from communities and guideline drafts by dispatch, the Archivist's duty and reports, proposals accepted and dismissed, merges into the vocabulary, feeds from Spaces and Help Desk through K7 with `no_connection`) → **(9) home, admin, usage and tokens** (the homes, the question log and gaps screens, admin pages, the model screen with its pause, usage by month, retention, MCP tokens) | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database and a scratch namespace, the installed application never touched |
| 4 | the two MCP servers with the run-facts gate, `app_roles` and the three `shares[]` admitted to the kernel's token, the registry wrapper (`deploy/kernel-registry-knowledge.json`), the two agents declared with their grants and the duty; the kernel's Actions-MCP contract proved against its own `application_actions.register()` with a stubbed hook | the command bar answers "what does the warranty say about batteries" with two citations; an agent's `key_mint` pauses; the Archivist's duty mints nothing, deletes nothing, shares nothing |
| 5 | the owner's `app_install.php apply` (ports pinned first — §14), the memory database and its token (`ROOT_STEPS.sh`), K24's credential in MaluDB's provider config, DNS and TLS for `knowledge.<domain>`, the hires, the first base; the end-to-end proof | **a curator drops twelve PDFs and a DOCX into a new base on a desktop, writes three guidelines (extraction, vocabulary with four aliases, answering), previews one source, starts a build and watches it finish; the expert proposes four areas from the communities and the curator accepts two; a Reader asks on a phone and gets a two-paragraph answer with three citations that open the pages; a question the sources do not cover comes back "nothing on this" and appears in the gaps; a thumbs-down and a pin that becomes a note source; a key minted and `POST /api/v1/ask` answered from another machine, the 31st call in a minute refused with 429; Help Desk's expert reads `ask` over an approved connection and is refused for a base not shared with it; the Monday note names the stale URL source; the kernel's statement shows the extraction tokens (K24)** |

**Extended** (after version 1, each its own spec, §11). Size: ten specs (sso-shell + nine slices), about 40 screens, 70
actions, 44 records tools, 6 activity tools — below Help Desk (65 screens, 96 actions) because the engine is MaluDB's.
**Three to four days is the honest estimate**: the build and the worker's text extraction one, the ask window and the API
one, the graph explorer and the agents one, Phase 5 with the owner the remainder — **plus M1 in MaluDB's API, which gates
answers in words** (a day's work upstream; the evidence fallback keeps every slice provable without it).

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order): **the public widget** (a key with allowed origins, a CORS door, a
one-line snippet, a themed ask box) · **OCR** (tesseract for scanned PDFs and images — the one new system package) · **a
site crawl** (a sitemap or a depth-limited crawl into many URL sources, re-checked by the Archivist) · **MaluDB principals
per member** (`X-MaluDB-Principal: member:<id>`, grants synced from `base_members`, so the engine itself enforces reach —
and the kernel's Memory MCP could then offer an agent the bases it may read) · **community reports on every build** (a
model call per community, under the limit) · **PageIndex** when MaluDB v4 ships it (citations by the engine's section tree) ·
**audio and video** (a transcript as a source) · **answer export** (a question log or a pinned set as Markdown or CSV; a
base's sources as a ZIP) · **K8 wakes** replacing the dispatch turns · **a base copied as a template** (guidelines and areas
without sources) · **per-question cost shown to the asker** (from the kernel's ledger, K24) · **evals**: a base's example
questions with expected citations run as an eval suite by the kernel's runner before and after a guideline change.

**Later**: a base shared between two installations (an agency's base read by its client's OS — a federation question);
training or fine-tuning of anything (never, by the owner's data rule).

## 12. What the kernel, MaluDB and the siblings owe this application

| # | Item | Why the application cannot do it | Until then |
|---|---|---|---|
| **K24** | **An application credential on the ledger proxy**: minted on the application's Overview like the application token, accepted by `ledger_proxy.py` beside run and judge keys, every call priced and posted to the open statement with `application_id` set and no run — so MaluDB's provider `base_url` can point at the proxy and extraction, embedding and answer calls are on the AI statement | the ledger and the proxy are the kernel's; the owner's rule is token accounting for every model call | MaluDB's own provider key; `ai_usage` counts here; the statement shows nothing of it |
| **K25** | **Catalog entry `knowledge` (kind `ours`) seeded by a migration** in `maludb-os-core`, business area **Operations**, category `knowledge`, icon `feather-book-open`, criticality `medium` — so every installation shows "Knowledge — from us, not installed" and the installer's `apply` finds its row | the catalog is the kernel's (Spaces' db/169 came the same way) | `application_catalog_save` by hand |
| **M1** | **`POST /v1/memory/answer`** in the MaluDB API: `{namespace, project?, question, history[], system, max_evidence, max_tokens}` → retrieve (graph seeds + walk, recall over the namespace, the project's subjects first, semantic entity search when the embed model is real) and compose with the namespace's chat model → `{answer_md, confidence, citations[{document_id, chunk_id, quote, statement_ids}], evidence[], usage{input_tokens, output_tokens, model}}`; refuses to compose when evidence is below a threshold (`no_evidence`) | composing an answer needs a model; the application holds no key; the engine already holds the provider and knows its own retrieval best | evidence-only answers and the expert's turn (§6) |
| **M2** | **Usage on every model-calling response** (`/v1/memory/documents`, `/ingest`, `/reindex/run`, `/embeddings/run`, M1): `usage{input_tokens, output_tokens, model, calls}` | the engine makes the call | characters counted here; dollars nowhere until K24 |
| M3 | `POST /v1/memory/recall` and `GET /v1/graph/query` accept a **`project`** filter (seeds restricted to the project's subjects) and `recall` exposes **`maludb_semantic_search`** (entity cards) beside the compartment rail | the facades are the engine's | the application intersects the project's subject list with the seeds itself |
| K16 | **K8 (wake an agent from an application)** — already owed to four siblings; here for "propose areas" and "draft guidelines" (today a dispatched chat turn) | messaging is an agent's tool | the chat-turn dispatch |
| HD2 | **Help Desk shares `articles_published`** (id, title, section, body Markdown, updated_at — people-free) so a base can follow the help centre as a feed; **SP** Spaces' `pages_index` + `page_markdown` are already in its plan | the siblings' data | a curator pastes or uploads |
| HD3 / SP2 | Help Desk's expert and Spaces' expert gain `reads[]` on `knowledge.ask` / `search` / `bases_index` — the first consumers | the consumers declare wanting | nothing reads us |

K25 is small and comes first (the Spaces pattern); K24 is a day in the kernel and the one item on the critical path for the
owner's accounting rule; M1 is the item that turns evidence into answers and is the owner's to schedule in MaluDB's API.

## 13. Decisions the owner is asked for (recommendation first)

1. **Name and key** — "Knowledge", catalog key `knowledge`, repository `maludb-os-knowledge` (created private 2026-10-05, in
   the org), local clone and install path `/srv/apps/knowledge`, DNS label `knowledge` (`knowledge.<domain>`), record
   database `<tenant>_knowledge`, memory database `<tenant>_knowledge_memory`, roles `knowledge_rw` / `_records_ro` /
   `_activity_ro`, SQL prefix `kn_`. Confirm, or choose another name ("Corpus", "Atlas", "Ask" and "Library" were
   considered — "Knowledge" says what it holds; the key must be `[a-z][a-z0-9_]*`).
2. **Roles and names** — Reader (`reader`, read), Member (`user`), Curator, Knowledge admin; a Member may create a base of
   their own; **the admin sees private bases, logged** `base.admin_view`. Confirm the words, and the admin rule (alternative:
   private bases closed even to the admin, who may only archive).
3. **Where the graph lives** — **a memory database of its own** (`<tenant>_knowledge_memory`, `maludb_core` enabled, its own
   API token, one namespace per base), created by `ROOT_STEPS.sh` at install: a person's corpus never shares a database with
   the agents' memories, a base is forgotten or rebuilt without touching them, the model config is this application's, and the
   plugin's "one memory per tenant" rule keeps meaning *activity episodes* (which still go to the tenant's memory). Alternative:
   bases as `kb:<id>` namespaces inside the tenant's one memory database — one fewer database, and the kernel's Memory MCP
   could one day recall from them, at the price of a corpus beside the agents' private memories and a tenant-wide token
   reaching both.
4. **How a model is used, and metered** — extraction, embedding **and answering** by MaluDB with its configured provider (the
   application holds no key, as the rule says); answers through **M1** (`/v1/memory/answer`, owed upstream); **metering through
   K24** (MaluDB's provider URL = the kernel's ledger proxy with an application credential) so every call is on the AI
   statement. Until M1: evidence-only answers and the expert; until K24: MaluDB's own key and this application's counts. The
   kernel's expert (chat endpoint) stays the command bar's and Spaces' path. Alternative: every answer through the kernel's
   expert turn (exists today; 30–60 s; one at a time per agent — unfit for the API); or a kernel "complete" route the
   application calls with its own retrieval (a second place that holds RAG logic). Confirm the recommendation and that M1 is
   scheduled in MaluDB's API.
5. **The unit and its reach** — a **knowledge base** is private (its members) or internal (everyone in the business reads);
   shared to members, departments, everyone, agents and **applications** (the K7 gate); **guests (externals) may be Readers** of
   a base shared with them (an agency's client). Confirm; and confirm **no site scope** (one business, departments as
   principals, never a wall).
6. **Sources in v1** — PDF (text layer), DOCX, PPTX, XLSX, CSV, JSON, HTML, Markdown, plain text, pasted text, one web page
   per URL (fetched server-side, public hosts only), a pinned answer as a note, a sibling feed through K7; **50 MB per file**;
   scanned PDFs and images flagged `no_text` (OCR Extended); audio, video and site crawls Extended. Confirm.
7. **Guidelines — five kinds, two moments** — extraction, vocabulary (with a terms table), exclusion shape the graph and
   **require a rebuild** (shown, estimated, started by a person or under auto-extract for new sources); answering and audience
   apply at once. Every guideline form offers **Preview on a source** (the engine's `preview`, one chunk, no graph written).
   Confirm, or ask for one free-text instruction box (simpler; loses the vocabulary seeding and the rebuild rule).
8. **Focus areas are MaluDB projects** (name, description as the classifier, seed terms as subjects, example questions);
   sources are assigned to areas (many); a question may be scoped to an area; a key may be bound to one; **after a base's
   first build the expert proposes areas from the communities** and a curator accepts in one click. Confirm.
9. **Builds and limits** — `auto_extract` on by default: a new source is queued and extracted at once; a changed graph-shaping
   guideline marks sources stale and a person starts the build (the estimate shown first); **a monthly limit per base in MB of
   text** (default 50) stops a build short and notifies — dollars when K24 exists; one build per base at a time; a removed
   source is **forgotten** in the engine (0.106.0). Confirm the defaults.
10. **Asking** — every answer cites or declines; evidence below the base's threshold is "the knowledge base holds nothing on
    this" plus the nearest subjects, logged as a **gap**; conversations keep the last three turns; the asker's language by
    default (setting); the evidence panel is open to every Reader (that is how an answer is trusted); thumbs with a comment;
    **a curator pins an answer** (owner, review every 6 months) and the pin becomes a `note` source so the next build knows it.
    Confirm.
11. **The question log** — every question logged with its asker (a person, a key's label, or a sibling application), its
    answer, evidence, citations, confidence, latency and usage; the asker sees their own; the base's curators see all of the
    base's; retention 365 days (setting); a purge pauses for agents. Confirm (alternative: askers anonymous to curators —
    loses "who keeps asking about returns").
12. **The API and the siblings** — keys per base (optionally per area) with limits (30/min, 2,000/day defaults), rotation with
    a 24 h overlap; `POST /api/v1/ask`, `/search`, `GET /bases`, `/questions/{id}`, feedback; K7 shares `ask`, `search`,
    `bases_index` answering only bases shared with the consumer application; `reads[]` on Spaces' pages and Help Desk's
    articles as feeds; the public widget Extended. Confirm.
13. **Agents** — the **expert** and the **Archivist** (duty Mondays 06:45) hired on install into Reader with the shipped
    grants; the expert proposes areas and drafts guidelines by dispatch; what pauses (§5): sharing out, minting and rotating
    keys, making a base internal (`external_send`); deleting a base, removing a source, deleting guidelines or areas, purging
    questions (`deletion`); model config, settings, limits, retention and rebuild-all (`other`). Confirm.
14. **Privacy and retention** — a source's bytes and text are kept until removed (then forgotten in the engine within the
    minute); a question's text is kept per the retention setting; a base carries a sensitivity (internal/restricted) passed to
    the engine; **source text and question text are never in a log payload** (ids, titles, counts, 120 characters of the
    question at most); the MaluDB token is read in one file. Confirm.
15. **Kernel, MaluDB and sibling items** — K25 (the catalog seed, first), **K24** (the ledger-proxy application credential),
    **M1** (the answer route), M2 (usage on responses), M3 (project filter and semantic recall), K16/K8, HD2 and the two
    consumers' `reads[]` approved and built in their own repositories beside this application; this application is **on
    request, not a default** (an installation without a corpus has no use for it), the repository **public** once secret-free
    (a showcase of MaluDB). Approve, or keep it private.
16. **Ports and the handoff** — `APP_INTERNAL_PORT=8187`, `MCP_RECORDS_PORT=8835`, `MCP_ACTIVITY_PORT=8836` pinned before
    `apply` (the block after Spaces'); proof scratch ports 8501–8507; and the **Spaces division**: a planning-class model builds
    K25 and K24 in the kernel, Phase 0's second half (including the engine proof against the live API), Phase 1 for your
    approval, Phase 2, slices 1–3 and **the two exemplars — slice 4 (the build) and slice 5 (ask)**; a worker model (Sonnet
    5.5) builds slices 6–9, Phase 4 and Phase 5 from the specs. Confirm, or hand the worker everything after slice 4.

## 14. Ports, names and files

- Repository `/srv/apps/knowledge` (origin `github.com/maludb/maludb-os-knowledge`); record database `<tenant>_knowledge`
  (`subello_knowledge` here); roles `knowledge_rw`, `knowledge_records_ro`, `knowledge_activity_ro`; **memory database
  `<tenant>_knowledge_memory`** (`subello_knowledge_memory`; schema and role `knowledge_mem`; `maludb_core` +
  `enable_memory_schema`; its API token minted by `ROOT_STEPS.sh` into `config/.env` as `MALUDB_KB_API_TOKEN`); the tenant's
  memory (`certstudy_memory`) keeps the activity episodes tagged `knowledge`.
- `knowledge.<domain>` (`vhost.label = "knowledge"`); `APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT` **all
  three in `env.required`** and written to `config/.env` before `apply` (§13.16); `deploy/` files are templates (`{{DOMAIN}}`,
  `{{APP_DIR}}`, `{{APP_KEY}}`, `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`).
- Units: `knowledge-records-mcp`, `knowledge-activity-mcp`, `knowledge-activity-ingest` (+ timer, every minute),
  `knowledge-directory-sync` (+ timer, every minute), `knowledge-worker` (+ timer, every minute: text extraction, builds,
  reindex and embeddings after a build, feeds, URL checks, the outbox, rate-counter pruning, retention, dispatch retries).
- `maludb-os.json`: `catalog_key knowledge`, `name "Knowledge"`, `business_area "Operations"`, `category knowledge`, `icon
  feather-book-open`, `criticality medium`, `vhost.label knowledge`, no `scopes`, `sso`, `directory {reads: true, writes:
  false}`, `assistant {command_bar: true, agent: expert}`, `agents [expert, archivist]` each `hired_on_install: true`,
  `approvals` (§5), endpoints (records, activity, web, health, the API), services (above), `shares` (the three of §8),
  `reads` (the three of §8), `runtime.python` none (the worker is PHP; the MCP servers use `mcp/venv` as every sibling).
- Env: the contract's required keys plus `MALUDB_KB_API_URL`, `MALUDB_KB_API_TOKEN`, `MALUMAIL_API_KEY`, `MAIL_FROM`,
  `MAIL_FROM_NAME` (optional), `ATTACHMENT_MAX_BYTES`, `KN_URL_FETCH` (on/off), `KN_PDFTOTEXT` (path, default
  `/usr/bin/pdftotext`).
- Vhost allow-list (public): `/api/v1/`, `/mcp/records`, `/mcp/activity`.
- Kernel files: `mcp/registries/knowledge.json` (made by the installer), the K25 catalog seed migration, K24 in
  `mcp/agent_runner/ledger_proxy.py` + its migration + the Overview's mint.
- Proof scratch ports: 8501–8507 (app, fake kernel, fake MaluMail, the two MCP servers, a fake MaluDB API for the unit
  proofs; the engine proof uses the live API on a scratch memory database).

## 15. The owner's answers

*Awaiting the owner's answers to §13 (2026-10-05). Each becomes a rule here, D1–D16, with where it lives.*

## 16. State

**2026-10-05 — the plan written; awaiting the owner's sixteen answers.** The field researched (§0: NotebookLM, Claude
Projects, GraphRAG and LightRAG, the enterprise answer tools, the hosted RAG builders, and MaluDB 0.106.0 / API 0.3.1 as
installed); the repository `maludb/maludb-os-knowledge` created in the org (private) and cloned to `/srv/apps/knowledge`;
this document, `CLAUDE.md` and `README.md` committed. **Next: the owner's answers → §15 → K25 and K24 in the kernel, M1 in
MaluDB's API → Phase 0's second half.**
