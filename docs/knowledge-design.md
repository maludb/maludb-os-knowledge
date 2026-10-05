# Knowledge — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-05 · the business's **knowledge bases**: a person uploads documents, sets guidelines, names the areas they care
about, and the application builds a **knowledge graph** in MaluDB that is asked three ways — an **ask-me-anything window**,
a **token API** other systems call, and the kernel's agents (the command bar, a mention in Spaces, a sibling's K7 read).
**A general product, with the regulatory template built first** (the owner, 2026-10-05): the first corpora are **laws,
codes and regulations** — the fire codes of the City of Chicago and of Cook County, Illinois, with the standards they
reference — and a code is not a pile of pages: it is a numbered tree of provisions with definitions, exceptions, tables,
cross-references, editions, amendments and effective dates, and an answer about it is a quotation with a section number
or it is wrong. Its own repository `/srv/apps/knowledge` (origin `github.com/maludb/maludb-os-knowledge`), served as
`knowledge.<domain>` (`knowledge.subello.com` here), built with `htmx-php-builder` (how it is built) and fitted to
`maludb-os-integration` 0.7.0 (how it fits), exactly as HR, Projects, Help Desk, General Ledger, Consultant Tracking and
Spaces were. **`/srv/apps/spaces/docs/spaces-design.md` is the nearest plan** (the same shape, the same owner's rules, the
same day); `/srv/apps/helpdesk` and `/srv/apps/consultant_tracking` are the nearest **built** exemplars (their kit, specs
and proof suites are what this repository copies). The engine is **MaluDB** — `maludb_core` 0.106.0 and the MaluDB API
0.3.1 as they run on this server today (§0.6) — and the kernel's own agent memory (`docs/build-specs/agent-memory.md`,
`memory-screen.md` in `maludb-os-core`) and Help Desk's small knowledge base (`articles`, `kb_sections`) are the prior art
this design keeps or overturns by name.

> **What this document is.** The plan for the application, in the kernel's words, for the owner's go, and the brief a
> worker model builds from. **Checkpoint.** This document and the owner's answers (§13, recorded in §15) are Phase 0's
> first half; the schema in `db/`, `maludb-os.json`, `os/`, `skills/` and `deploy/` complete it. Phase 1 adds
> `docs/knowledge-mcp-tool-surface.md`, `docs/knowledge-action-manifest.md`, `docs/build-specs/guidelines-compile.md`,
> `docs/build-specs/code-parsers.md` and the slice specs in `docs/build-specs/`. **No feature PHP is written until the
> owner approves them together.** The decisions this document needs from the owner are §13; §15 records the answers.

## 0. The research — the proof corpus, what a code is, what the field calls this, and what MaluDB gives today

### 0.0 The proof corpus (the owner's choice, 2026-10-05): the fire codes of Chicago and Cook County, and their standards

| Jurisdiction | The adopted code | The local layer | Where the text lives | Form it arrives in |
|---|---|---|---|---|
| **City of Chicago** | The **2018 International Fire Code** (second printing and its errata) adopted **in part, by reference**, as **Title 14F Fire Prevention Code** of the Municipal Code (the interim code since 1 August 2020; a successor ordinance takes effect in 2026) | Chicago's amendments, deletions and additions **codified as Title 14F chapters and sections** (`14F-1-001` style numbering); **Title 15 Fire Prevention** — Chicago's own older chapters (Bureau of Fire Prevention, fire protection equipment, explosives and fireworks, flammable liquids, flammable compressed gases, hazardous materials) — **remains in force beside it** | The Municipal Code of Chicago on American Legal Publishing (HTML, one page per section); chicago.gov posts PDFs of whole titles | An export a person downloads (PDF or Word per title) — the publisher refuses automated fetches |
| **Cook County** (unincorporated, and the Forest Preserve District) | The **2021 International Fire Code** with local amendments (ordinance of 29 July 2021; drawings must conform from 1 December 2021), adopted in one ordinance with the 2021 IBC, IRC, IMC, IECC (with Illinois amendments) and IEBC | The amendments in the **Cook County Code of Ordinances, Chapter 102 (Buildings and Building Regulations)**; the adopting ordinance as a PDF on the County's Legistar | Municode (a single-page application; Word and PDF export per chapter) | An export a person downloads |
| **The model codes** | IFC 2018 and IFC 2021 (International Code Council) | — | ICC Digital Codes (licensed) | PDF |
| **Referenced standards** (in scope — the owner holds the licences) | NFPA 1, 13, 13R, 13D, 14, 20, 25, 72, 101, 110 … as each code's Chapter 80 names them | — | NFPA (licensed) | PDF |

What that corpus teaches before a line is written: **(1) the text arrives as files**, not by URL — both publishers block
robots, so file upload (PDF, Word export, HTML export) is the first door and URL fetch the second; **(2) one installation
holds two editions of one model code** (IFC 2018 for Chicago, IFC 2021 for the County), so edition is a property of a base,
never of the installation; **(3) two amendment styles** — a codified title whose sections stand beside the model code's,
and an ordinance that says "Section 903.2 is amended to read"; **(4) a city's own older chapters coexist with the adopted
model code**, so a base is a *layered* set of sources with precedence, not one document; **(5) referenced standards are
corpora of their own** that several bases cite — NFPA 13 is one base read by both fire codes — and a cross-reference must
resolve across bases; **(6) "as of when"** matters: the County's answer differs before and after 1 December 2021, Chicago's
will differ again in 2026.

