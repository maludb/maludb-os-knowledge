# Build spec: the graph explorer (slice 6)

Built by the worker model from this spec, replicating slice 5's files, gates and proof style (`docs/build-specs/ask.md`). What exists at the
end: a Reader can see what a base's graph holds — its size, its most connected subjects, its communities (when MaluDB has any), the
surprising links, a search over it — open a subject (its statements, its neighbours, the sections behind it, a small drawing of its
neighbourhood), find a path between two subjects, and turn any of it into a question with "Ask about this"; a curator also sees the subjects
that look like duplicates and sends them to the vocabulary. Read-only: this slice writes nothing to the record database but its log rows and
nothing to the engine at all.
Schema: `knowledge_bases` (`namespace`, `subjects_count`, `statements_count`, `communities_count`, `documents_count`, `last_built_at`,
`guideline_version`) (db/006); `sections.maludb_document_id` (db/007); `vocabulary_terms` (db/008); `questions.evidence` (db/010); the views
`mcp_bases`, `mcp_sections`, `mcp_sources`, `mcp_vocabulary_terms`, `mcp_focus_areas`, `mcp_questions`, `mcp_activity_log` and the gates
`kn_can_read_base()`, `kn_can_curate_base()` (db/006, db/014). Never modify them. Engine: `app/knowledge/engine.php` — `kn_engine_stats()`,
`kn_engine_graph_query()`, `kn_engine_communities()`, `kn_engine_community_members()`, `kn_engine_neighbors()`, `kn_engine_path()`,
`kn_engine_god_nodes()`, `kn_engine_surprises()`, `kn_engine_find_subject()`, `kn_engine_documents()`, `kn_engine_documents_in()`, `kn_engine_error_message()`
(finished; never edited). **The base is the wall**: subjects, projects and the graph routes are shared by every base in the memory database
(design §0), so every subject, document, path and neighbour this slice shows has first been **confined to the base** — a document only when
it is one of the base's (`document_type = kb:<id>`, `kn_engine_documents_in()`), a subject only when an edge joins it to one of the base's
documents. Communities and surprises are per namespace in the engine (the phase-0 engine proof shows it) and are shown as they come.

## Divergences from the design
1. **Communities do not exist yet.** MaluDB computes none from ingested documents (design §0; M6 owed). `kn_engine_communities()` answers an
   empty list for every base today. The `graph` page says so plainly — "MaluDB has not grouped this base's subjects into communities yet; it will
   when community detection is added. The most connected subjects are shown instead." — and shows the **connected-subjects list**
   (`kn_engine_god_nodes(ns, 20)`, subjects only) in the communities region. `community-view` is built and proven against communities the proof
   writes, and works the day MaluDB computes them.
2. **No engine route lists a namespace's subjects or reads one subject by id.** The explorer therefore addresses a subject **by its canonical
   name** (`kn_engine_find_subject($name)`, an exact match) — every link the explorer draws carries the name the engine returned — and
   `duplicate_subjects` walks the base's own documents instead (as the tool surface's divergence 5 says): up to 200 of them
   (`kn_engine_documents(ns, 200)`), each one's subject neighbours (`kn_engine_neighbors('document', id)`), grouped (below). M3 (a project and namespace filter on the graph routes) and a
   subject listing route upstream would replace both; nothing else changes then.
3. **Tenant-wide statistics are never shown as a base's.** `kn_engine_stats()` answers MaluDB's tenant-wide counts plus the base's own document
   count; the page shows only the base's document count from the engine and the counts cached on `knowledge_bases` after its builds (slice 4).

