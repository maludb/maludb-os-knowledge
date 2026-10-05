# Build spec: guidelines, vocabulary and focus areas (slice 3)

What exists at the end: a curator opens a base's guidelines and finds the template's shipped set already there, grouped by the five
kinds; writes or edits one beside the kind's examples and, before saving, **previews** it on one of the base's own sections — the exact
prompt the model will be handed; saves, and learns at once whether it changed the graph ("version 4 — 212 sections need a rebuild") or
only the answers ("applies to the next question"); sees every earlier version and which one the graph was built under. They keep the
vocabulary — terms, aliases, types, a CSV import, the terms the sources never mention. They name **focus areas** — a description, seed
terms, example questions — each a MaluDB project, accept or dismiss the areas the expert proposes, and assign sources to areas.
Schema: `guidelines`, `guideline_versions`, `vocabulary_terms`, `focus_areas`, `source_focus_areas`, `kn_guidelines_before()`,
`kn_guidelines_after()`, `kn_vocabulary_after()`, `kn_graph_shape_changed()`, `kn_focus_areas_before()`, `kn_source_focus_areas_before()`,
`kn_slugify()` (db/008); `kn_guideline_kinds()`, `kn_graph_shaping_kinds()`, `base_templates` (db/005); `kn_unassigned_sources()`,
`kn_empty_areas()`, `kn_unused_terms()`, `kn_base_overview()` (db/013); `archivist_proposals`, `kn_notify()`, `kn_base_curator_ids()`
(db/012); the views `mcp_guidelines`, `mcp_guideline_versions`, `mcp_vocabulary_terms`, `mcp_focus_areas`, `mcp_source_focus_areas`,
`mcp_sources`, `mcp_sections`, `mcp_bases`, `mcp_activity_log` (db/014). The compile module (`docs/build-specs/guidelines-compile.md`,
`app/knowledge/compile.php` — **built in this slice**, with its proof); the engine: `kn_engine_project_create()`, `kn_engine_project()`,
`kn_engine_project_link_subject()`, `kn_engine_project_unlink_subject()`, `kn_engine_project_delete()`, `kn_engine_project_name()`,
`kn_engine_seed_subjects()`, `kn_engine_find_subject()`, `kn_engine_preview()` (`app/knowledge/engine.php`). Never modify them. **The
database is the referee**: a graph-shaping save (extraction, vocabulary, exclusion, any term) bumps `guideline_version` and marks every
extracted section stale; an answering or audience save does neither; every save writes a version row; a template copy shapes nothing; an
area's slug is unique in its base; a source is assigned only to an area of its own base — the handlers write inside `kn_guard()` and present
a refusal as its sentence; PHP decides nothing twice.

## Divergences from the design
1. **A vocabulary import bumps the version once per term.** `kn_vocabulary_after()` is a row trigger: importing 300 terms raises
   `guideline_version` by 300. Harmless (the version is only compared, never counted), but "built under v4 → v304" reads oddly; the import's
   `did` says "300 terms — the base is now at version 304". A statement-level trigger (an additive migration) would fix it; not needed for v1.
2. **Subject types are `[a-z_]`.** db/008 allows digits (`^[a-z][a-z0-9_]{0,39}$`); `kn_engine_seed_subjects()` refuses them (`^[a-z_]{1,60}$`).
   The term form and the import accept `^[a-z][a-z_]{0,39}$` only, so every saved type can be seeded.
3. **Merging duplicate subjects** (design §9 "vocabulary … merges") needs `duplicate_subjects` over the graph — slice 6's. This slice's
   vocabulary screen has no merge.
4. **"Propose areas"** (design §9) wakes the expert through a dispatch — slice 8's. This slice builds `focus_area_propose` (how the expert
   files a proposal), the proposed cards, Accept and Dismiss; the button appears when slice 8's handler file exists.