### 0.1 What a code is, structurally (the thing the parser and the citation must know)
Parts → **chapters** → **sections** (`903`) → **subsections** (`903.2.1.1`) → numbered **exceptions**; **tables** (`Table
903.2.11.6`) and figures; a **definitions chapter** (ICC's Chapter 2; NFPA's Chapter 3) whose terms are used in small caps
throughout; **referenced standards** (ICC's Chapter 80) with the edition named; **appendices** adopted only when the
adopting ordinance names them; notes and "Reserved" placeholders. Numbering families: ICC `903.2.1.1`; NFPA `8.15.1.2`;
Chicago `14F-9-903` and `15-16-010`; Cook County `Sec. 102-24`; the US Code of Federal Regulations `§ 1910.157(c)(1)`.
An **amendment** does one of four things to a numbered provision — *amends* it to read otherwise, *deletes* it, *adds* a
new one, or *reserves* it — and the **effective provision** is the last word in order of precedence (local over state
over the model code). A provision **references** other provisions ("in accordance with Section 903.3.1.1"), tables and
standards ("installed in accordance with NFPA 13"); it **defines** terms; it **applies to** occupancies, systems,
materials and quantities; it **requires**, **permits** or **prohibits**, often above a **threshold** (an area, a height,
an occupant load, a quantity). Those are the verbs and subject types of the regulatory template (§6).

### 0.2 Google NotebookLM — "a notebook of sources, answered with citations"
A notebook holds up to 50 **sources**; every answer is **grounded and cited inline**, a citation opens the passage; a
"notebook guide" offers a summary, suggested questions, an FAQ; an answer can be **saved as a note**, and a note **turned
into a source**; a notebook is shared. No graph, no API, no guidelines beyond the question. **Kept:** sources as the unit
people understand, citations that open the passage (here: the provision), suggested questions per area, "pin this answer"
turning an answer into a `note` source, sharing a base. **Dropped:** audio overviews (Extended), Google-only sources, the cap.

### 0.3 Claude Projects and custom GPTs — "instructions plus knowledge"
A project is **instructions** plus **knowledge files** plus conversations. That is the simplest statement of the owner's
"guidelines" — and one text box, which stops working for a corpus of a thousand provisions: nothing says *what to
extract*, *what the words mean*, or *what never to say*. **Kept:** guidelines as first-class text a non-technical person
writes; conversations scoped to a base. **Changed:** guidelines have **five kinds** (§6) because they act at different
moments, and **a template ships them pre-written** for a domain — the regulatory template first.

### 0.4 Microsoft GraphRAG, LightRAG and their kin — "entities, relationships, communities"
Entities and relationships extracted by a model, clustered into **communities**, a **community report** each; **local**
questions (an entity and its neighbourhood) answered differently from **global** ones (themes, a map-reduce over
reports); **prompt tuning** generates a domain extraction prompt from a sample. **Kept:** local/global (a question scoped
to a focus area or asked of the whole base; the explorer shows communities, god nodes and surprises); prompt tuning *is*
what guidelines, vocabulary and the template are; **a community proposes a focus area** in the general template, **a
chapter proposes one** in the regulatory template (deterministic — no model needed). **Changed:** community reports are
written on request by the expert.

### 0.5 Glean, Guru, Notion AI, Confluence, and the hosted RAG builders — "permissions follow the source; a key per workspace"
The enterprise tools show a person only what they may already see; Guru adds **verified answers** re-verified on a
schedule and **feedback** on each; the builders (OpenAI file search, Pinecone Assistant, Dify, AnythingLLM, Open WebUI)
give a workspace, a system prompt, an **API key** per workspace, `POST /ask` with citations, rate limits, a widget.
**Kept:** reach by base (member, department, everyone, agent, sibling application); pinned answers with an owner and a
review date; thumbs; gaps as a report; the API exactly that shape with keys bound to a base; the widget Extended.

### 0.6 MaluDB — the engine this application is a face for (as installed: core 0.106.0, API 0.3.1)
The owner maintains MaluDB; this application re-implements nothing of it.

| Need | MaluDB today | How this design uses it |
|---|---|---|
| Text in, graph out | `POST /v1/memory/documents` (title, text, `namespace`, `projects[]`, `subjects[]`, `verbs[]`, `metadata`, **`edges[]` provided by the caller**, chunking) and `POST /v1/memory/ingest` (text, **`hints[]`**, `namespace`, **`preview`**) — the model extracts **subjects, verbs and statements** with source spans (the SVPOR graph); `DELETE /v1/documents/{id}` **forgets** a document and what it taught | **a provision is one MaluDB document** (a plain document's section, for the general template); the parser posts the **structural edges itself** (`part_of`, `references`, `defines`, `amends`) and the model extracts the semantic ones (`requires`, `applies_to`, `prohibits`, `permits`); `preview` is "try my guidelines on this provision"; an amended or removed provision is forgotten and the effective one ingested |
| A domain's extraction prompt | `POST /v1/memory/config` **per namespace**: model alias, `prompt_template` (with `{{chunk}}`), `generation_params`, `default_subject_type`, embedding model | **a base's compiled guidelines ARE its namespace config** — one namespace per base |
| Areas | **projects** (`/v1/projects`: a subject of type `project` with `classifier_md`, `subjects[]`, `verbs[]`, `documents[]`) | **a focus area is a MaluDB project** (a chapter, in a code) |
| Vocabulary | subjects with **aliases** and a **subject type** (`/v1/subjects`); the extractor is handed the known names and only creates what is absent | **the definitions chapter seeds the vocabulary automatically**; a curator adds terms; the subject types of the template (provision, term, occupancy, system, material, standard, jurisdiction) are seeded |
| Asking | `GET /v1/graph/query` (seeds + a bounded walk, `namespace`-scoped) · `POST /v1/memory/recall` (several namespaces) · `/v1/graph/{neighbors,walk,path,god-nodes,surprises,stats}` · `/v1/communities` | the **evidence** step of every answer; the explorer; the path between two provisions |
| Answering in words | **none** — the API composes no answers | **M1** (§12): `POST /v1/memory/answer`; until then evidence-only and the expert |
| Who may read | principals and scopes (`X-MaluDB-Principal`…) | v1 enforces reach in the application; principals per member Extended |
| Keeping it fresh | `/v1/memory/reindex/run`, `/v1/memory/embeddings/run` | the worker, after a build |
| Thresholds | typed **attributes** on subjects and edges (`/v1/attributes`, templates) | Extended (M4): "12,000 square feet" as a typed attribute on `903.2.7`'s requirement, so applicability can one day be computed |
| Long documents | PageIndex (a tree of summaries per document) is MaluDB's **version 4 plan** | the provision tree IS the document's tree here; when PageIndex ships, the engine may build it from ours |

What the application adds and MaluDB does not have: files and **text extraction**; the **code parsers** that turn text into
a provision tree with definitions, references and amendments; **editions, jurisdictions, precedence and effective dates**;
the five kinds of guideline and the **templates**; focus areas as a person sees them; builds with progress, cost and the
rebuild rule; the question log with gaps and pins; bases and who may read them; keys with rate limits; the K7 shares; the
two agents. The owner's rule that *an application never holds a model key* holds: MaluDB holds the extraction model's key as
it does today for the tenant's memory, and the kernel meters the calls through its ledger proxy (K24, §12).

## 1. What Knowledge is, and is not

Knowledge **owns the business's curated corpora and what can be asked of them**: a **knowledge base** per subject matter —
a jurisdiction's adopted fire code with its standards, the product manuals, the policies and contracts, a client's
regulatory filings — each a layered set of **sources** with **guidelines** and **focus areas**, built into a graph in
MaluDB, and asked by people, by agents and by other systems. It is NotebookLM with a graph and an API, GraphRAG with a
face, and for a code it is the inspector's and the architect's desk reference that quotes the section — with the
differences that matter here:

- **The graph is MaluDB's, and MaluDB is the owner's.** Nothing of extraction, embedding, storage or traversal is written
  here; a gap in the engine is fixed upstream (§12). The application is files, parsers, guidelines, areas, builds, asking,
  keys and who-may-read.
- **The provision is the unit.** For a code, every numbered provision is its own node, citation and MaluDB document;
  an answer cites "IFC 2021 §903.2.7, Exception 1, as amended by Cook County Code §102-xx", never "page 134". For a plain
  document the unit is its section, and the citation its heading and page.
- **A base is layered and dated.** A base is one jurisdiction's adopted code: the model edition, the amendments in order
  of precedence, the jurisdiction's own chapters, each source with its effective dates; the parser proposes what each
  amendment does to which section and a curator confirms; the **effective provision** is what the graph learns and what
  an answer quotes, with the original a click away. Every question is asked **as of** a date.
- **Guidelines are first-class, typed and shipped.** Five kinds (§6); three change the graph and mark the base "needs a
  rebuild", two change only the answers. A **template** ships them written for a domain — the regulatory template first
  (the owner's order), the general template beside it — so a curator starts from a working set.
- **Every answer quotes or declines.** A regulatory answer quotes the provision verbatim with its number, edition and
  jurisdiction, lists every exception, flags every amendment, keeps every threshold exact, and ends with "confirm with the
  authority having jurisdiction"; weak evidence is a plain "the code holds nothing on this" and a logged gap — never a
  guess, never a number the text does not contain.
- **Focus areas are the person's map and the engine's projects**; in a code they are its chapters (proposed from the
  structure), in a plain corpus they are proposed from the communities after the first build.
- **Three doors, one gate.** The window, the API and the agents pass one visibility function and one `kn_ask()`; a sibling
  reads a base through K7 only when the base was shared with it; a key reaches one base.
- **Full MCP coverage is a requirement**, not a feature (`maludb-os-integration`, `mcp-and-api.md`).
- **Nothing of identity lives here.** The kernel signs people in; a guest is a kernel external; a system holds a key.

Not in version 1: OCR of scanned PDFs and images (flagged `no_text`; Extended); audio and video; crawling a site (one
page per URL); the embeddable public widget; community reports written automatically; MaluDB principals per member;
**computed applicability** ("is a sprinkler required for *this* building" decided by the machine from thresholds — v1
quotes the provisions and their conditions and lets the person decide; typed thresholds are M4/Extended); an answer
written by a model **until M1 ships**; training or fine-tuning of any kind.

## 2. Who uses it (the actors, in the OS's words)

**A word about words.** "Agent" always means an OS AI agent. A **member** is a kernel member mirrored here; a **guest** a
member flagged external holding Reader; a **knowledge base** ("base") a named corpus with its guidelines, areas, graph,
template and, for a code, its jurisdiction and edition; a **source** one document, pasted text, fetched page, note or fed
record, with a **role** in the base (model code, amendment, own chapter, standard, document) and a **layer** (precedence);
a **provision** one numbered unit of a code (or one section of a plain document); an **amendment** a source whose
provisions change another source's; a **guideline** one instruction of one kind; a **template** a shipped set of
guidelines, vocabulary types, verbs and an area rule; a **focus area** a named area (a MaluDB project); a **build** one
extraction run; a **question** one ask with its answer, evidence and citations, asked as of a date; a **key** an API key
bound to a base.

| Actor | Who | How they reach the application |
|---|---|---|
| **Reader** | Anyone a base is shared with — the inspector, the plan reviewer, the architect, the contractor: asks, reads the code tree and any provision with its amendments, browses the graph, rates answers | The launcher → `knowledge.<domain>`; the phone (the window and a provision are read at 375 px first); the command bar |
| **Member** | Everyone inside the business holding the base role: a Reader who may also **create a base of their own** and add sources where given curate | The same |
| **Curator** | Owns or curates a base: its template, sources and their layers, the amendment review, guidelines, areas, builds, sharing, keys, pinned answers, the question log | A base's settings |
| **Guest** (external) | A client whose base an agency built, a consultant given the fire code base: a kernel external holding Reader; sees nothing but the bases shared with them | The launcher, to a one-base home |
| **Knowledge admin** | Every base; settings; the model configuration (read from MaluDB, changed with a pause); limits and retention; every key; costs; jurisdictions | The admin area |
| **An agent** | Any hired agent granted Reader (the shipped **expert** and **Archivist** on install, any other by a super-admin): asks and browses like a member; the expert answers the command bar and Spaces mentions | Records MCP (run token) · Actions MCP (relayed) · a chat turn · a duty |
| **An outside system** | Holds a key: asks one base through the token API, rate-limited, logged under the key's label | `POST /api/v1/ask`, `/api/v1/search` (§8) |
| **A sibling application** | Help Desk's expert answering a ticket from the manuals; Projects' expert checking a code question in a project | K7 `ask` / `search` / `bases_index` over an approved connection, only for bases shared with that application |
| **The kernel** | Sign-on, the directory, the agents, approvals, the command bar (chat endpoint), metering MaluDB's calls (K24), the siblings' reads (K7) | — |
| **System** | The worker: text extraction and parsing, builds, reindex and embeddings, feeds, URL checks, the outbox, retention, rate counters, the Archivist's wake | `source = 'cron'`, every row logged |

Tenancy: the business running the application is the tenant — one installation, one record database, **one memory database
of its own** for the bases (§6, §13.3) beside the tenant's agent memory, whose episodes still carry this application's activity.

## 3. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`; ordered as shown). The base role's key is
`user` so the installer's `--grant-standing-departments` makes every insider a Member when installed that way.

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `reader` | Reader | read | `bases.read` (the bases shared with me: ask, read the code tree and provisions, browse sources and the graph, see my own questions), `answers.rate` |
| `user` | Member | write | Reader's + `bases.create` (a base of my own — I am its curator), `sources.add` (where given curate), `questions.own` |
| `curator` | Curator | write | Member's + on my bases: `base.manage` (name, template, jurisdiction, edition, visibility, areas, guidelines, vocabulary, settings), `sources.manage` (add, replace, remove — removing pauses for an agent; layers and effective dates), `amendments.review` (confirm or reject what the parser proposed), `builds.run`, `base.share` (to members and departments; to a guest or an application pauses for an agent), `keys.manage` (pauses for an agent), `answers.pin`, `questions.review`, `feeds.manage` |
| `admin` | Knowledge admin (is_admin) | admin | every right on every base + `settings.manage`, `model.manage` (pauses), `limits.manage`, `retention.manage`, `jurisdictions.manage`, `keys.all`, `costs.read`, `agents.settings`; **sees private bases, logged** `base.admin_view` |

The `curator` role is application-wide capability; who curates *which* base is `base_members.level = 'curate' | 'own'`.

**Reach** (`kn_visible_base_ids()` as ONE PL/pgSQL function returning the caller's set, tested once per statement in every
view — the kernel's db/160 lesson; `kn_can_read_base(id)`, `kn_can_curate_base(id)`):

- **A base is private or internal.** Private: its `base_members`. Internal: every member of the business reads (the
  `everyone` principal); its members with `curate`/`own` curate. Guests never see an internal base unless named. The admin
  sees everything (logged). **Regulatory bases default to private** (the model codes and standards are licensed text).
- **`base_members (base_id, principal_kind member|department|agent|application|everyone, principal_id, level
  read|curate|own)`**. An **application** principal (a sibling's catalog key) is what K7 checks.
- **Sources, provisions, amendments, areas, builds, the graph and the question log follow their base.** A Reader reads
  provisions, sources and the graph (that is how an answer is trusted) but **not** guidelines, keys, other people's questions
  or costs.
- **A cross-reference into another base** (IFC §903.3.1.1 → NFPA 13) resolves only when the asker may read that base too;
  otherwise the citation shows "NFPA 13 — not in a base you may read".
- **A question belongs to its asker and its base**; curators see all of the base's; a key's questions show the key's label.
- **The engine is asked only about namespaces the caller may read**; the MaluDB token never leaves `app/knowledge/engine.php`.
- **An agent sees what its member id may see**; the run-facts gate decides the tools; `is_eval` refuses every write.
- **Scope: none.** One business, one installation; departments are principals, never a wall.

## 4. Doors for those who are not inside — guests, keys and the siblings

The kernel's rules: *application users are not OS users*; *never a password, a login form or an account of the
application's own*; for a reach with no account, **a token is the authority**.

**A guest is a kernel member flagged external**, granted Reader by the super-admin; they land on the bases shared with
them. A curator shares a base to a guest (`base.share` — pauses when an agent does it).

**A key** (`mcp_access_tokens` with `scope = 'api'`, **appended** `base_id`, `focus_area_id`, `rate_per_minute`,
`rate_per_day`) is minted by a curator for one base, shown once, hashed; every call is rate-limited (defaults 30/min,
2,000/day), logged `question.ask` with source `api` under the key's label, and answered exactly as the window answers a
Reader. Rotation keeps the old key 24 h. **The public widget** is Extended — and for a regulatory base it will show
citations and section numbers with a link, never the licensed text.

**A sibling application** reads through **K7**: `ask`, `search` and `bases_index` are `shares[]`; the kernel calls them
over an approved connection; the answer covers only bases with an `application` principal naming the consumer.

**Rejected:** inviting outsiders by email; a base reachable by link alone; the MaluDB token handed to anyone.

## 5. Agents and the application — the OS requirement, in detail

**Asking, browsing, adding sources, writing guidelines, reviewing amendments and building are free; deleting, sharing out,
minting keys and changing the model are a person's.** For every agent:

| Action | Category | Why |
|---|---|---|
| `base_share` to a guest or an application, `key_mint`, `key_rotate`, `base_visibility_set` (private → internal) | `external_send` | Content reaches someone outside, or a system |
| `base_delete`, `source_remove` (forgets it in the engine), `guideline_delete`, `focus_area_delete`, `question_purge`, `key_revoke` (not its own), `amendment_reject` (a confirmed one) | `deletion` | |
| `model_config_save`, `settings_save`, `limits_save`, `retention_set`, `jurisdiction_save`, `build_rebuild_all` | `other` | Configuration, spend |
| `base_create` (private), `source_add` (file, text, URL), `source_replace`, `source_layer_set`, `guideline_save`, `vocabulary_save`, `focus_area_save`, `focus_area_propose`, `amendment_propose`, `amendment_confirm`, `build_start` (new and stale provisions only, under the limit), `answer_pin`, `question_feedback`, `base_share` to a member or department, `feed_sync`, `archivist_proposal_*` | — | An agent must do these alone — this is what makes it a curator |

**Working — how an agent learns there is work.** (a) **The command bar and Spaces**: a person's turn on the expert
through the kernel's chat endpoint; the expert answers with `ask` (the same cited answer) and the graph tools, scoped to what
the asker may read. (b) **A duty** (cron) for the Archivist. (c) **A proposal needing a hand** writes an `agent_dispatches`
row: "the parse of the Cook County amendments found 31 overrides, 4 unmatched — review" is a person's screen; "the first
build finished — propose areas" wakes the expert through one chat turn (K8 when it lands).

**Shipped agents** (`maludb-os.json` `agents[]`; **hired on install** at the owner's word — §13.13):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — Knowledge Expert | The command bar and the house name: answers any question from the bases the asker may read, **always with the provision quoted and cited**, saying which base and edition answered and as of what date; asks for the facts a code question needs (occupancy group, area, height, jurisdiction, new or existing) when they are missing; explains a provision, its exceptions, its amendments and what it references; walks a path ("how does 903.2.7 relate to NFPA 13's 8.15"); **proposes focus areas** (chapters for a code; communities for a plain corpus); **drafts guidelines** from a sample for a base without a template; writes a chapter or area summary when asked; suggests questions; never deletes, shares out or mints a key unless the asker says so, and then pauses | every records tool, `ask`, `search`, `provision`, the graph tools, `record_history` | `focus_area_propose`, `focus_area_save`, `guideline_save`, `vocabulary_save`, `answer_pin`, `build_start`, `source_add` (text), `base_share` (member/department), `key_mint` (paused), `source_remove` (paused) | `key_mint`, `source_remove`, `base_share` to a guest or an application |
| `archivist` — Knowledge Archivist | A duty every Monday 06:45 (`45 6 * * 1`): the **base report** per base — sources that failed, have no text or are stale against the guideline version; **amendments proposed and not yet reviewed, and unmatched amendment targets**; provisions that reference a section or standard **not in any readable base**; URL sources whose page changed or vanished; feeds that stopped; builds that failed; the monthly limit nearly spent; **a base whose effective-to date has passed or whose jurisdiction has a newer edition noted**; the **answer report** — questions with no evidence (the gaps, clustered: "nine questions about fire pumps; NFPA 20 is not a source"), thumbs-down answers with their evidence, pinned answers past review, duplicate subjects the vocabulary should merge, areas with no source and sources in no area; each a **proposal** a curator accepts or dismisses; one Monday note per base to its curators and one to the admin's assistant | the report tools of §7, `get_base`, `find_sources`, `amendments_pending`, `unresolved_references`, `question_gaps`, `duplicate_subjects`, `graph_stats` | `archivist_propose`, `notification` to curators, `message_send` (the kernel's, to the admin's assistant) | nothing (it never deletes, shares, mints or rebuilds) |

**Skills** (`skills/`): `knowledge-basics` (what a base, a source, a provision, a guideline of each kind, a template, a
focus area, a build, a question and a key *are*; which tool answers what; always cite, never guess), `reading-a-code`
(parts, chapters, sections, exceptions, tables, definitions, referenced standards, appendices, amendments and precedence,
effective dates, the AHJ; how to cite; local vs global questions; the facts a code question needs), `writing-guidelines`
(the five kinds with examples for codes, contracts, manuals, policies), `asking-well`, `reading-the-graph`; runbooks
(`kind: runbook`): `answer-a-code-question` (facts → provisions → exceptions → amendments → quote → AHJ line),
`propose-focus-areas`, `draft-guidelines-from-a-sample`, `monday-base-report`, `merge-duplicate-subjects`.

## 6. Memory model — what Knowledge remembers

Two stores, deliberately apart: **record memory** (PostgreSQL 17, `<tenant>_knowledge`) holds the bases, sources, text and
anchors, **provisions and amendments**, guidelines, areas, builds, questions and keys; **the graph** lives in MaluDB — **a
memory database of its own** (`<tenant>_knowledge_memory`, `maludb_core` enabled, its own API token; §13.3), **one namespace
per base** (`kb:<id>`). The tenant's memory keeps receiving this application's activity episodes as every sibling's does.

### 6.1 From a file to a provision tree — the parsers (`app/knowledge/parse/`, the worker)

**Text extraction** first: PDF through `pdftotext -layout` per page (a PDF with no text layer is `no_text`); DOCX, PPTX and
XLSX as the XML inside the zip (`ZipArchive` + `DOMDocument`) with a heading map; HTML through a reader pass; Markdown and
plain text as they are; CSV and JSON flattened; a **URL** fetched server-side (http/https, public hosts, 10 MB, 20 s, one
page); pasted text verbatim. Each yields `source_text.text`, `source_pages` and `source_sections`.

**Then the parser the source's role and family call for** (`sources.family`): `icc` (`903.2.1.1`, "Exception:", "Exceptions: 1.
2.", `Table 903.2.11.6`, Chapter 2 definitions, Chapter 80 standards), `nfpa` (`8.15.1.2`, "A." annex notes, Chapter 3
definitions, Chapter 2 referenced publications), `municipal_title` (Chicago's `14F-9-903`, `15-16-010`), `ordinance_sections`
(Cook County's `Sec. 102-24`), `cfr` (`§ 1910.157(c)(1)`), and `document` (headings and pages — the general template's).
A parser emits the **provision tree**: `number` (the code's own, normalised), `label`, `heading`, `kind` (part, chapter,
section, subsection, exception, table, figure, definition, note, appendix), parent, position, the text and its char
range; the **defined terms** (number, term, definition); the **references** (from a provision to a number, a table or a
standard, with the raw phrase); and, for an **amendment** source, the **proposed overrides**: "Section 903.2 is amended to
read …" → `amend 903.2`, "Section 307.4.3 is deleted" → `delete`, "Add Section 503.2.1.1" → `add`, "Reserved" → `reserve`,
each with the override's text and a confidence. **A curator reviews the overrides** (`amendment-review`): confirm, edit the
target number, reject; an unmatched target ("Section 912.7, which does not exist in this edition") is flagged. A parser is
deterministic PHP (patterns, a numbering state machine, a tables heuristic); it never calls a model; its proof is a fixture
per family in `tests/parsers/` (a chapter of each real code). `docs/build-specs/code-parsers.md` is its spec.

**The effective provision.** `kn_effective_provision(base, number, as_of)` applies the confirmed overrides in order of
`sources.layer` (higher wins: a city ordinance over the state's over the model code) within `effective_from`/`to`; the
graph learns **effective provisions only** (each one MaluDB document, metadata `{base, number, kind, edition,
jurisdiction, layer, effective_from, effective_to, status: adopted|amended|added}`); a provision that is deleted or not
adopted is not ingested; the original text of an amended section stays in record memory for the reader ("the IFC text",
"Chicago's text") — never in the graph, so an answer cannot quote a superseded requirement as current. A change to an
override (confirm, reject, edit) marks that provision **stale** and a build re-ingests it alone — forget, then ingest.

### 6.2 How a person's words become the engine's (`app/knowledge/compile.php`, on every guideline save)

| The person writes | The application keeps | MaluDB receives |
|---|---|---|
| a **base** from a **template** | `knowledge_bases` (+ `template`, `jurisdiction_id`, `edition_label`, `effective_from/to`) | a namespace `kb:<id>` and its **memory config** (`POST /v1/memory/config`: the admin's model alias, the compiled `prompt_template`, `default_subject_type`, the embedding model); the template's **subject types and verbs** seeded |
| an **extraction** guideline (the regulatory template ships: "every provision is a subject of type *provision* with its number; extract what it requires, permits or prohibits, for which occupancy, system or material, above what threshold; keep exceptions as their own statements; a defined term is a subject of type *term*") | `guidelines` kind `extraction`, versioned | lines in the namespace's `prompt_template` and `hints[]` on every ingest |
| a **vocabulary** guideline + terms (the definitions chapter seeds them; a curator adds "PG17 = Postgres 17") | `guidelines` kind `vocabulary`, `vocabulary_terms`, `defined_terms` | **subjects seeded with aliases and a type** before the build |
| an **exclusion** guideline (shipped: page headers and footers, copyright notices, tables of contents, annex commentary marked "A." unless adopted, "never state a requirement the text does not contain") | `guidelines` kind `exclusion` | prompt lines; an answer-time filter |
| an **answering** guideline (shipped for codes: "quote the provision verbatim with number, edition and jurisdiction; list every exception; flag amendments by name; thresholds exactly as written; if the facts needed are missing, ask for them; end with *confirm with the authority having jurisdiction*") and an **audience** guideline (shipped: "fire inspectors, plan reviewers, architects and contractors; precise, no legal advice") — **apply at once, no rebuild** | `guidelines` kinds `answering`, `audience` | the system prompt of the answer route (M1) and of the expert's turn |
| a **focus area** (a chapter: "Chapter 9 Fire Protection and Life Safety Systems"; or a topic: "Sprinklers — 903, 904, NFPA 13") | `focus_areas` | a **project** (`classifier_md` = the description, `subjects[]` = the seed terms and the chapter's provisions); a question scoped to it seeds retrieval from the project's subjects |
| a **source** with a **role** and a **layer** | `sources` (+ `role`, `family`, `layer`, `edition_label`, `effective_from/to`), its provisions | one document **per effective provision** (§6.1) with the parser's `edges[]` |
| a **build** | `builds` + `build_items` (one item per provision or section) | the ingest calls; then `/v1/memory/reindex/run` and `/v1/memory/embeddings/run`; counts from `/v1/graph/stats` cached |

A change to an **extraction, vocabulary or exclusion** guideline bumps `knowledge_bases.guideline_version`; provisions
built under an older version show **stale**; `build_start` re-extracts new and stale provisions under the base's monthly
limit; `build_rebuild_all` is a person's. **`preview`** runs one provision through `POST /v1/memory/ingest {preview: true}`.

**Templates** (`base_templates`, seeded, versioned, the owner's to extend): **`regulatory`** — the guidelines above, subject
types `provision, term, occupancy, system, material, quantity, standard, jurisdiction, document`, verbs `requires,
permits, prohibits, applies_to, excepts, defines, references, amends, deletes, adds, supersedes, part_of, has_threshold`,
areas proposed **from chapters**, the citation format, the AHJ line, default visibility private, the parser families;
**`general`** — NotebookLM-shaped: sections as units, areas from communities, a light extraction prompt, citations by
heading and page. A base may switch template only while empty.

### 6.3 Asking

`kn_ask()` (`app/knowledge/ask.php`; one function behind the window, the API, the MCP tool, the K7 share and the expert):
gate (base readable, key valid and under its rate, question ≤ 2,000 characters) → **`as_of`** (default today; a base
whose effective window excludes the date answers from the base that covers it when the jurisdiction has one, else says so)
→ optional **`facts`** (free text the asker gives: "new building, Group M, 10,000 sq ft, one storey, unincorporated Cook
County" — passed to retrieval and the prompt; structured facts are Extended) → the last three turns → **M1** `POST
/v1/memory/answer {namespace, project?, question, facts, history, system: the compiled answering prompt, limits}` →
`{answer_md, confidence, citations[{document_id, quote}], evidence[]}` → documents mapped to **provisions** (number, kind,
edition, jurisdiction, the amendment chain, the original on request), **cross-references followed one hop** into other
readable bases (NFPA 13 cited from 903.3.1.1) → a `questions` row with `citations` and `evidence`, `status answered |
evidence_only | no_evidence | needs_facts | refused | failed` → the reply. **Until M1:** `kn_search()` (`graph/query` +
`memory/recall` over the base's namespace, ranked, mapped to provisions, capped at twelve) answers with **the provisions
themselves** — for a code that is already most of the value — and the window's "Ask the expert" runs the kernel's chat
endpoint. Weak evidence is `no_evidence` ("the code holds nothing on this; nearest sections: …") and a **gap**.

**Record memory** (PostgreSQL 17, `<tenant>_knowledge`, db/001–0NN; prefix `kn_` on functions, views `mcp_*`):

| Area | Tables | Notes |
|---|---|---|
| The mirror | `members` (+ `is_agent`, `is_external`), `department_members`, `departments`, `sso_nonces`, `member_sessions`, `directory_sync_state` | the kernel's ids; `members.roles text[]` |
| Roles | `kn_rights`, `kn_roles`, `kn_role_rights`, `mcp_app_roles` | `kn_has_right(right)` |
| Settings | `kn_settings` (one row: default visibility per template, default monthly limit (MB of text per base per month, 50), max source bytes (50 MB), URL fetch allowed, evidence threshold, answer language mode, question retention days (365), key defaults, review months for pins (6), the MaluDB model alias and embedding model — **read from MaluDB, changed with a pause**) | seeded |
| Templates | `base_templates` (key, name, version, `guidelines jsonb`, `subject_types text[]`, `verbs text[]`, `area_rule` chapters/communities, `citation_format`, `default_visibility`, `parser_families text[]`) | seeded: `regulatory`, `general` |
| Jurisdictions | `jurisdictions` (id, name, `kind` city/county/state/federal/other, `parent_id`, notes), `jurisdiction_bases` (jurisdiction, base, `code_kind` fire/building/mechanical/…, `effective_from/to`) — which base answers for a place as of a date | seeded with none; the proof adds Chicago and Cook County |
| Bases | `knowledge_bases` (id, `key`, name, description, `template`, `visibility`, `owner_member_id`, `namespace`, `jurisdiction_id`, `edition_label` ("IFC 2018 + Title 14F + Title 15"), `effective_from/to`, `supersedes_base_id`, `status` empty/parsing/review/ready/building/needs_rebuild, `guideline_version`, `auto_extract`, `monthly_text_mb_limit`, `sensitivity`, `language`, counts cached, `last_built_at`, `archived_at`), `base_members` (§3) | a base's namespace is set once |
| Sources | `sources` (id, base, `kind` file/text/url/feed/note, **`role`** model_code/amendment/own_chapter/standard/document, **`family`** icc/nfpa/municipal_title/ordinance_sections/cfr/document, **`layer`** int, `edition_label`, `effective_from/to`, title, origin, `attachment_id`, `text_sha256`, `char_count`, `page_count`, `status` uploaded/extracting_text/parsing/review/ready/no_text/failed/forgotten, `provisions_count`, `error`, `added_by/at`, `superseded_by`, `url_etag`, `url_checked_at`), `source_text`, `source_pages`, `source_sections`, `source_focus_areas` | a replaced source's provisions are forgotten and re-parsed |
| Provisions | `provisions` (id, base, source, `number`, `number_sort` (zero-padded for ordering), `label`, `heading`, `kind`, `parent_id`, `position`, `text`, `char_from/to`, `page_no`, `depth`, `status` adopted/not_adopted/reserved/deleted/amended/added/stale, `extracted_version`, `maludb_document_id`, `extracted_at`, `error`), `defined_terms` (base, provision, term, definition, `vocabulary_term_id`), `provision_references` (from_provision, `raw`, `kind` section/table/figure/standard/definition, `to_number`, `to_standard`, `to_provision_id` (resolved, maybe in another base), `to_base_id`), `provision_overrides` (base, `target_number`, `action` amend/delete/add/reserve, `override_provision_id`, `amendment_source_id`, `layer`, `effective_from/to`, `confidence`, `status` proposed/confirmed/rejected, `decided_by/at`, `note`) | `kn_effective_provision()`, `kn_code_tree(base, as_of)` (the TOC with effective status per node, one recursive query), `kn_provision_chain(id)` (original → each override) |
| Files | `attachments` (the bytes under `storage/sources/<base>/<id>/`) | GL's canonical; `record_type = 'source'` |
| Guidelines | `guidelines`, `guideline_versions`, `vocabulary_terms` (base, canonical_name, aliases, subject_type, description, `maludb_subject_id`, `from_definition` bool) | a save of a graph-shaping kind writes a new version |
| Focus areas | `focus_areas` (id, base, name, slug, description, `seed_terms`, `example_questions`, `chapter_provision_id` (when it is a chapter), `maludb_project_id`, `status` proposed/active/archived, `proposed_by`, `proposal_note`, position) | an area is a MaluDB project |
| Builds | `builds` (id, base, `kind` extract/rebuild_all/reindex/embed/forget, status, `started_by`, times, `items_total/done/failed`, `chars_processed`, `subjects_added`, `statements_added`, `usage jsonb`, `log`), `build_items` (build, provision or section, status, times, error, usage) | one build per base at a time |
| Questions | `questions` (id, base, `focus_area_id`, `asked_by_member_id`, `token_id`, `application_key`, `conversation_id`, `turn_no`, `door`, question, **`as_of date`**, **`facts text`**, `answer_md`, `status`, `confidence`, `evidence jsonb`, `guideline_version`, `model`, `latency_ms`, `usage`, `created_at`), `citations` (question, rank, **`provision_id`**, source, page_no, section, quote, `maludb_chunk_id`, `maludb_statement_ids`), `question_feedback`, `pinned_answers` (base, question, title, `answer_md`, `owner_member_id`, `review_until`, `verified_at`, `source_id` (the note), status) | the question text retained per the setting |
| Keys | `mcp_access_tokens` (+ `base_id`, `focus_area_id`, `rate_per_minute`, `rate_per_day`, `rotated_from`, `allowed_origins`), `key_usage` | `kn_rate_ok(token)` |
| Feeds | `feeds` (base, provider, tool, `arguments`, area, `every`, `last_sync_at`, `last_status`, `items`) | Spaces' pages, Help Desk's articles (HD2) through K7 |
| Agents | `agent_dispatches` (+ `base_id`, `build_id`), `archivist_proposals` (base, `kind` area/merge/gap/stale_source/failed_source/review_pin/unassigned/amendment_pending/unresolved_reference/edition_note, payload, status, decided_by/at) | §5 |
| Notifications | `notifications` (build_done, build_failed, parse_done, review_needed, limit_near, proposal, feedback, review_due, monday_note), `notification_prefs`, `notification_outbox` | GL's canonical |
| Notes | `notes` (on a base, a source, a provision) | GL = CT canonical |
| Usage | `ai_usage` (base, month, `kind` extract/answer/embed, calls, input_tokens, output_tokens, chars) | the application's own count; dollars are the kernel's (K24) |
| Tokens | `mcp_access_tokens` with `scope = 'mcp'` | a person's own |

### 6.4 Tables against the estate — read, reuse, new (the shared-schema rule, 2026-10-05)

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | **reuse** | the kernel contract (Consultant Tracking `db/001`); `members` **appends** `is_agent`, `is_external` (Spaces') |
| `activity_log`, `activity_ingest_state` | **reuse** | `memory.md` / CT `db/002`; **appends** `base_id` |
| `mcp_access_tokens` | **reuse** | CT `db/003`; **appends** the bound-token columns (`base_id`, `focus_area_id`, `rate_per_minute`, `rate_per_day`, `rotated_from`, `allowed_origins`) — proposed to the catalogue |
| `kn_rights`, `kn_roles`, `kn_role_rights`, `mcp_app_roles` | **reuse** (shape) | CT `db/004` (`ct_` → `kn_`) |
| `kn_settings` | **reuse** (skeleton) | the `app_settings` singleton skeleton |
| `attachments` | **reuse** | **GL `db/009` canonical**, verbatim; `record_type` widened to `source` |
| `notes` | **reuse** | GL `db/009` = CT `db/006`; `record_type` widened to `base`, `source`, `provision` |
| `notifications`, `notification_prefs`, `notification_outbox` | **reuse** | **GL `db/014` canonical**; `kind` lists ours; every recipient a member (Spaces' substitution, recorded) |
| `agent_dispatches` | **reuse** | GL `db/014` = CT `db/013`; `kind` widened; **appends** `base_id`, `build_id` |
| `ai_usage` | **new** (recorded) | GL's `ai_statements` are the kernel's statement imported; CT's usage lines are pass-through billing; an application's own count of engine calls per base per month is nothing close — canonical |
| `questions`, `citations`, `question_feedback`, `pinned_answers` | **new** | a logged question with evidence and citations exists nowhere — canonical |
| `provisions`, `defined_terms`, `provision_references`, `provision_overrides`, `jurisdictions`, `jurisdiction_bases`, `base_templates` | **new** | a numbered legal tree with amendments and effective dates exists in no sibling — canonical for "a code" |
| `knowledge_bases`, `base_members`, `sources`, `source_text`, `source_pages`, `source_sections`, `source_focus_areas`, `guidelines`, `guideline_versions`, `vocabulary_terms`, `focus_areas`, `builds`, `build_items`, `key_usage`, `feeds`, `archivist_proposals` | **new** | nothing close (Help Desk's `articles` and Spaces' `pages` are authored content — **read** below); `sources` becomes the canonical "ingested document with text anchors" |
| Help Desk's `articles`, Spaces' `pages`, Projects' `issues`, the ledger's parties, HR's employment | **read** | a feed through K7 when the sibling shares it; never copied except as a `feed` source keyed by the provider's id |

**Owed to the catalogue**: `mcp_access_tokens`'s bound-token columns; `sources`, `provisions` (+ `defined_terms`,
`provision_references`, `provision_overrides`), `questions`/`citations`, `jurisdictions` and `ai_usage` as Knowledge's canonical tables.

**Views and functions**: `mcp_*` over every table above with `security_barrier` and `kn_visible_base_ids()` once per
statement; the reads that matter are SQL functions — `kn_base_overview(base)`, `kn_code_tree(base, as_of)`,
`kn_effective_provision(base, number, as_of)`, `kn_provision_chain(id)`, `kn_provision_markdown(id)` (the provision with its
exceptions and tables, as the answer quotes it), `kn_references_out(id)`, `kn_references_in(id)`, `kn_unresolved_references(base)`,
`kn_source_page(source, page)`, `kn_questions(base, filters)`, `kn_question_gaps(base, since)`, `kn_build_progress(build)`,
`kn_rate_ok(token)`, `kn_usage(base, month)`; the engine reads are **one PHP module** (`app/knowledge/engine.php`), the only
place the MaluDB token is read.

**Activity memory**: `activity_log` through one `log_activity()`, shipped to the tenant's one MaluDB as `activity` episodes
tagged `knowledge`. **A source's text, a provision's text and a question's text are never in a log payload** (ids, numbers,
titles, counts, the kind, the status, 120 characters of the question at most). Events: `base.create|update|share|unshare|
visibility_set|archive|delete|admin_view`, `source.add|replace|text_extracted|no_text|parsed|fail|remove|forget|layer_set`,
`provision.stale|extract|fail`, `amendment.propose|confirm|reject|edit`, `guideline.save|delete|version`,
`vocabulary.save|sync`, `focus_area.save|propose|accept|dismiss|delete`, `build.start|progress|done|fail|cancel|rebuild_all`,
`question.ask|answer|no_evidence|needs_facts|refuse|feedback|pin|unpin|purge`, `key.mint|rotate|revoke|rate_limited`,
`feed.save|sync|fail`, `jurisdiction.save`, `archivist.propose|accept|dismiss`, `agent.dispatch|reply|fail`, `settings.save`,
`model_config.save`, `share.read`, `token.mint|revoke`, `member.sign_on`, `directory.sync`, `screen.view`. A key is never logged.

## 7. The question inventory — what Knowledge exists to answer

Kind R = record (the records MCP), A = activity (the activity MCP). Who = the lowest role that may ask (every answer is
filtered by what the asker may see). The tool name is fixed here so Phase 1 is a transcription.

**Bases and jurisdictions**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| B1 | Which knowledge bases may I read, what is each about, which jurisdiction and edition, how big, when last built? | R | Reader | `find_bases`, `get_base` |
| B2 | Which base answers for Cook County's fire code as of 2021-11-15? | R | Reader | `base_for_jurisdiction` |
| B3 | Who may read or curate base X, and why? | R | Curator | `base_members` |
| B4 | What are the application's settings, templates and the model in use? | R | Reader / Admin (model) | `get_settings`, `find_templates` |

**Sources and the code**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| S1 | What sources are in base X, by role, layer, status and area; which are stale, failed or without text? | R | Reader | `find_sources`, `get_source` |
| S2 | What is the code's table of contents as of date D — chapters, sections, each adopted, amended, added, deleted or reserved? | R | Reader | `code_tree` |
| S3 | **What does provision 903.2.7 say, effective as of D** — its text, exceptions, tables, the amendment chain, the original? | R | Reader | `provision`, `provision_chain` |
| S4 | What does provision X reference, and what references it (sections, tables, standards — resolved into other bases I may read)? | R | Reader | `references_out`, `references_in` |
| S5 | Which references point at a section or a standard not in any base I may read? | R | Curator | `unresolved_references` |
| S6 | Which defined terms does the code have, and where is each defined? | R | Reader | `defined_terms` |
| S7 | Which amendments are proposed and not reviewed, confirmed, rejected, or unmatched? | R | Curator | `amendments_pending`, `find_amendments` |
| S8 | What does source X say — its outline, one page, the text around a citation? | R | Reader | `source_outline`, `source_page`, `source_excerpt` |
| S9 | Which subjects and statements did provision or source X contribute to the graph? | R | Reader | `provision_graph`, `source_graph` |
| S10 | What feeds does the base follow, and when did each last sync? | R | Curator | `find_feeds` |

**Guidelines and areas**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| G1 | What are base X's guidelines, by kind, and which version is the graph built under? | R | Curator | `find_guidelines`, `guideline_versions` |
| G2 | What would these guidelines extract from this provision (a preview)? | R | Curator | `extraction_preview` |
| G3 | What is the vocabulary — terms, aliases, types, which came from the definitions chapter, which the graph never met? | R | Curator | `find_vocabulary`, `unused_terms` |
| G4 | What focus areas exist, with their terms, example questions and size; which are proposed and by whom? | R | Reader | `find_focus_areas`, `get_focus_area` |
| G5 | Which sources or provisions are in no area, and which areas have no source? | R | Curator | `unassigned_sources`, `empty_areas` |

**Builds**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| L1 | Is a build running on base X, how far, what failed? | R | Curator | `build_progress`, `find_builds` |
| L2 | What would a build do now (how many provisions, how many characters, against the monthly limit)? | R | Curator | `build_estimate` |
| L3 | How much text and how many engine calls did base X use this month? | R | Curator | `base_usage` |

**The graph**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| K1 | What is the graph of base X — nodes, edges, communities, top relationships? | R | Reader | `graph_stats` |
| K2 | What are the communities, and what is in community C? | R | Reader | `communities`, `community_members` |
| K3 | What is subject Y (an occupancy, a system, a term) — its statements, neighbours, the provisions behind it? | R | Reader | `get_subject`, `subject_neighbors`, `subject_statements` |
| K4 | How are Y and Z related (a path — two provisions, a provision and a standard)? | R | Reader | `graph_path` |
| K5 | The most connected subjects, and the surprising links between communities? | R | Reader | `god_nodes`, `surprises` |
| K6 | What subgraph answers "…"? | R | Reader | `graph_query` |
| K7 | Which subjects look like duplicates the vocabulary should merge? | R | Curator | `duplicate_subjects` |

**Asking**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| Q1 | **Answer this from base X (or area A), as of D, given these facts, with the provisions quoted and cited** — the one tool behind every door | R | Reader | `ask` |
| Q2 | What evidence does base X hold on this (provisions, statements, passages; no composition)? | R | Reader | `search` |
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
| ACT1 | Who did what to this base, source or provision, in order? | A | Reader | `record_history` |
| ACT2 | What did person, key or agent X do here this week? | A | Curator | `actor_timeline` |
| ACT3 | What changed since I last looked? | A | Reader | `recent_activity` |
| ACT4 | Which siblings read us, and how often? | A | Admin | `share_reads` |
| ACT5 | Search the activity for a phrase | A | Reader | `activity_search` |

Plus the contract's: `app_roles`, one guarded `search` on each server (the records one IS Q2), `record_history`. Records
tools: about 52; activity tools: 6.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit from Consultant Tracking.
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it and the `shares[]` alone.
- **Agents**: run tokens on the two read servers (the run-facts gate, fail closed); the Actions MCP from the registry; the
  approval hook before every action with a category; the chat endpoint for the command bar and dispatched proposals; the
  duty for the Archivist.
- **The engine**: `MALUDB_KB_API_URL` and `MALUDB_KB_API_TOKEN` (the knowledge memory database's own token, minted at
  install by `deploy/ROOT_STEPS.sh`), read only by `app/knowledge/engine.php`; a missing token makes every build and ask
  answer `engine_unavailable`, never a crash. The model alias and provider live in MaluDB's config; the admin's screen shows
  them and changes them through `POST /v1/memory/config` with a pause.
- **Metering (K24)**: MaluDB's provider `base_url` for this memory database points at the kernel's ledger proxy with an
  **application credential**, so every extraction, embedding and answer call is ledgered, priced and stamped with the
  application — the AI statement shows "Knowledge — extraction" beside the agents. Until K24: MaluDB's own key and `ai_usage`.
- **The token API** (bearer = a key; allow-listed in the vhost): `POST /api/v1/ask {question, focus_area?, as_of?, facts?,
  conversation_id?}` → `{question_id, status, answer_md, confidence, as_of, citations[{provision: {number, label, heading,
  kind, status, edition, jurisdiction}, source, page, quote, url}], evidence[], conversation_id}`; `POST /api/v1/search {query,
  as_of?, limit}`; `GET /api/v1/bases`; `GET /api/v1/provisions/{number}?as_of=` (the effective provision, for a system that
  shows the text itself); `GET /api/v1/questions/{id}`; `POST /api/v1/questions/{id}/feedback`; `GET /api/v1/health`. 401,
  403, 429 with `Retry-After`, 503 `engine_unavailable`. Logged `question.ask` with source `api`.
- **K7 — Knowledge is a provider.** Shares, every one a records-server tool, people-free:
  | Tool | For | Document | Content |
  |---|---|---|---|
  | `ask` | Help Desk's expert, Projects' expert, Spaces' expert, Consultant Tracking | `os.knowledge-answer/1` | `{base, question, focus_area?, as_of?, facts?}` → the cited answer, **only for bases shared with the consumer application**; citations carry numbers, titles and quotes, never more of the licensed text |
  | `search` | the same | `os.knowledge-evidence/1` | evidence over the shared bases |
  | `bases_index` | the same | `os.knowledge-bases/1` | the bases shared with the consumer: key, name, jurisdiction, edition, areas, last built |
  Reads (`reads[]`): `spaces.pages_index` + `spaces.page_markdown` (a space as a feed, when Spaces is built) and
  `helpdesk.articles_published` (HD2, owed) — absent, a feed shows `no_connection`.
- **Public doors** (allow-listed in the vhost): `/api/v1/*` (key-checked), `/mcp/records`, `/mcp/activity`.
- **Live-ness without a socket**: the ask window posts and waits up to 20 s for M1; the expert path answers 202 and the
  window polls every 3 s; a parse's and a build's progress poll every 5 s while visible.

## 9. Screens (the nxl look; the ask window and a provision are read at 375 px first; the code tree and the graph explorer
are designed at 1280 px and work at 375; no modals; cards for named things — bases, sources, areas, pinned answers —,
tables for the code tree, the question log, builds, keys, amendments and admin lists; every name a link; every page a way back)

**The shell**: a sidebar at 1280 (Home · Ask · **Bases** (each base: Ask, **Code** (the tree), Sources, Amendments, Areas,
Graph, Guidelines, Builds, Questions, Keys, Share, Settings) · Pinned · Admin), a bottom tab bar at 375 (Home · Ask · Bases ·
Me). The command bar at the top of every screen.

**Home** — my bases as cards (template, jurisdiction, edition, status, size, last built, "needs a rebuild", "amendments to
review"); builds and parses running; my recent questions; pins due for review; the Archivist's last note; curators: the
week's gaps and thumbs-down; admin: usage by base, failed builds, pending dispatches.

**Ask** — `/ask` (choose a base or a jurisdiction and code kind, optionally an area; **as of** (today by default); a **facts**
line for a code question; the conversation; each answer with its confidence, the **provisions quoted** with their number,
edition and amendment badges, citations as chips opening `provision-view`, "show the evidence", thumbs with a comment,
"pin" (curators), "ask the expert" when evidence-only; suggested questions when empty) — the Reader's screen, 375 px first.

**Bases:** `/bases` (cards; create from a template) · `base-view` (overview: template, jurisdiction, edition, effective
window, counts, areas, last build, status, an ask box, the curators) · `base-form` · `base-share` · `base-settings`.

**Code:** `code-view` (the **table of contents as a tree** as of a date: parts, chapters, sections with their status badges —
adopted, amended, added, deleted, reserved —, a number search, expand/collapse, "ask about this chapter") ·
`provision-view` (the effective text with exceptions and tables; badges; **the amendment chain** — the original IFC text,
each override with its source and layer —; defined terms highlighted and linked; references out and in; the provisions
nearby; the subjects and statements it taught; "ask about this section"; the history) · `definitions` (the defined terms →
the vocabulary).

**Sources:** `sources` (grouped by layer and role; status; add: drop files, paste, URL, follow a feed; set role, family,
layer, edition, effective dates) · `source-view` (title, origin, status and errors, the outline or the provision count, the
pages, the areas, "what it taught", replace, remove) · `source-page` · `feed-form`.

**Amendments:** `amendment-review` (the overrides a parse proposed, by source: action, target number, the override text
beside the target's current text, confidence; confirm, edit the target, reject; the unmatched; "confirm all above 0.9") ·
`amendments` (every override, confirmed or not, with its effect on the tree).

**Guidelines and areas:** `guidelines` (the five kinds; the template's shipped set shown as the starting point; version
and "built under N"; "needs a rebuild") · `guideline-form` (with the kind's examples; **Preview on a provision**) ·
`vocabulary` (terms; from the definitions chapter marked; import CSV; merges) · `areas` (cards; proposed ones from chapters
or communities with accept/dismiss) · `area-form` · `area-view`.

**Builds:** `builds` (the estimate against the limit; start; rebuild all, paused for agents; the list) · `build-view`
(progress by provision, errors, counts, usage, cancel).

**Graph:** `graph` (stats; communities; god nodes; surprises; search → `subject-view`) · `subject-view` (type, aliases,
statements by verb, neighbours, the provisions behind it, a small SVG neighbourhood at 1280, "ask about this") ·
`community-view` · `path`.

**Questions:** `questions` (log with as-of and facts; filters) · `question-view` · `gaps` · `pinned`.

**Keys:** `keys` · `key-form`.

**Admin:** Settings · Templates (read-only; versions) · Jurisdictions (tree; which base answers for which code kind and
dates) · Model (shown, changed with a pause; "test extraction") · Usage · Bases (all; private ones logged) · Keys (all) ·
Agents · Connections · Archivist proposals · My settings · My MCP tokens.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document and the owner's answers (§13); the schema `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`: every actor of §2; a private and an internal base and who sees each for seven actors; sources of every role and family with layers and dates; **a provision tree, a confirmed amendment overriding a section, a deletion, an addition, the effective provision as of two dates, a reference resolved into a second base and one unresolved, the definitions seeding the vocabulary**; a guideline save bumping the version and marking provisions stale; an area; a build with items; a question with citations to provisions, feedback and a pin becoming a note; a key, its counters and a 429; a feed; proposals; retention); the kit proven without a kernel (`tests/phase0/run.sh`); **the parser fixtures** (`tests/parsers/`: a chapter of IFC 2018 and of IFC 2021 as PDF text, a Title 14F chapter and a Title 15 chapter as exported text, a Cook County Chapter 102 export, an NFPA 13 chapter, a CFR part — each parsed to the expected tree, definitions, references and overrides); **the engine proof against the live MaluDB API on a scratch memory database** (`tests/phase0/engine.sh`: config a namespace, seed subjects and types, ingest provisions with provided edges, preview, graph query, recall, communities, forget — recording what 0.3.1 returns, including whether usage is reported — M2); `maludb-os.json`, `os/{expert,archivist}.md`, ten skills (five runbooks), `deploy/` with `ROOT_STEPS.sh` (ports, then the memory database and its token); the installer's `plan` clean | the owner's go on §13 |
| 1 | `docs/knowledge-mcp-tool-surface.md`, `docs/knowledge-action-manifest.md`, `mcp/action_registry.json`, `docs/build-specs/guidelines-compile.md` (how each kind becomes prompt lines, hints, subjects and the answering prompt; the two templates in full), **`docs/build-specs/code-parsers.md`** (each family's grammar, the override patterns, the reference patterns, the definitions rule, the fixtures), and the slice specs (`sso-shell` and nine slices; **the parser and ask are the exemplars**) | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles`, `kn_has_right()`, the ingest bridge, the shell, `/api/v1/health`, the vhost allow-list, the engine module with `engine_unavailable` | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; 375 and 1280 |
| 3 | the slices, in order: **(1) bases, templates, jurisdictions, sharing and settings** (the CRUD exemplar) → **(2) sources and the code parsers — THE FIRST EXEMPLAR** (upload many, paste, URL; text extraction per kind; the six parser families; the provision tree and the code-view; definitions into the vocabulary; references resolved across bases; amendment detection and the review screen; effective provisions as of a date; the general template's sections; replace and remove) → **(3) guidelines, vocabulary and focus areas** (the five kinds, the templates' shipped sets, versions, the compile step, preview on a provision, areas from chapters and from communities, assignment) → **(4) the build** (estimate, queue, the worker ingesting provision by provision with the parser's edges and progress, forget-then-ingest for stale ones, reindex and embeddings, counts, failures and retries, cancel, rebuild-all paused, notifications) → **(5) ask — THE SECOND EXEMPLAR** (`kn_ask()` through M1 with the evidence fallback, as-of and facts, the window at 375, provisions quoted, citations opening the provision, cross-references one hop, the evidence panel, no-evidence and needs-facts, gaps, conversations, feedback, pins as notes, suggested questions, the expert path with 202 polling) → **(6) the graph explorer** → **(7) the API, keys and K7** (incl. `GET /api/v1/provisions/{number}`) → **(8) agents, proposals and feeds** → **(9) home, admin, usage and tokens** | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database and namespace |
| 4 | the two MCP servers with the run-facts gate, `app_roles` and the three `shares[]` admitted to the kernel's token, the registry wrapper, the two agents with their grants and the duty; the Actions-MCP contract proved against the kernel's `application_actions.register()` | the command bar answers "is a sprinkler system required for a 10,000 sq ft Group M in unincorporated Cook County" by quoting 903.2.7 of the County's base with its exceptions and the County's amendment if one applies, and ends with the AHJ line; an agent's `key_mint` pauses; the Archivist mints nothing, deletes nothing, shares nothing |
| 5 | the owner's `app_install.php apply` (ports pinned first), the memory database and its token, K24's credential in MaluDB's config, DNS and TLS, the hires, **the proof corpus**: the owner supplies IFC 2018 and IFC 2021 (PDF), the Title 14F and Title 15 exports, the Cook County Chapter 102 export and ordinance, NFPA 13 and 72 (PDF) | **four bases built — "Chicago Fire Prevention Code (IFC 2018 + 14F + Title 15)", "Cook County Fire Code (IFC 2021 + Ch. 102)", "NFPA 13 (edition as referenced)", "NFPA 72" — shared so the two fire codes resolve into the standards; the parse of Chicago's and the County's amendments reviewed and confirmed by a curator; a Reader on a phone asks the sprinkler question above for each jurisdiction and gets two different quoted answers from two editions with the local amendments flagged; asks it "as of 2021-11-01" for the County and gets the answer that nothing in this base covered that date; asks what NFPA 72 requires for inspection intervals from a citation in 907 and the answer crosses into the NFPA 72 base; asks a question no source covers and gets "nothing on this" and a gap; a key minted and `POST /api/v1/ask` answered from another machine with the provision objects, the 31st call in a minute refused; Help Desk's expert reads `ask` over an approved connection and is refused for the NFPA base not shared with it; the Monday note names an unresolved reference (NFPA 20) and an unreviewed amendment; the kernel's statement shows the extraction tokens (K24)** |

**Extended** (§11). Size: eleven specs (sso-shell, the parsers, nine slices), about 46 screens, 80 actions, 52 records
tools, 6 activity tools. **Four to five days is the honest estimate**: the parsers and the amendment review one and a
half (six families against real fixtures), the build and the worker's extraction one, the ask window and the API one, the
explorer and the agents one, Phase 5 with the owner the remainder — **plus M1 in MaluDB's API**, which turns quoted
provisions into written answers (a day upstream; the evidence fallback keeps every slice provable without it).

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order): **computed applicability** (typed thresholds as MaluDB attributes
(M4) — area, height, occupant load, quantity — and structured facts, so "does 903.2.7 apply to these facts" is evaluated and
shown with its reasoning, the person still deciding) · **the public widget** (citations and numbers with a link, never the
licensed text) · **OCR** (tesseract) · **a site crawl** · **MaluDB principals per member** · **community and chapter reports on
every build** · **PageIndex** when MaluDB v4 ships it · **a code diff between editions** ("what changed from IFC 2018 to 2021 in
Chapter 9" — two bases, matched by number) · **audio and video** · **answer export** · **K8 wakes** · **a base copied as a
template** (a jurisdiction's layers without its sources) · **per-question cost shown to the asker** (K24) · **evals**: a base's
example questions with expected provisions run as an eval suite by the kernel's runner before and after a guideline change
· **more parser families** (state administrative codes, EU directives, ISO-style standards) as the owner names them.

**Later**: a base shared between two installations; training or fine-tuning of anything (never).

## 12. What the kernel, MaluDB and the siblings owe this application

| # | Item | Why the application cannot do it | Until then |
|---|---|---|---|
| **K24** | **An application credential on the ledger proxy**: minted on the application's Overview like the application token, accepted by `ledger_proxy.py` beside run and judge keys, every call priced and posted to the open statement with `application_id` set and no run — so MaluDB's provider `base_url` can point at the proxy and extraction, embedding and answer calls are on the AI statement | the ledger and the proxy are the kernel's; the owner's rule is token accounting for every model call | MaluDB's own provider key; `ai_usage` counts here |
| **K25** | **Catalog entry `knowledge` (kind `ours`) seeded by a migration** in `maludb-os-core`, business area **Operations**, category `documents`, icon `feather-book-open`, criticality `medium` | the catalog is the kernel's (db/169 came the same way) | `application_catalog_save` by hand |
| **M1** | **`POST /v1/memory/answer`** in the MaluDB API: `{namespace, project?, question, facts?, history[], system, max_evidence, max_tokens}` → retrieve (graph seeds + walk, recall over the namespace, the project's subjects first, semantic entity search when the embed model is real) and compose with the namespace's chat model → `{answer_md, confidence, citations[{document_id, chunk_id, quote, statement_ids}], evidence[], usage}`; refuses to compose below a threshold (`no_evidence`); **quotes only from the retrieved documents** (the system prompt enforces it; the route verifies each quote is a substring of its cited document) | composing an answer needs a model; the application holds no key; the engine knows its own retrieval best | evidence-only answers and the expert's turn |
| **M2** | **Usage on every model-calling response** (`/v1/memory/documents`, `/ingest`, `/reindex/run`, `/embeddings/run`, M1) | the engine makes the call | characters counted here |
| M3 | `recall` and `graph/query` accept a **`project`** filter; `recall` exposes `maludb_semantic_search` beside the compartment rail | the facades are the engine's | the application intersects the project's subjects with the seeds itself |
| M4 | **Typed attributes on ingested documents and edges** through `/v1/memory/documents` (`attributes[]` with a template), so a threshold ("12,000 square feet") is a number the graph can compare — Extended's applicability | the attribute facades exist; the ingest route does not take them | thresholds stay text in the statement |
| K16 | **K8 (wake an agent from an application)** — already owed to four siblings | messaging is an agent's tool | the chat-turn dispatch |
| HD2 | **Help Desk shares `articles_published`**; **SP** Spaces' `pages_index` + `page_markdown` are in its plan | the siblings' data | a curator uploads |
| HD3 / SP2 / P3 | Help Desk's, Spaces' and Projects' experts gain `reads[]` on `knowledge.ask` / `search` / `bases_index` | the consumers declare wanting | nothing reads us |

K25 is small and first; K24 is a day in the kernel and the one item on the critical path for the owner's accounting rule;
M1 turns provisions into answers and is the owner's to schedule in MaluDB's API.

## 13. Decisions the owner is asked for (recommendation first)

Three were answered on 2026-10-05 before this revision and are recorded in §15 as A1–A3: a **general product with the
regulatory template built first**; the proof corpus **the fire codes of Chicago and Cook County**; **referenced standards
in scope** (the owner holds the licences). The sixteen below stand, four more join them.

1. **Name and key** — "Knowledge", catalog key `knowledge`, `knowledge.<domain>`, record database `<tenant>_knowledge`,
   memory database `<tenant>_knowledge_memory`, roles `knowledge_rw` / `_records_ro` / `_activity_ro`, SQL prefix `kn_`.
   Confirm, or choose another name ("Corpus", "Atlas", "Codex", "Ask" and "Library" were considered).
2. **Roles and names** — Reader (`reader`, read), Member (`user`), Curator, Knowledge admin; a Member may create a base of
   their own; **the admin sees private bases, logged**. Confirm.
3. **Where the graph lives** — **a memory database of its own**, one namespace per base, created by `ROOT_STEPS.sh`: a corpus
   never shares a database with the agents' memories, a base is forgotten or rebuilt alone, the model config is this
   application's. Alternative: `kb:<id>` namespaces inside the tenant's one memory database.
4. **How a model is used, and metered** — extraction, embedding **and answering** by MaluDB with its configured provider;
   answers through **M1**; metering through **K24**. Until M1: evidence-only (the quoted provisions — for a code, most of the
   value) and the expert; until K24: MaluDB's own key and `ai_usage`. Alternative: every answer through the expert's chat turn
   (30–60 s, one at a time — unfit for the API). Confirm, and that M1 is scheduled in MaluDB's API.
5. **The unit and its reach** — a base is private or internal, shared to members, departments, everyone, agents and
   applications; guests may be Readers; **regulatory bases default to private** (licensed text); no site scope. Confirm.
6. **Sources in v1** — **files first** (PDF with a text layer, DOCX, PPTX, XLSX, CSV, JSON, HTML and Word exports, Markdown,
   text), pasted text, one page per URL (public hosts), a pinned answer as a note, a sibling feed; **50 MB per file**; scanned
   PDFs flagged `no_text` (OCR Extended). Confirm.
7. **Guidelines — five kinds, two moments, shipped by template** — extraction, vocabulary (with terms) and exclusion shape the
   graph and require a rebuild; answering and audience apply at once; every form offers **Preview on a provision**. Confirm.
8. **Focus areas are MaluDB projects**; in a code they are **proposed from the chapters** (deterministic), in a plain corpus
   from the communities after the first build; a question may be scoped to one; a key may be bound to one. Confirm.
9. **Builds and limits** — `auto_extract` on by default; a changed graph-shaping guideline or a changed amendment decision
   marks provisions stale and a person starts the build (estimate first); **a monthly limit per base in MB of text** (default
   50); one build per base at a time; a removed source is forgotten in the engine. Confirm.
10. **Asking** — every answer quotes or declines; weak evidence is a logged gap; conversations keep three turns; the evidence
    panel is open to every Reader; thumbs; a curator pins an answer (review every 6 months) and the pin becomes a `note`
    source. Confirm.
11. **The question log** — every question logged with its asker (person, key label or sibling), as-of date, facts, answer,
    evidence, citations, confidence, latency and usage; curators see their base's; retention 365 days; a purge pauses for
    agents. Confirm (alternative: askers anonymous to curators).
12. **The API and the siblings** — keys per base with limits (30/min, 2,000/day), rotation with a 24 h overlap; `POST
    /api/v1/ask` (with `as_of` and `facts`), `/search`, `GET /bases`, **`GET /provisions/{number}`**, `/questions/{id}`,
    feedback; K7 shares `ask`, `search`, `bases_index` only for bases shared with the consumer; `reads[]` on Spaces and Help
    Desk; the widget Extended. Confirm.
13. **Agents** — the **expert** and the **Archivist** (Mondays 06:45) hired on install into Reader; what pauses (§5). Confirm.
14. **Privacy and retention** — source, provision and question text never in a log payload; sensitivity passed to the
    engine; the MaluDB token read in one file; licensed text never leaves through a widget or a K7 share beyond quotes. Confirm.
15. **Kernel, MaluDB and sibling items** — K25 first, **K24**, **M1**, M2, M3, M4 (Extended), K16/K8, HD2 and the consumers'
    `reads[]`; this application **on request, not a default**; the repository **public** once secret-free (no licensed text is
    ever committed — fixtures are short excerpts the owner approves, or synthetic). Approve, or keep it private.
16. **Ports and the handoff** — `APP_INTERNAL_PORT=8187`, `MCP_RECORDS_PORT=8835`, `MCP_ACTIVITY_PORT=8836`; scratch 8501–8507;
    the **Spaces division**: a planning-class model builds K25 and K24, Phase 0's second half (the schema, the parser fixtures,
    the engine proof), Phase 1 for your approval, Phase 2, slice 1 and **the two exemplars — slice 2 (sources and the code
    parsers) and slice 5 (ask)** — with slices 3 and 4 in between because 5 needs them; a worker model (Sonnet 5.5) builds
    slices 6–9, Phase 4 and Phase 5 from the specs. Confirm, or hand the worker everything after slice 5.
17. **A base is one jurisdiction's adopted code** — the model edition plus the amendment layers plus the jurisdiction's own
    chapters, each source with a role, a layer (precedence) and effective dates; a new edition is a **new base** that
    supersedes the old (old answers stay reproducible); `jurisdiction_bases` says which base answers for a place, a code kind
    and a date; a question is asked **as of** a date (default today). Confirm (alternative: one base per jurisdiction with
    editions inside it — fewer bases, a harder "as of" in the engine).
18. **Amendments are proposed by the parser and confirmed by a curator** — the parser detects amend/delete/add/reserve
    against numbered targets with a confidence; nothing changes the effective text until a curator confirms (or "confirm all
    above 0.9"); **the graph learns effective provisions only**; the original stays readable, never quoted as current;
    referenced standards are **bases of their own**, shared so cross-references resolve one hop. Confirm.
19. **Quote-only answers for regulatory bases** — the shipped answering guideline: verbatim provision text with number,
    edition and jurisdiction; every exception; amendments flagged; thresholds exact; missing facts asked for (`needs_facts`);
    the AHJ line; M1 verifies every quote is a substring of its cited document. **Computed applicability is Extended** (M4).
    Confirm.
20. **The proof corpus and who supplies it** — IFC 2018 and IFC 2021 as PDF, Title 14F and Title 15 and Cook County Chapter
    102 as exports (PDF or Word) plus the County's adopting ordinance, NFPA 13 and 72 as PDF — **supplied by the owner from
    the licences held**, never fetched by the application, never committed; four bases as §10's Phase 5 says. Confirm, and
    name any further standard to include (NFPA 1, 20, 25, 101, 110 are the likely next).

## 14. Ports, names and files

- Repository `/srv/apps/knowledge` (origin `github.com/maludb/maludb-os-knowledge`); record database `<tenant>_knowledge`
  (`subello_knowledge`); roles `knowledge_rw`, `knowledge_records_ro`, `knowledge_activity_ro`; **memory database
  `<tenant>_knowledge_memory`** (`subello_knowledge_memory`; schema and role `knowledge_mem`; `maludb_core` +
  `enable_memory_schema`; its API token minted by `ROOT_STEPS.sh` into `config/.env` as `MALUDB_KB_API_TOKEN`); the tenant's
  memory (`certstudy_memory`) keeps the activity episodes tagged `knowledge`.
- `knowledge.<domain>` (`vhost.label = "knowledge"`); `APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT` **all
  three in `env.required`** and written to `config/.env` before `apply`; `deploy/` files are templates.
- Units: `knowledge-records-mcp`, `knowledge-activity-mcp`, `knowledge-activity-ingest` (+ timer), `knowledge-directory-sync`
  (+ timer), `knowledge-worker` (+ timer, every minute: text extraction and parsing, builds, reindex and embeddings, feeds,
  URL checks, the outbox, rate-counter pruning, retention, dispatch retries).
- `maludb-os.json`: `catalog_key knowledge`, `name "Knowledge"`, `business_area "Operations"`, `category documents`, `icon
  feather-book-open`, `criticality medium`, `vhost.label knowledge`, no `scopes`, `sso`, `directory {reads: true, writes:
  false}`, `assistant {command_bar: true, agent: expert}`, `agents [expert, archivist]` each `hired_on_install: true`,
  `approvals` (§5), endpoints, services, `shares` (three), `reads` (three).
- Env: the contract's required keys plus `MALUDB_KB_API_URL`, `MALUDB_KB_API_TOKEN`, `MALUMAIL_API_KEY`, `MAIL_FROM`,
  `MAIL_FROM_NAME` (optional), `ATTACHMENT_MAX_BYTES`, `KN_URL_FETCH`, `KN_PDFTOTEXT`.
- Vhost allow-list (public): `/api/v1/`, `/mcp/records`, `/mcp/activity`.
- Kernel files: `mcp/registries/knowledge.json` (made by the installer), the K25 catalog seed migration, K24 in
  `mcp/agent_runner/ledger_proxy.py` + its migration + the Overview's mint.
- Proof scratch ports: 8501–8507. Parser fixtures: `tests/parsers/<family>/` — short excerpts only, never a whole licensed
  code; the real corpus lives outside the repository (`/srv/apps/knowledge/storage/`, gitignored).

## 15. The owner's answers

| # | Decision (2026-10-05) | Where it lives |
|---|---|---|
| A1 | **A general knowledge-base product, with the regulatory template built first** — the shell stays general (NotebookLM-shaped for a plain corpus); the regulatory template, its parsers, amendments and quote-only answering are built and proven first | §1; `base_templates`; slice 2 |
| A2 | **The proof corpus is the fire codes of the City of Chicago (IFC 2018 as Title 14F, with Title 15) and of Cook County (IFC 2021 with Chapter 102)** | §0.0; §10 Phase 5 |
| A3 | **Referenced standards (NFPA) are in scope** — the owner holds the licences; each standard is a base of its own, shared so the codes' references resolve | §0.0; §13.18, §13.20 |

Every recommendation of §13 was taken on 2026-10-05, with three tightenings (D15, D18, D20).

| # | Decision | Where it lives |
|---|---|---|
| D1 | **"Knowledge", key `knowledge`**, `knowledge.<domain>`, databases `<tenant>_knowledge` and `<tenant>_knowledge_memory`, roles `knowledge_rw` / `_records_ro` / `_activity_ro`, prefix `kn_` | `maludb-os.json`; §14 |
| D2 | **Four roles**: Reader, Member (`user`), Curator, Knowledge admin; the admin sees private bases, logged `base.admin_view` | §3; `kn_roles`; `app_roles` |
| D3 | **A memory database of its own**, one namespace `kb:<id>` per base, made by `ROOT_STEPS.sh`. **Owed before Phase 5: settle the pgaudit setting** (it logs every MaluDB query and the disk has filled once) | §6; §14 |
| D4 | **MaluDB does extraction, embedding and answering**; answers through **M1**, metering through **K24**; M1 is scheduled **before slice 5**, K24 may follow it; until M1 the window shows the quoted provisions and the expert | §6.3; §12 |
| D5 | **Private or internal bases**, shared to members, departments, everyone, agents and applications; guests may be Readers; **regulatory bases default to private**; no site scope | §3; `base_members` |
| D6 | **Sources**: files first (PDF with a text layer, Word and Office, HTML, Markdown, text, CSV, JSON), pasted text, one page per URL, notes, feeds; **50 MB per file, a setting**; no OCR in v1 | §6.1; `kn_settings` |
| D7 | **Five guideline kinds, two moments, shipped by template**; every form previews on a provision | §6.2; slice 3 |
| D8 | **Focus areas are MaluDB projects**; chapters propose them for a code, communities for a plain corpus | §6.2; slice 3 |
| D9 | **Auto-extract new sources**; a person starts rebuilds after an estimate; **50 MB of text per base per month**; one build per base at a time | §6.2; `builds` |
| D10 | **Every answer quotes or declines**; gaps logged; three turns of conversation; pins become `note` sources | §6.3; slice 5 |
| D11 | **Question log** with asker, as-of date and facts; curators see their base's; 365 days | §6.3; `questions` |
| D12 | **API and siblings**: keys per base at 30/min and 2,000/day with 24 h rotation overlap; `ask`, `search`, `bases`, `provisions/{number}`; K7 shares `ask`, `search`, `bases_index` | §8; slice 7 |
| D13 | **Expert and Archivist** hired on install; the pause list of §5 | §5; `agents[]` |
| D14 | **No source, provision or question text in any log**; sensitivity passed to the engine; the MaluDB token read in one file | §6.4; `engine.php` |
| D15 | **On request, not a default; K25, K24, M1–M4, K16, HD2 approved. Tightened: the repository stays private until the owner has reviewed the parser fixtures, then goes public** | §12; the org |
| D16 | **Ports 8187 / 8835 / 8836**, scratch 8501–8507; the Spaces division: the planning model builds K25, K24, Phase 0 second half, Phase 1, Phase 2, slice 1 and the exemplars (slice 2 the parsers, slice 5 ask, with 3 and 4 between); Sonnet 5.5 builds slices 6–9 and Phases 4–5 | §14; `CLAUDE.md` |
| D17 | **A base is one jurisdiction's adopted edition**, layered, asked as of a date; a new edition is a new base that supersedes the old | §6.3; `jurisdiction_bases` |
| D18 | **Amendments are parser-proposed and curator-confirmed**; the graph learns effective provisions only; standards are bases of their own. **Tightened: bulk confirm never covers a delete or an amend that changes a number** | §6.1; slice 2 |
| D19 | **Quote-only answers** for regulatory bases; M1 verifies every quote is a substring of its cited document; computed applicability is Extended | §6.2; §12 M1 |
| D20 | **The owner supplies the corpus** from the licences held; never fetched, never committed; Phase 0 uses short excerpts and synthetic fixtures. **Tightened: before Phase 5 the owner confirms the ICC and NFPA licences allow machine processing and quotation through an API.** Next standards: NFPA 1 and 25; 101 and 110 later | §0.0; §10 Phase 5 |

## 16. State

**2026-10-05 — the plan approved; K25 done (kernel db/170, category `documents` because the catalog admits no "knowledge").** The owner answered all twenty decisions of §13 the same day, every recommendation taken
with three tightenings (§15, D1–D20). **Next: K25 in the kernel (db/170, the catalog seed), then K24 and M1, then Phase 0's
second half** (the schema, the parser fixtures from excerpts and synthetic text, the engine proof against the live MaluDB API).