## Screens (designed at 1280 px, usable at 375 px — the drawing is hidden on a phone and the lists carry everything)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `graph` | `/bases/{id}/graph?q=` | the base's graph: **Size** (documents in the graph from `kn_engine_stats(ns)`, and the cached subjects, statements, communities, last built); **Search** (`q` → `kn_engine_graph_query(q, ns, 2, 50)`: the seed subjects and their confined neighbourhood as a list of subjects with their kind and a count of the base's documents touching them); **Communities** (cards: label, member count → `community-view`) or the empty state with the connected-subjects list; **Most connected** (`kn_engine_god_nodes(ns, 10)`: subjects → `subject-view`, documents → their section); **Surprises** (`kn_engine_surprises(ns, 25)`: two subjects and the link between them, each end → `subject-view`; empty: "No surprising links yet — they appear once MaluDB has communities to compare."); **Duplicates** (curators only, below); each region shows its own engine error and the others still render |
| `subject-view` | `/bases/{id}/subject?name=` | one subject: its canonical name, type and description (the engine's), the aliases the base's vocabulary gives it (`mcp_vocabulary_terms` whose `canonical_name` or `aliases` match — curators; for a Reader only the names the description carries); **Statements** (the neighbour rows that are statements, grouped by their verb when the row carries one, else under "Statements"); **Neighbours** (subjects, confined, at most 40, each → its `subject-view`); **The sections behind it** (document neighbours of the base → `mcp_sections` by `maludb_document_id`: heading path, source, page → slice 2's `section-view`); **the drawing** at ≥ 992 px; **Ask about this** (→ `/ask?base={id}&q=What does this knowledge base say about <name>?`, slice 5's prefill — never sent by itself); **Path to…** (a name field → `path`) |
| `community-view` | `/bases/{id}/community?community=` | one community (verified to be one of `kn_engine_communities(ns)`, else 404 "Community not found."): its label, size, members (`kn_engine_community_members(id, 200)` — the subjects, confined, each → `subject-view`); **Ask about this** (`q` = "What does this knowledge base say about <label>?") |
| `path` | `/bases/{id}/path?from=&to=` | two names (fields with the search's suggestions) → `kn_engine_path(['subject', a], ['subject', b], 6)`; the path as a vertical chain of nodes and the links between them, each subject → `subject-view`, each document → its section; **withheld** when any node on it is a document outside the base or a subject not confined to it: "There is no path between these inside this base." (never naming the outside node) |

### The drawing (`graph_svg()`)
A server-rendered inline SVG, no script library: the subject at the centre of a 640 × 420 viewBox, its confined neighbours (at most 24: the
sections first, then subjects by their own degree) on one ring (two rings past 12), each a circle with a text label cut at 24 characters and a
`<title>` with the full name, the edges as straight lines labelled with the verb at their midpoint when known; subjects `--bs-primary`, documents
(sections) `--bs-secondary`, statements `--bs-info`; every node an `<a>` to its screen; `role="img"` with an `aria-label` naming the subject and
the neighbour count; `d-none d-lg-block`. The layout is deterministic (angles by sorted name), so the proof compares it. The lists beneath carry
everything the drawing shows, at every width.

### Duplicates (curators of the base — `kn_can_curate_base`)
`duplicate_subjects(PDO, int $baseId, string $ns): array` — the subjects met on the base's documents (divergence 2), each name normalised by `graph_normal_name()`
(lower case, unaccented, punctuation and the words "the", "a", "an" dropped, whitespace collapsed, a trailing `s` dropped from a word of more than
four letters); groups of two or more distinct canonical names that normalise alike **or** where one is an alias of another in the vocabulary
while both still exist as subjects; a group whose names the vocabulary already folds into one term (all but one are its aliases and the term is
seeded) is left out. Each group: the names, how many of the base's documents touch each (the confinement count), a suggested canonical name (the
most connected). **Merge** opens slice 3's vocabulary form for the base prefilled with `canonical` = the suggestion and `aliases` = the others
(the vocabulary save is slice 3's action and marks the base for a rebuild — the form says so). The records MCP `duplicate_subjects` tool (Phase
4) reads this same function; the Archivist's merge proposals (slice 8) are built from it.

### Confinement and caching
- `graph_confine_subjects(string $ns, array $subjects): array` — for each subject (id and name), `kn_engine_neighbors('subject', id)`, its
  document neighbours checked with `kn_engine_documents_in($ns, …)`; answers the subjects that touch the base with their document count; at most
  40 subjects are checked per call (the rest are dropped and the region says "and more"). `graph_subject_in_base(string $ns, array $subject):
  ?int` — the count, or null when the subject does not touch the base (then `subject-view` answers 404 "Subject not found in this base." — the
  same sentence for a name the engine does not know).
- `graph_cache(int $baseId, string $key, int $ttl, callable $fn): array` — a file cache under `storage/cache/graph/<base id>/<sha1(key)>.json`
  (directory 0750, files 0640, written atomically by rename); the key always includes the base's `last_built_at` and `guideline_version`, so a
  build invalidates it; TTL 60 s for an engine read and 600 s for `duplicate_subjects` (the tool surface's figures, so a screen and a tool agree);
  **an engine failure is never cached**; a write first removes that base's files older than a day. Curators may
  add `?fresh=1` to bypass it. The cache holds only what is already confined to the base, and is read only after the base's gate, so every
  Reader of a base sees the same, and no one else sees it at all.
- `engine_unavailable` (no URL or token, the API down) and every other engine code: the region shows a `warning` box with
  `kn_engine_error_message($code)` and the cached counts from `knowledge_bases` still show; a page never fails as a whole and never shows a stack
  or MaluDB's own message.

### "What it taught the graph" (for slice 2's screens)
`section_taught(PDO, int $sectionId): array` — the section's `maludb_document_id` → `kn_engine_neighbors('document', id)`: the subjects and
statements this section contributed, confined (a subject touching only this document is still the base's). This slice adds the region partial
`app/views/graph/partials/taught.php` to slice 2's `section-view` and `source-view` (the source's: the union over its live sections, at most 40
sections asked, cached as above). A section with no `maludb_document_id` (not built yet) says "Not in the graph yet."

## Files (exactly these)
- `html/bases/graph.php` (`graph`) · `html/bases/subject.php` (`subject-view`) · `html/bases/community.php` (`community-view`) · `html/bases/path.php` (`path`)
- `app/features/graph/{queries,present,svg,cache}.php` (`svg.php`: `graph_svg()`; `cache.php`: `graph_cache()`)
- `app/knowledge/bridge.php` — slice 5's op table gains the graph ops of the tool surface (`graph_stats`, `communities`, `community_members`,
  `subject`, `subject_neighbors`, `subject_statements`, `path`, `god_nodes`, `surprises`, `graph_query`, `section_graph`, `source_graph`,
  `sections_for_subject`, `duplicate_subjects`), each calling this slice's query function after the bridge's reader (or curator, for
  `duplicate_subjects`) gate, and answering the tool surface's shapes — a document node replaced by its section `{section_id, heading_path,
  source: {id, title}, page}`, a document with no section dropped, no tenant-wide count
- `app/views/graph/{index,subject,community,path,partials/size,partials/search-results,partials/communities,partials/connected,partials/god-nodes,partials/surprises,partials/duplicates,partials/neighbours,partials/statements,partials/sections-behind,partials/engine-error,partials/taught}.php`
- slice 2's `app/views/sources/` section and source views: the `taught` partial included (one line each)
- `tests/fake_kb_engine.php` — slice 5's fake, extended: `GET /v1/graph/neighbors`, `/v1/graph/path`, `/v1/graph/god-nodes`, `/v1/graph/surprises`, `/v1/graph/stats`, `/v1/communities`, `/v1/communities/{id}/members`, `GET /v1/subjects?label=eq.…` (what `kn_engine_find_subject()` calls; `data.subject` null when no exact match), from `$FAKE_KB_STATE.graph`
- `tests/phase3/slice6/{run.sh,lib.php,graph.php,subject.php,confine.php,community.php,path.php,duplicates.php,cache.php,engine_down.php,browser.mjs}`

## Query functions (signatures fixed)
- `graph_overview(PDO, int $baseId): array` (`['size', 'communities' | 'connected', 'god_nodes', 'surprises', 'errors' => [region => code]]`, each region from the cache) · `graph_search(PDO, int $baseId, string $q): array`
- `graph_subject(PDO, int $baseId, string $name): ?array` (`['subject', 'aliases', 'statements', 'neighbours', 'sections', 'svg_nodes', 'errors']`; null = not in the base) · `graph_community(PDO, int $baseId, int $communityId): ?array` · `graph_path(PDO, int $baseId, string $from, string $to): array` (`['path' | null, 'withheld' => bool, 'error']`)
- `graph_confine_subjects(string $ns, array $subjects): array` · `graph_subject_in_base(string $ns, array $subject): ?int` · `graph_normal_name(string $name): string` · `duplicate_subjects(PDO, int $baseId, string $ns): array` · `section_taught(PDO, int $sectionId): array` · `source_taught(PDO, int $sourceId): array`
- `graph_cache(int $baseId, string $key, int $ttl, callable $fn): array` · `graph_svg(array $center, array $neighbours): string`

## Handlers
None write. The four screens: `require_login()`; `require_right('bases.read')`; slice 1's `base_from_request()` (404 "Knowledge base not found."
for a base the caller may not read; it logs `base.admin_view` for the admin's look at a private base); the Duplicates region only when `kn_can_curate_base(base)`; `log_screen_view()` — `graph` (`after.base_id`,
`after.searched` true/false — never the search words), `subject-view` (`after.subject_id` — never the name), `community-view`
(`after.community_id`), `path` (`after.found`, `after.withheld`). JSON: each screen answers its query function's array through `present.php`
(the whitelist: names, kinds, ids, counts, section ids and heading paths — never a section's text).

## Action manifest entries
Screens: `graph`, `subject-view`, `community-view`, `path`. Actions: none (Merge opens slice 3's `vocabulary_save` form; Ask about this opens
slice 5's `ask`). Agent approvals: none. `PARTIAL_UPDATE_TARGETS` gains nothing.

## Activity log events
`screen.view` only (above). No subject name, search phrase or section text is in a payload.

## Notifications this slice queues
None.

## Status vocabulary
Node kinds: subject `feather-circle` primary, document/section `feather-file-text` secondary, statement `feather-message-circle` info. Region
states: an engine error `warning` with the module's sentence; empty states in muted text naming what will appear. Ids: `graph-size`,
`graph-search`, `graph-communities`, `graph-connected`, `graph-god-nodes`, `graph-surprises`, `graph-duplicates`, `dup-group-{n}`, `subject-head`,
`subject-statements`, `subject-neighbours`, `subject-sections`, `subject-svg`, `community-members`, `path-chain`, `taught`.

## Out of scope for this slice
Writing communities (MaluDB's — M6); community reports written by the expert (Extended; the expert answers "summarise this community" in the
command bar with the records tools); merging subjects in the engine (never — the vocabulary does it at the next build); the records MCP graph
tools (`graph_stats`, `communities`, `community_members`, `get_subject`, `subject_neighbors`, `subject_statements`, `graph_path`, `god_nodes`,
`surprises`, `graph_query`, `duplicate_subjects`, `section_graph`, `source_graph`, `sections_for_subject` — Phase 4, over these same functions,
`docs/knowledge-mcp-tool-surface.md`).

## Proof (`tests/phase3/slice6/run.sh`: the scratch database `knowledge_dev6`; ports 8501 the app, 8502 the fake kernel, 8503 the fake engine, 8506 the fake episodes; headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
The world (`lib.php` `graph_world()`): base **Pumps** (id 1, internal) with sections whose documents are 501–504; base **Valves** (id 2, private,
Cara's) with document 601; the fake engine's graph: subjects "Zorblat Pump" (touches 501, 502), "Merrow Filter" (502), "Quillon Valve" (601 only —
Valves'), "Zorblat Pumps" (503 — a duplicate by normalisation), "ZP-9" (504; a vocabulary alias of "Zorblat Pump" on Pumps), a god-node list that
mixes both bases' documents and subjects, a path Zorblat Pump → Merrow Filter through 502, and a path Zorblat Pump → Quillon Valve through 601.
- [ ] **Overview**: Priya opens Pumps' graph → the size shows Pumps' 4 documents from the engine and the cached counts, never the tenant total; the
  communities region shows the plain empty-state sentence and the connected subjects (Zorblat Pump, Merrow Filter, Zorblat Pumps, ZP-9 — never
  Quillon Valve, never document 601); most connected excludes Valves' document and subject; surprises empty with its sentence; search "Zorblat" →
  the confined neighbourhood; `screen.view` logged with `searched: true` and no search words.
- [ ] **Subject**: "Zorblat Pump" → statements, neighbours (Merrow Filter; not Quillon Valve), the sections behind it (501, 502 with heading paths
  and pages, linking to `section-view`), Ask about this prefilled; "Quillon Valve" from Pumps → 404 "Subject not found in this base."; a name the
  engine does not know → the same 404; Cara sees "ZP-9" among the aliases, Priya does not (vocabulary is the curator's).
- [ ] **Confinement**: every subject, document and name in every JSON answer of the four screens for Pumps is checked against Pumps' documents — none
  of Valves'; the 40-subject cap says "and more".
- [ ] **Communities**: the proof writes two communities into the fake for `kb:1` → cards with labels and sizes; `community-view` lists confined
  members; a community of `kb:2` asked from Pumps → 404 "Community not found."
- [ ] **Path**: Zorblat Pump → Merrow Filter → the chain through section 502; Zorblat Pump → Quillon Valve → "There is no path between these inside
  this base." with no node of the path in the response; `screen.view` `found`/`withheld` logged.
- [ ] **Duplicates**: Cara sees the group {Zorblat Pump, Zorblat Pumps} with the suggestion "Zorblat Pump" and Merge prefilled to slice 3's form; ZP-9
  is not a group once the vocabulary term is seeded (`maludb_subject_id` set) and both names fold into it; Priya never sees the region and its JSON
  key is absent.
- [ ] **Cache**: a second open makes no engine call (the fake's log); a build (`last_built_at` moved) invalidates it; `?fresh=1` for Cara bypasses,
  for Priya is ignored; an engine failure is not cached (the next open calls again); a stale file older than a day is removed on the next write.
- [ ] **Engine down**: the fake stopped → each region shows "The knowledge engine is not reachable…" (the module's sentence) and the cached counts
  still render, HTTP 200; no MaluDB message or token in the page; `subject-view` says the engine is unavailable rather than 404.
- [ ] **Taught**: slice 2's `section-view` for section 501 shows the subjects it taught; an unbuilt section says "Not in the graph yet."
- [ ] **The bridge**: each graph op signed for Priya answers the same confined data as the screens (document nodes as sections); `duplicate_subjects`
  for Priya → `curators_only`/403, for Cara → the groups; an op for a base Priya cannot read → `not_found`.
- [ ] **375 × 740 and 1280 × 800**: at 1280 the drawing renders with 24 nodes at most, every node a link, the labels cut with full names in
  `<title>`; at 375 the drawing is hidden and the lists carry the same nodes; every control ≥ 44 px, `scrollWidth` = viewport, no console errors;
  the registry reads the four screens built.

## Built and proven
**Built 2026-10-10 by a Sonnet 5.5 worker from this spec; verified and committed by the planning model the same day (8397cc8); proven by `tests/phase3/slice6/run.sh`** — overview 19,
subject 26, confinement 11, communities 13, path 17, duplicates 15, cache 19, taught 13, the bridge 34, engine down 14, the browser 93 (375 × 740 and 1280 × 800)
— 274 checks under php -S and 274 under a real Apache, against the fake engine (`tests/fake_kb_engine.php`, its `graph` state) and the fake kernel, plus the
registry (4 screens built: 44 of 61 screens, 45 of 62 actions) and no PHP warning. Slices 2 and 5 and Phase 2 stay green. No migration.

Settled in the build:
- **The engine's sentence is the module's**, `kn_engine_error_message()` — "The knowledge engine is not available right now." — not the "is not reachable…" the proof
  paraphrased; `invalid_argument` on a search says "Search with at least one word of two letters or more." The first region to fail with an unreachable engine
  (unavailable, timeout, auth, forbidden) is every remaining region's failure at once, so a hanging engine is waited on once, not five times.
- **Names the base does not hold are one answer.** `subject-view` (404 "Subject not found in this base."), `path` (an end that is not the base's → "There is no path between these
  inside this base.", `withheld` true — the same words as a path through another base's document), the bridge (`not_in_base`, `no_path_in_base`). The path page echoes the
  names a person typed; it never names a node of a path it withholds. Of several engine paths (the first 8) the **first wholly inside the base** wins; some but none inside is
  withheld; none at all is the same sentence with `withheld` false.
- **Statements are confined through the base's documents**, not their own neighbours: a subject's statement rows are kept only when one of the base's documents the subject
  touches (at most 20 asked) lists the same statement, and its section is that document's. This needs document → statement edges in the unified graph, which the tool
  surface's `section_graph` assumes already. A statement or subject node on a PATH is confined by its own neighbours (`graph_confine_nodes($ns, $kind, …)`, the generic form
  behind `graph_confine_subjects()`, which answers `['ok', 'subjects', 'more']` and each subject's `documents`, `degree`, `doc_ids`, `rels`).
- **The vocabulary's duplicates** are separate groups of two kinds — names that read alike, and a seeded-or-not term whose canonical name and an alias both exist as subjects —
  and one subject may stand in both; a group is left out when every name in it is a seeded term's canonical name or alias. The suggested name of a vocabulary group is the term's
  canonical name; of any other the subject most documents touch (ties: the shorter, then alphabetical). **Merge** goes to `/vocabulary/new?base=&canonical_name=&aliases=`; slice 3's
  `html/vocabulary/form.php` (+ its view) now prefills from those, and when a term of that name already exists opens it for change with the aliases united (nothing is written by opening).
- **The cache** (`graph_cache()`): a result marked `nocache` (a name that is not the base's, any failure) is never written; at most 400 files a base (the oldest go first, besides
  the day-old rule); `graph_fresh()` is set by the screen for a curator's `?fresh=1`. The two god-node regions are two engine calls (limit 20 subjects-only, limit 10), as written. The
  path page's name suggestions are the connected subjects (the same cached call).
- **Communities** show the engine's `member_count` as their size; a community's members are confined (subjects: at most 40 checked, "and more"; documents as their sections).
- **The bridge** (`app/knowledge/bridge.php`): a document node is a node of kind **`section`** with `id` = the section id and `section` = `{section_id, heading_path, source, page_no}`
  (`subject_neighbors` accepts `kind=document` for them); a subject may be named by its id (`graph_find_subject_by_id()` asks the engine's list route `id=eq.N` through
  `kn_engine_request()`; engine.php was not touched); neighbours carry no subject type (null); a statement's `object` is null (the neighbour row has only its text); a surprise's
  `score` is the engine's `community_pair_edges`; `graph_stats.top_verbs` count the confined top subjects' neighbour rels; an unreachable engine is 503 `engine_unavailable` (504
  `engine_timeout`, else 502 `engine_error` with the engine's code as `detail`), and `graph_stats` alone answers the cached counts with `engine: "unavailable"`. An op for a base
  one may not read is `not_found` even for `duplicate_subjects` (a reader who can read it gets `curators_only`, 403). The graph ops write nothing, not even a log row.
- **The drawing** puts at most 24 nodes — the base's sections, then subjects by degree, then statements that have a section — on one ring (two past 12: 150 × 85 and 265 × 150 around
  the centre), sorted by name for their angle; the verb labels the edge when known; statements link to their section. A subject's JSON does not carry the drawing.
- **Links keep the way back without nesting it**: `graph_here()` is the address of the page minus its own `back` and `fresh`, so exploring subject after subject never wraps one
  `?back=` inside the next. `back_link()` gained the labels "the graph", "the subject", "the community" and "the path".
- **Slice 2's JSON is unchanged** (`taught` is HTML only); a forgotten section or source shows no such region; "Not in the graph yet." is a section without a document id.
- **Proof additions**: `taught.php` and `bridge.php` beside the spec's list (the Proof section has bullets for both); the fake engine's `GET /v1/documents` with both
  `document_type` and `id=in.(…)` now answers the ids that are in the namespace (what `kn_engine_documents_in()` asks), its `GET /v1/graph/query` walks the neighbours when `graph.subjects` is
  set, and `graph.labels` names non-subject nodes; slice 5's contract is untouched. The cast is the fixture's (Priya, Marco, Bea, Ann, Reid), the scratch database `kn_dev6`.
- `.kn-row-link` (app-overrides.css) is the 44 px row of a link list.
- **One earlier proof changed with the slice it was waiting for**: slice 5's `bridge.php` named `graph_stats` as "an op no slice has built yet" (→ 404 `unknown_op`); it names `preview` (slice 3's, still unbuilt) now.

## Open questions