5. **A renamed area is a new engine project.** Projects are named `kb:<id>/<area name>` (tenant-wide names, `kn_engine_project_name()`); a
   rename deletes the old project (links first) and creates the new one with the seed terms linked again. Sections built before keep the old
   project tag until rebuilt (the area page says how many).
6. **Events beyond design §6.3**: `vocabulary.delete`, `focus_area.assign`; an import logs `vocabulary.save` with `after.import`.

## Screens (cards for areas; tables for guidelines' versions and the vocabulary; full-page forms; no modals)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `guidelines` | `/bases/{id}/guidelines` | curators: the five kinds in order, each a card group headed by the kind and its one line (below) — its guidelines (title, the body's first two lines, origin chip — template · edited · person · agent —, version, an inactive one greyed), **Add a {kind} guideline**; the head of the page: "The graph is built under version N" (the base's `guideline_version` and the sections by `extracted_version`/`status` from `mcp_sections`: "198 sections at v4 · 14 stale"), a **needs a rebuild** box linking to Builds when any is stale, the compiled prompt's warnings (`truncated`, `conflicts`), **See what the engine is told** (→ `guidelines-compiled`) |
| `guideline-add` / `guideline-edit` | `/guidelines/new?base={id}&kind=`, `/guidelines/{id}/edit` | kind (a select; changing it on an edit is allowed), title, body (a large textarea), active, position; **beside it** (below it at 375) the kind's line and its three examples (`GUIDELINE_EXAMPLES`); **Preview on a section**: a select of the base's sections (live ones, the first 200 by source and position; `?section=` preselects) and **Preview** (HTMX POST of the draft to `/guidelines/preview.php` → `#preview`): for extraction, vocabulary and exclusion — the compiled prompt with the section's text in place of `{{chunk}}`, the line counts, "this draft changes what the engine is told" or "no change", truncations and alias conflicts, the note "This shows what the model will be told, not what it will extract — a dry run of the model comes with MaluDB M5", and in a `<details>` the engine's own generic preview prompt when the engine answers; for answering and audience — the compiled answer guidelines (`kn_compile_answer_system()` with the draft); a base with no section yet previews with "(no section yet — add documents)" in the chunk's place. Save says, for a graph-shaping kind, "Saving marks N sections for a rebuild" |
| `guideline-view` | `/guidelines/{id}` | the guideline's versions (`mcp_guideline_versions`, newest first): version, saved by, when, active, "the base was at vN after this save", the body in a `<details>`; the version the graph was built under is marked ("the latest save at or below the version the sections were built under"); Edit · Delete |
| `guidelines-compiled` | `/bases/{id}/compiled` | read-only: the namespace's compiled `prompt_template` (with `{{chunk}}`), its SHA-256 and size, the line counts, truncations, conflicts; the compiled answer guidelines; the terms as the engine gets them; for the admin also whether the engine holds this prompt (`kn_compile_engine_sha()` = the hash: "the engine has it" / "the engine will get it before the next build") |
| `vocabulary` | `/bases/{id}/vocabulary?q=&never=` | curators: a table — canonical name, aliases, type, description's first line, "seeded" (a subject id is known), **never met** `warning` (from `kn_unused_terms()`), Edit; the alias conflicts in a `warning` box ("the pump" is claimed by Pump Housing and Zorblat Pump — only Pump Housing keeps it); **New term**, **Import CSV**; a filter for never-met terms |
| `term-add` / `term-edit` | `/vocabulary/new?base={id}`, `/vocabulary/{id}/edit` | canonical name, aliases (one per line or comma-separated; at most 20, each ≤ 200), type (a select of the template's `subject_types` and the base's own, or a new one `[a-z_]`), description (≤ 2,000); Delete on the edit |
| `vocabulary-import` | `/bases/{id}/vocabulary-import` | a CSV file or pasted CSV; **Check** shows the parsed rows — new, updated, refused with the line's reason — writing nothing; **Import** (the same CSV carried in a hidden field) writes them in one transaction |
| `areas` | `/bases/{id}/areas` | everyone who reads the base: the active areas as cards (name, description's first line, seed-term chips, example questions count, sources count, **linked** `success` / **not linked in the engine** `warning` for curators); curators also see **Proposed** cards above (proposer with the agent chip, the proposal note, the seed terms, **Accept** · **Dismiss**), "Sources in no area (12)" (`kn_unassigned_sources()`) each with an area select (`focus_area_assign`), "Areas with no source" (`kn_empty_areas()`), archived areas behind a toggle, **New area**; empty: "Focus areas narrow a question to part of the base. None yet." |
| `area-add` / `area-edit` | `/areas/new?base={id}`, `/areas/{id}/edit` | name (≤ 120), description (it becomes the project's classifier — "say what belongs here and what does not"), seed terms (one per line, ≤ 30, each ≤ 200 — a datalist of the base's vocabulary), example questions (one per line, ≤ 10, each ≤ 300), position, status active · archived (edit only) |
| `area-view` | `/areas/{id}` | the description, the seed terms, the example questions (each "Ask this" → `/ask?base=&area=&q=`, slice 5), the sources in the area as small cards with **Remove** (curators), **Add a source** (a select of the base's live sources not in it), "N sections of these sources were built before they joined — rebuild to tag them" (sections with `extracted_at` before the assignment's `created_at`), the engine link (curators: "project #N", or "not linked — **Link now**"), the timeline |

**The kinds' lines and examples** (`GUIDELINE_KINDS`, `GUIDELINE_EXAMPLES` in `app/features/guidelines/present.php`; synthetic words):
| Kind | Line | Examples |
|---|---|---|
| extraction | "What the graph should hold. Changes the graph — the base needs a rebuild." | "Extract every product, model number, part, procedure step and service interval. A model number is a product; an interval is a statement about the part it applies to." · "Extract every policy, who it applies to, who owns it, what it requires and its effective date." · "Extract the parties, every obligation with its owner and deadline, the payment terms and the renewal and termination conditions." |
| vocabulary | "What the words mean. Changes the graph." | "Treat PTO, paid time off and annual leave as one term." · "Model codes are written with a hyphen (ZP-9); use the full product name as the subject." · "A department name is a subject of type organisation." |
| exclusion | "What never to extract — or say. Changes the graph and the answers." | "Ignore page headers and footers, page numbers and the table of contents." · "Never extract personal data about employees: home addresses, phone numbers, salaries." · "Nothing about pricing or discounts." |
| answering | "How to answer. Applies to the next question — no rebuild." | "Two paragraphs at most, in plain English; say when the sources disagree." · "Start with yes or no when the question allows it, then the reason with its quote." · "Name the document and the section you quote." |
| audience | "Who is asking. Applies to the next question — no rebuild." | "Field technicians reading on a phone." · "New employees in their first month." · "Auditors who need the exact wording." |

## Guidelines
- **Save** (`guideline_save`, `/guidelines/save.php`, with or without `guideline`): `require_base_right(base, 'base.manage')`; kind one of the
  five; body 1–20,000; title ≤ 200; `origin` = `agent` under a run token, else `person` on a new row (a shipped row keeps `template` — the
  screen calls a shipped row with `version_no > 1` "edited"). INSERT or UPDATE the base table; **the database** bumps `version_no`, writes the
  `guideline_versions` row, and — for a graph-shaping kind (before or after the change) — `kn_graph_shape_changed()`. A save that changes only
  `position` is not a version (the trigger's rule). Then, for a graph-shaping change, `kn_compile_send()` **best-effort**. `did`: "Saved —
  version 4: 212 sections need a rebuild; the engine has the new guidelines" / "… the engine will get them before the next build" / "Saved —
  applies to the next question". Location `/bases/{id}/guidelines#guideline-{id}`.
- **Delete** (`guideline_delete`, **deletion**): `base.manage`; a graph-shaping delete bumps the version (the trigger); then
  `kn_compile_send()` best-effort. A shipped guideline may be deleted (the base keeps working with what remains).
- **Preview** (`/guidelines/preview.php`): POST + CSRF (the draft is long), `base.manage`; **not an action** and writes nothing, logs nothing
  (the records tool `extraction_preview` reads through the same `kn_compile_prompt()` in Phase 4); the section must be of the base (else 404).

## Vocabulary
- **Save** (`vocabulary_save`): `base.manage`; canonical 1–200 (unique per base, case-insensitive — `23505` → the field: "The base already
  has the term Zorblat Pump"); aliases ≤ 20, each ≤ 200, de-duplicated, never the canonical name; type `^[a-z][a-z_]{0,39}$` or empty;
  description ≤ 2,000. The trigger bumps the version; `kn_compile_send()` best-effort (the ALIAS lines). The engine's subjects are seeded by
  the build (slice 4), not here.
- **Delete** (`vocabulary_delete`, **deletion**): `base.manage`; the trigger bumps; compile sent best-effort. The engine subject stays (subjects
  are shared by every base of the memory database).
- **Import** (`vocabulary_import`, `/vocabulary/import.php`): `base.manage`; a header row naming `canonical_name` (or `term`, `name`), `aliases`
  (split on `;` or `|`), `subject_type` (or `type`), `description`, case-insensitive, in any order; at most 500 rows and 1 MB; the delimiter by
  the first line (`,` `;` or tab; RFC quoting); `step=check` answers the rows (new / update / refused with its line number and reason) and
  writes nothing; `step=apply` upserts by `lower(canonical_name)` in ONE transaction (a non-empty cell replaces, an empty one keeps), then
  `kn_compile_send()` once. A refused row refuses nothing else; `did` "41 added, 7 updated, 2 refused".

## Focus areas (each a MaluDB project — `app/knowledge/areas.php`, used by the handlers and by slice 4's worker)
- `kn_area_engine_sync(PDO $pdo, int $areaId): array` → `['ok', 'code', 'project_id', 'linked', 'unlinked', 'recreated' => bool]`: an `active`
  area only; no `maludb_project_id` → `kn_engine_project_create($namespace, $name, <first 4,000 of the description>, <the description as
  classifier_md>)` and write the id; an id whose project's name (`kn_engine_project()`) is not `kn_engine_project_name($namespace, $name)` →
  `kn_engine_project_delete()` (it unlinks first) and create again (divergence 5); otherwise `kn_engine_project_create()` again with the same
  name (it finds the existing project and updates its description and classifier); then the seed terms:
  each term's subject (`kn_engine_find_subject()`; missing → `kn_engine_seed_subjects([{canonical_name, subject_type: the vocabulary term's
  type when the seed term is a term of the base, else other}])` — no model call), `kn_engine_project_link_subject()` for each missing link,
  `kn_engine_project_unlink_subject()` for a linked subject no longer a seed term.
- `kn_area_engine_remove(PDO $pdo, int $areaId): array`: `kn_engine_project_delete()` when an id is set (links first — the engine defect of §12).
- **Save** (`focus_area_save`): `base.manage`; name 1–120 (the slug from the trigger; `23505` on the slug → "The base already has an area
  called Warranty"); description, seed terms, example questions, position, status `active` | `archived` (never `proposed` from here). Then
  `kn_area_engine_sync()` **best-effort** for an active area; `did` "Saved — linked in the engine" / "Saved — the engine will link it before the
  next build". Location `/areas/{id}`.
- **Propose** (`focus_area_propose`): `base.manage` (the expert holds it on a base it curates); INSERT with `status = 'proposed'`, `proposal_note`
  required (≤ 1,000: "a cluster of 14 subjects about returns"); no engine call; each curator told (`kn_notify(…, 'proposal', 'Proposed area:
  Returns', <note>, 'focus_area', id, 'area:{id}:{member}')`). Location `/bases/{id}/areas`.
- **Accept** (`focus_area_accept`) / **Dismiss** (`focus_area_dismiss`): `base.manage`; a `proposed` area only (else 422 "That area is not a
  proposal"); accept → `active` (the trigger stamps `decided_by`/`decided_at`), `kn_area_engine_sync()` best-effort, an open
  `archivist_proposals` row of kind `area` naming this area → `accepted`; dismiss → `archived`, such a row → `dismissed`.
- **Link** (`focus_area_link`): `base.manage`; `kn_area_engine_sync()` now, refusing in the engine's words when it fails.
- **Delete** (`focus_area_delete`, **deletion**): `base.manage`; `kn_area_engine_remove()` first — **its failure refuses the delete** ("The
  engine could not remove the area's project — try again"), so no project outlives its area; then DELETE (assignments cascade; keys, feeds
  and questions keep their rows with the area cleared). Location `/bases/{id}/areas`.
- **Assign** — `source_areas_set` (`/sources/areas.php`: a source and the full set of its areas, from `source-view`) and `focus_area_assign`
  (`/areas/assign.php`: an area, a source, `assigned` yes/no, from `area-view` and the unassigned list): `base.manage`; only active areas of the
  source's base (the trigger refuses another base's). Assignment shapes nothing now (no version bump); the next extraction of the source's
  sections sends the area's project (`kn_compile_projects()`).
- **The purge** (slice 2's `kn_worker_purge_bases()`): before deleting a base, `kn_area_engine_remove()` for each of its areas with a project;
  a failure leaves the base for the next pass. Added here.

## Files (exactly these)
- `html/bases/guidelines.php` (`guidelines`) · `compiled.php` (`guidelines-compiled`) · `vocabulary.php` (`vocabulary`) · `vocabulary-import.php`
  (`vocabulary-import`) · `areas.php` (`areas`)
- `html/guidelines/form.php` (`guideline-add`, `guideline-edit`) · `view.php` (`guideline-view`) · `save.php` · `delete.php` · `preview.php`
- `html/vocabulary/form.php` (`term-add`, `term-edit`) · `save.php` · `delete.php` · `import.php`
- `html/areas/form.php` (`area-add`, `area-edit`) · `view.php` (`area-view`) · `save.php` · `propose.php` · `accept.php` · `dismiss.php` ·
  `link.php` · `delete.php` · `assign.php` · `html/sources/areas.php`
- `app/knowledge/compile.php` (the compile spec) · `app/knowledge/areas.php` · `app/knowledge/worker/sources.php` (the purge's areas step)
- `app/features/guidelines/{queries,present,write,handler}.php` · `app/features/vocabulary/{queries,write}.php` ·
  `app/features/areas/{queries,present,write,handler}.php` (each `handler.php`: load the record and its base through `base_from_request()`,
  the gate, a `*_log()` with `base_id`)
- `app/views/guidelines/{index,form,view,compiled,partials/kind-group,partials/guideline-card,partials/preview,partials/examples}.php`,
  `app/views/vocabulary/{index,form,import,partials/term-row,partials/import-check}.php`,
  `app/views/areas/{index,form,view,partials/area-card,partials/proposal-card,partials/unassigned}.php`, the source-view's
  `app/views/sources/partials/areas-form.php`
- `tests/phase3/slice3/{run.sh,lib.php,compile.php,golden/*,guidelines.php,preview.php,vocabulary.php,areas.php,engine.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `find_guidelines(PDO, int $baseId): array` (`mcp_guidelines` grouped by kind in `kn_guideline_kinds()` order, then position, id) ·
  `find_guideline(PDO, int $id): ?array` · `guideline_versions(PDO, int $guidelineId): array` (`mcp_guideline_versions`) ·
  `built_under(PDO, int $baseId): array` (`mcp_sections` counts by `extracted_version` and status; the base's version)
- `preview_sections(PDO, int $baseId, int $limit = 200): array` (`mcp_sections` live: id, heading_path, source_title) · `find_section(PDO, int $id): ?array` (slice 2's)
- `find_terms(PDO, int $baseId, array $filters): array` (`mcp_vocabulary_terms`, with `never_met` from `kn_unused_terms()`) · `find_term(PDO, int $id): ?array`
  · `base_subject_types(PDO, int $baseId): array` (the template's then the terms', the compile order) · `parse_vocabulary_csv(string $csv): array`
  (`['rows' => [...], 'errors' => [line => reason]]`, pure)
- `find_areas(PDO, int $baseId, array $filters): array` (`mcp_focus_areas`; `status`) · `find_area(PDO, int $id): ?array` · `area_sources(PDO, int $areaId): array`
  (`mcp_source_focus_areas` + `mcp_sources`) · `area_sections_built_before(PDO, int $areaId): int` · `unassigned_sources(PDO, int $baseId): array`
  (`kn_unassigned_sources()`) · `empty_areas(PDO, int $baseId): array` (`kn_empty_areas()`) · `area_timeline(PDO, int $areaId, int $limit = 30): array`

## Writes (base tables, after the gate)
`save_guideline(PDO, ?int $id, int $baseId, array $fields, string $origin, int $by): array` (`['guideline_id', 'version_no', 'shapes_graph',
'base_version_before', 'base_version_after', 'stale_marked']`) · `delete_guideline(PDO, int $id, int $by): array` · `save_term(PDO, ?int $id,
int $baseId, array $fields, int $by): array` · `delete_term(PDO, int $id, int $by): array` · `import_terms(PDO, int $baseId, array $rows, int $by):
array` (`['added', 'updated']`) · `save_area(PDO, ?int $id, int $baseId, array $fields, int $by): int` · `propose_area(PDO, int $baseId, array
$fields, int $by): int` · `decide_area(PDO, int $id, bool $accept, int $by): array` · `delete_area(PDO, int $id, int $by): array` ·
`set_source_areas(PDO, int $sourceId, array $areaIds, int $by): array` (`['added', 'removed']`) · `assign_area(PDO, int $areaId, int $sourceId,
bool $assigned, int $by): bool`

## Handlers (every one: `kn_handler_begin()`; `require_base_right($base, 'base.manage')`; `kn_guard()`; the log; `emit_action_status()` + `kn_done()`; `HX-Trigger: guidelinesChanged` / `vocabularyChanged` / `areasChanged`)
- `guidelines/save.php` (`guideline_save`): `guideline.save` (`after`: guideline_id, kind, title, version_no, active, origin, shapes_graph,
  body_chars, engine_synced — never the body); when the base version moved, also `guideline.version` (`before.version`, `after.version`) and
  one `section.stale` row (entity base, `after`: count, version).
- `guidelines/delete.php` (`guideline_delete`, **deletion**): `guideline.delete` (guideline_id, kind, title, version_no) and the version rows as above.
- `vocabulary/save.php` (`vocabulary_save`): `vocabulary.save` (term_id, canonical_name, aliases count, subject_type) + `guideline.version`.
  `vocabulary/delete.php` (`vocabulary_delete`, **deletion**): `vocabulary.delete`. `vocabulary/import.php` (`vocabulary_import`, apply only):
  `vocabulary.save` (`after.import`: added, updated, refused).
- `areas/save.php` (`focus_area_save`): `focus_area.save` (focus_area_id, name, status, seed_terms count, example_questions count,
  engine_linked). `areas/propose.php` (`focus_area_propose`): `focus_area.propose` (name, note length). `areas/accept.php` /
  `dismiss.php` (`focus_area_accept` / `focus_area_dismiss`): `focus_area.accept` / `focus_area.dismiss` (focus_area_id, proposed_by).
  `areas/link.php` (`focus_area_link`): `focus_area.save` (`after.engine_linked`, `project_id`). `areas/delete.php` (`focus_area_delete`,
  **deletion**): `focus_area.delete` (name, project_id, sources). `areas/assign.php` (`focus_area_assign`) and `sources/areas.php`
  (`source_areas_set`): `focus_area.assign` (source_id, added, removed).

## Action manifest entries
Screens: `guidelines`, `guideline-add`, `guideline-edit`, `guideline-view`, `guidelines-compiled`, `vocabulary`, `term-add`, `term-edit`,
`vocabulary-import`, `areas`, `area-add`, `area-edit`, `area-view`. Actions (13): `guideline_save`, `guideline_delete`, `vocabulary_save`,
`vocabulary_delete`, `vocabulary_import`, `focus_area_save`, `focus_area_propose`, `focus_area_accept`, `focus_area_dismiss`,
`focus_area_link`, `focus_area_delete`, `focus_area_assign`, `source_areas_set`. Agent approvals (reconciled with `maludb-os.json`):
`guideline_delete`, `vocabulary_delete`, `focus_area_delete` (`deletion`); the rest free (the expert holds `guideline_save`,
`vocabulary_save`, `focus_area_save`, `focus_area_propose`). `PARTIAL_UPDATE_TARGETS` gains `/guidelines/save.php => ['guidelines',
'guideline', 'mcp_guidelines', 'guideline_id']`, `/vocabulary/save.php => ['vocabulary_terms', 'term', 'mcp_vocabulary_terms', 'term_id']`,
`/areas/save.php => ['focus_areas', 'focus_area', 'mcp_focus_areas', 'focus_area_id']`.

## Activity log events (every row: `base_id`)
`guideline.save|delete|version`, `section.stale`, `vocabulary.save|delete`, `focus_area.save|propose|accept|dismiss|delete|assign`,
`screen.view` (`guidelines`, `area-view`). **No row carries a guideline's body, a description's words or an example question** — kinds, titles,
names, counts, versions. A term's canonical name and an area's name are labels and may be logged.

## Notifications this slice queues
| Step | Who is told | Kind |
|---|---|---|
| the expert proposes an area | each curator of the base (`kn_base_curator_ids()`) | `proposal` (dedupe `area:{id}:{member}`) |

## Status vocabulary
Origin chips: template `light`, edited `info`, person `secondary`, agent `info` with the agent icon; "shapes the graph" `warning` on the three
kinds; inactive greyed; area status: active `success`, proposed `primary`, archived `dark`; linked `success` / not linked `warning`; never met
`warning`. Ids: `guidelines-kind-{kind}`, `guideline-card-{id}`, `guideline-form`, `guideline-form-field-{name}`, `guideline-examples`,
`preview`, `preview-section`, `built-under`, `vocabulary-table`, `term-row-{id}`, `alias-conflicts`, `import-check`, `areas-proposed`,
`area-card-{id}`, `proposal-card-{id}`, `areas-unassigned`, `area-sources`, `area-engine-link`.

## Out of scope for this slice
Seeding the terms into the engine and ensuring the namespace and areas before extraction (4 — it calls `kn_compile_ensure()` and
`kn_area_engine_sync()`); asking scoped to an area (5); merging duplicate subjects and the graph's view of a term (6); the expert's dispatch that
proposes areas and drafts guidelines (8); the Archivist's proposals beyond accepting an area one (8); evals of a guideline change (Extended).

## Proof (`tests/phase3/slice3/run.sh`: the scratch database `kn_dev3`, the app on :8501, the fake kernel on :8502, the real MaluDB API
0.4.0 on a scratch memory database on :8503 with the fake provider on :8504 (`tests/phase3/engine.sh`; `kb:0` configured by the run with the
fake provider's connection and a dummy secret), the fake MaluDB + MaluMail on :8506; headless Chromium at 375 × 740 and 1280 × 800; the
registry and approvals `--check`)
The world: slice 2's, with "SMOKE Field Manuals" read and cut (sections `queued`; the test sets twelve of them `extracted` at version 1 with a
`maludb_document_id` from a real ingest, so staleness shows).
- [ ] **Compile** — every box of `guidelines-compile.md`'s proof (`compile.php` and the golden files).
- [ ] **Guidelines**: the four shipped rows grouped by kind; an extraction save → version 2, twelve sections `stale`, the base
  `needs_rebuild`, `guideline_versions` row with `base_guideline_version` 2, `guideline.save` + `guideline.version` + one `section.stale`
  logged, no body in any row, the namespace's prompt re-sent (`kn_compile_engine_sha('kb:N')` = the new hash), `did` saying so; a position-only
  change → no version; an answering save → version unchanged, no stale, nothing sent, "applies to the next question"; a shipped row edited → the
  "edited" chip; an inactive extraction guideline absent from the compiled prompt; delete of an exclusion → version 3; the engine stopped → the
  save stands and `did` says "before the next build"; Reid (read) → 403; Bea → 404; an agent's save → origin `agent`.
- [ ] **Preview**: the draft (unsaved) shown inside the prompt with the chosen section's text after `Text:`; "no change" for an unchanged draft; a
  draft over the cap → the truncation warning; an answering draft → the answer guidelines with it; a section of another base → 404; nothing
  written and nothing logged; the engine's generic prompt present in the `<details>` with the engine up and absent with it down.
- [ ] **Vocabulary**: a term with aliases → version bump, `vocabulary.save`, the ALIAS lines in `/bases/{id}/compiled`; a duplicate name → the
  field; a type with a digit → the field; an alias conflict shown; never-met for a term no section mentions and not for one that does; a CSV of 50
  rows with two bad lines → check writes nothing, import adds 46 and updates 2 in one transaction, version raised by 48, `did` words it;
  delete → `vocabulary.delete`; 501 rows → refused whole.
- [ ] **Areas, against the engine**: "SMOKE Warranty" with seed terms `Zorblat Pump`, `claim` → a project `kb:N/SMOKE Warranty` with both
  subjects linked (the second seeded without a model call — the fake provider's extract count unchanged); a rename → the old project gone
  (`GET /v1/projects/{old}` 404), the new one with the same links; a seed term removed → unlinked; Seamus proposes "SMOKE Returns" (status
  `proposed`, no project, the curators told once each); Accept → active, linked; another dismissed → archived, no project; an archivist
  proposal of kind `area` naming it follows; delete → the project's links removed then the project, then the row; with the engine stopped the
  save leaves "not linked", Link now refuses in the engine's words, a delete is refused and the area kept; the purge of a deleted base removes
  its areas' projects.
- [ ] **Assignment**: three sources assigned from `source-view` (`added` 3), one removed from `area-view`, another base's area refused by the
  trigger's words, the unassigned and empty lists follow; "N sections built before they joined" counts the twelve extracted ones; no version bump.
- [ ] **Who sees**: Reid sees the areas and area-view (no proposals, no link status, no buttons) and no guidelines, vocabulary or compiled
  prompt (403 "Guidelines and the vocabulary are the curators'."); Ann the same as Reid on the base named to her; Avery's view of the private base logs `base.admin_view`.
- [ ] **JSON mode and agents**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; `_partial=1` keeps
  untouched fields on the three partial targets; 422 `{error: {code: invalid, fields}}`; the expert (run token + relay, a curate share and the
  Curator role) saves a guideline and proposes an area as `source agent`; `guideline_delete`, `vocabulary_delete`, `focus_area_delete` listed
  `deletion` in the registry.
- [ ] **375 × 740 and 1280 × 800**: the kind groups stack; the examples sit beside the form at 1280 and below it at 375; Preview fills `#preview`
  without a reload and scrolls inside the page (a long prompt never widens the viewport — `pre` wraps); the vocabulary table scrolls within the
  page; area cards with chips; every control ≥ 44 px; no console errors; JavaScript off saves and previews (a full page); the registry reads 13
  screens and 13 actions of this slice built.

## Built and proven
Not built.

## Open questions
