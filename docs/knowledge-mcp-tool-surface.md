# Knowledge — MCP tool surface

2026-10-05 · **Phase 1, for the checkpoint.** The read tools that answer every question in `docs/knowledge-design.md` §7 (B1–B3, S1–S5,
G1–G5, L1–L3, K1–K7, Q1–Q7, A1–A4, ACT1–ACT5), the three K7 shares, and the token API's JSON. Writes are never here: they are the
action tools the kernel's Actions MCP builds from `docs/knowledge-action-manifest.md`. The one exception in effect, `ask`, records
the question it answers. It does that through PHP (the engine bridge, below), never from the read role.

Conventions (`mcp-and-api.md`): Python 3 + FastMCP (`mcp>=1.2,<2`), streamable HTTP at `/mcp`, bound to `127.0.0.1`, one systemd unit
each (`knowledge-records-mcp`, `knowledge-activity-mcp`); `mcp/db.py` (already here) and `server_common.py` (Help Desk's, copied in
Phase 4). Every query runs through `db.fetch_scoped()` with `app.member_id` and `app.role` set **transaction-locally**
(`set_config(…, true)`) from the verified bearer, so the `mcp_*` views (db/014) and the read functions (db/013) decide the rows and
answer as the caller. A pooled connection never carries an identity past its transaction. Tool names are snake_case. Every tool
takes one `params` object (Pydantic v2, `extra="forbid"`, `str_strip_whitespace=True`, a described `Field` on every field). Every
list tool takes `limit` (1–100, default 25) and, where it pages, `offset` (0–10,000). Every tool carries `readOnlyHint: true` and
`openWorldHint: false`, and every result is a JSON string (`db.to_json`). **A section, an outline, an answer and a pinned answer reach
an agent as Markdown** beside their facts. Ids are integers, times are ISO 8601 with the zone, and base, area and template keys are
lower-case slugs.

**Errors are answers, not exceptions.** A tool that cannot answer returns `{"ok": false, "error": {"code", "message", "retry": bool}}`.
It never raises, except for the run-facts gate's own refusal ("'x' is not among the tools this agent was granted on Records MCP"),
which is the contract's ToolError. Codes:

| Code | Means |
|---|---|
| `not_found` | no such record, **or one the caller may not read**: the same words, so a tool never confirms that a base exists |
| `curators_only` | the record is readable, but this tool is a curator's on that base (§3: guidelines, keys, builds, usage, other people's questions) |
| `invalid_argument` | a parameter failed validation; `fields` names it |
| `ambiguous` | a name matched several records; `candidates[]` (≤ 6, id + label) are returned so the agent asks one question |
| `engine_unavailable` | no `MALUDB_KB_API_*`, or MaluDB cannot be reached (`engine.php`) |
| `engine_timeout` | MaluDB did not answer in time |
| `engine_error` | any other engine failure (`engine_auth`, `engine_forbidden`, `route_not_found`, `model_failed` collapse here, with `detail` = the engine's code) |
| `not_in_base` | a graph node (subject, community) that the base's documents never touch |
| `no_path_in_base` | the engine's path runs through another base's documents |
| `bridge_unavailable` | the PHP side of the engine bridge did not answer (Apache down); `retry: true` |
| `too_large` | the answer would pass the tool's byte cap: narrow it |

Gates are what the view or function already enforces. The tool only trims and chooses the error. Words used below (db/004, db/006,
db/014, design §3):

| Word | Means |
|---|---|
| `reader` | the base is in `kn_visible_base_ids()`: owner, a named member, agent or department, `everyone` (an internal base, not for a guest), the admin |
| `curate` | the base is in `kn_curated_base_ids()`: owner, a `curate`/`own` share naming the caller or their department, the admin |
| `own` | the caller's own: their questions, notifications, tokens |
| `insider` | `kn_is_member_here()`: admitted and not a guest |
| `admin` | `kn_is_admin()`: the super-admin, or a human holding `settings.manage`. Every base, private ones included |
| `kernel` | the kernel's own token (`kernel.{exp}.{app}.{nonce}.{hmac}`): `app_roles` and the three shares, nothing else |

**Reach is the base's, never the role's alone.** A right (`kn_has_right`) is a capability. Which bases it applies to is
`base_members`. An agent hired with the role `user` (the expert, the Archivist) reads curator tools only on bases where a curator
shared `curate` with it, and gets `curators_only` elsewhere. **A guest** gets the bases named to them and the people on those bases:
every tool answers the same smaller world. **An agent is a member**: the same functions apply, with no special case.

## The two servers

| | Records | Activity |
|---|---|---|
| FastMCP name | `knowledge_records_mcp` | `knowledge_activity_mcp` |
| Port | `MCP_RECORDS_PORT` (pinned 8835) | `MCP_ACTIVITY_PORT` (pinned 8836) |
| Role / env | `knowledge_records_ro` (`MCP_RECORDS_DB_USER`/`_PASSWORD`) | `knowledge_activity_ro` (`MCP_ACTIVITY_DB_USER`/`_PASSWORD`) |
| Sees | every `mcp_*` view but `mcp_activity_log`; the db/013 functions; `mcp_resolve_token`, `mcp_admit_agent`, `mcp_member_kind` | `mcp_activity_log` + `mcp_members`, `mcp_departments`, `mcp_bases`, `mcp_sources`, `mcp_focus_areas` (names beside a row) |
| Public path | `knowledge.<domain>/mcp/records` (Apache, `ProxyPreserveHost Off`) | `/mcp/activity` |
| Kernel registry endpoint name | `Records MCP` | `Activity MCP` |

**Who may call: three token shapes, one middleware** (`server_common.make_app`).

1. **A person's own token**: `mcp_` + 48 hex, scope `mcp`, minted on My MCP tokens and shown once; only its sha256 is stored. It is
   resolved by `mcp_resolve_token(hash, 'mcp')`, which requires the member to be active and admitted. **A key (scope `api`) is
   refused here** (401): keys are for the token API alone. A person's token is never tool-filtered. Its rows are the views'.
2. **The tenant's signed tokens** (`ACTION_TOKEN_KEY`): `{mid}.{exp}.{hmac}` (the person at the keyboard, through the kernel's
   assistant) or the **agent run token** `{mid}.{exp}.{run}.{hmac}`. The member id must already be in the mirror and admitted.
   **An unknown id is refused, never created.** For a run token the server asks the kernel's run-facts call,
   `POST {OS_INTERNAL_URL}/api/v1/runs/facts.php {"token"}` with `Authorization: Bearer {OS_APPLICATION_TOKEN}`, which answers
   `{valid, is_agent, member_id, run_id, request_id, is_eval, endpoints:[{name, tools}]}`. The server asks once per run and caches the
   answer for 300 s. It offers exactly the tools named for `Records MCP` / `Activity MCP`. On an agent's first contact a vouched agent
   is admitted (`mcp_admit_agent(member_id)`) and resolved again. **Fail closed:** if the kernel is unreachable, `OS_APPLICATION_TOKEN`
   is missing or `valid: false`, the server lists and calls no tools. **`is_eval`:** these servers never write, so every read
   answers. `ask` answers too, but under an eval run it **persists nothing**: no `questions` row, no citations, no gap, no usage row.
   It logs `question.ask` with `after.eval = true`, and `question_id` is `null` in the answer, so the kernel's "nothing changed"
   check holds.
3. **The kernel's own token** (`verify_kernel_token`, 60 s, bound to `APP_KEY = knowledge`): `KERNEL_TOOLS = {app_roles, ask, search,
   bases_index}`, and `KERNEL_ONLY = {bases_index}` (a person or an agent never lists or calls it). No member identity is set:
   `app.member_id` is empty, so every view answers nothing. The shares answer as the **application principal** through the bridge
   (§ The shares). The kernel sends a share's arguments flat. The middleware wraps them in `params`.

**Identity on every query.** `app.member_id` = the resolved member and `app.role` = `members.business_role`, transaction-local. The
server never filters rows in Python by who is asking: the views do that. The Python side only resolves names to ids **through the
same views** (so a name the caller cannot see is `not_found`) and trims fields.

### The engine bridge (how a Python tool reaches MaluDB and `kn_ask()`)

The MaluDB token is read in ONE file, `app/knowledge/engine.php` (CLAUDE.md), and `kn_ask()` is PHP. The read servers therefore never
call MaluDB and never insert a question. Every engine-backed tool and `ask` posts to PHP on the **internal port only**:

```
POST http://127.0.0.1:{APP_INTERNAL_PORT}/internal/bridge.php
X-KN-Bridge: {unix_ts}.{hex hmac_sha256(ACTIONS_RELAY_KEY, "kn-bridge:" + unix_ts + "." + sha256_hex(body))}
Content-Type: application/json
{"op": "<op>", "caller": {"kind": "member", "member_id": 26} | {"kind": "application", "application": "helpdesk"},
 "door": "mcp" | "agent" | "share", "run_id": 812 | null, "request_id": "…" | null, "is_eval": false, "args": {…}}
```

- PHP refuses with 401 unless `REMOTE_ADDR` is loopback, the timestamp is within ±30 s and the HMAC verifies (the `kn-bridge:` prefix
  separates it from the actions relay). The path is **not** on the vhost's public allow-list.
- A `member` caller must be an active, admitted mirror row, or the call is a 403 `unknown_member`. PHP sets `app.member_id` for the
  request (`db_apply_context`) and applies the **same** gates as the screens: `kn_can_read_base()`, and `kn_can_curate_base()` for a
  curator op. An `application` caller is judged by `kn_application_base_ids(application)` alone and is honoured only for the share
  ops (`share_ask`, `share_search`, `share_bases`).
- Ops: `ask`, `search`, `suggest`, `graph_stats`, `communities`, `community_members`, `subject`, `subject_neighbors`,
  `subject_statements`, `path`, `god_nodes`, `surprises`, `graph_query`, `section_graph`, `source_graph`, `sections_for_subject`,
  `duplicate_subjects`, `preview`, `admin_view`, `share_ask`, `share_search`, `share_bases`. Any other op answers 404.
- The answer is the tool's JSON, passed through verbatim (an engine failure included, as `{"ok": false, "error": …}`). The Python
  side waits 65 s for `ask`, 35 s for `search`, 55 s for `share_ask`/`share_search`, 7 s for `share_bases` and 15 s for anything else (the waits as built and confirmed in Phase 4 — § Phase 4, item 6; the approved text said 25 s and 7 s before M1's 60 s model timeout and K26). A connection error or a
  timeout becomes `bridge_unavailable` / `engine_timeout`.
- Built by slice 5 (`ask`, `search`, `suggest`, `admin_view`), slice 3 (`preview`), slice 6 (the graph ops) and slice 7 (the share ops).
  Phase 4 wires the Python side. Engine results are cached per (op, base, arguments, guideline_version) for 60 s, and
  `duplicate_subjects` for 10 min.

**Every graph answer is confined to the base** (design §0: `graph/query`, `stats` and `god-nodes` are tenant-wide, and subjects are
shared by every base in the memory database). In each answer:

- A document node stays only if it is in the namespace (`kn_engine_documents_in`). It is then **replaced by its section** (`{section_id,
  heading_path, source: {id, title}, page}`, mapped by `sections.maludb_document_id`). A document with no section (a seed document)
  is dropped.
- A subject stays only if an edge joins it to one of those documents.
- A tenant-wide count is never returned.

## Records server: `knowledge_records_mcp`

### Roles (kernel db/145)
`app_roles` takes no arguments. It answers `os.app-roles/1`: `{"schema": "os.app-roles/1", "application": "knowledge", "roles":
[{key, name, description, capability, is_admin, rights[]}], "rights": [{key, description}]}`. It reads `mcp_app_roles` and `kn_rights`
(db/004) and returns 4 roles and 20 rights. Callers: `kernel` and anyone admitted.

### Bases (B1–B3)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_bases` | Which bases I may read: what each is about, how big, when last built. `q` alone resolves a base by key or name. Not for one base in full (`get_base`) | B1 | `mcp_bases` | `q?` (≤ 100, matches `key` exactly or `name` ILIKE), `status?` (empty, building, ready, needs_rebuild), `mine?` (bool: owned or curated), `include_archived?` (false), `limit`, `offset` | rows `{base_id, key, name, description (≤ 300), template, visibility, status, my_level, i_curate, source_count, section_count, stale_count, area_count, pinned_count, documents_count, subjects_count, last_built_at, running_build_id, owner_name, archived_at}`, newest built first | reader |
| `get_base` | One base at a glance: counts by status, areas, the last build, curators. For a curator, also guideline counts, keys, feeds, open proposals, 30-day gaps and the build estimate | B1 | `kn_base_overview(base_id)` | `base` (key or id) | the function's object verbatim (`my_level` decides the curator half). An admin reading a private base where they hold no share gets `admin_view: true` and is logged `base.admin_view` through the bridge (`admin_view`, once per base per caller per hour) | reader |
| `base_members` | Who may read or curate base X and why: member, department, everyone, agent, application | B2 | `mcp_base_members` | `base`, `kind?` (member, department, agent, application, everyone), `limit` | rows `{base_member_id, principal_kind, principal_id, principal_ref, principal_name, level, granted_by, created_at}` plus the owner as the first row `{principal_kind: "owner"}` | curate |
| `get_settings` | The application's settings: limits, key defaults, retention, thresholds; the model (admin) | B3 | `mcp_settings` | none | the row. `model_alias` and `embedding_model` are `null` unless `admin` (the view blanks them) | any admitted (guest included) |
| `find_templates` | The templates and the guideline set each ships. `q` alone resolves one | B3 | `mcp_templates` | `q?`, `include_inactive?` | rows `{template_key, name, description, version, subject_types, area_rule, default_visibility, is_active, guidelines}`. A template's shipped set is product text, not a base's guidelines | any admitted |

### Sources and sections (S1–S5)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_sources` | The sources of a base by kind, status and area; which are stale, failed or without text. `q` alone resolves a source by title across my bases | S1 | `mcp_sources` | `base?` (required unless `q`), `q?` (title ILIKE), `kind?` (file, text, url, feed, note), `status?` (uploaded, extracting_text, sectioning, ready, no_text, failed, forgotten), `area?` (slug or id), `stale?` (bool), `include_forgotten?` (false), `limit`, `offset` | rows `{source_id, base_id, kind, title, status, sections_count, char_count, page_count, is_stale, built_under, focus_area_ids, error (≤ 300), added_by_name, added_at, url_checked_at, superseded_by}`. For a url source `origin` is the URL. For a file source it is the file name, never a storage path | reader |
| `get_source` | One source: origin, status and error, pages, areas, its file, its top-level outline | S1 | `mcp_sources`, `mcp_source_pages` (count, first/last), `mcp_attachments` (`filename, mime_type, byte_size`), `mcp_focus_areas`, `kn_source_outline()` (level 1 only, ≤ 50) | `source` (id or title) | `{source, pages: {count}, file?, areas[], outline[]}` | reader |
| `source_outline` | What a source contains, as a tree in reading order, without text | S2 | `kn_source_outline(source_id)` | `source`, `depth?` (1–9, default 9) | `{markdown, sections: [{section_id, parent_id, level, pos, heading, heading_path, kind, page_no, char_count, status, extracted_version}]}`. The `markdown` is an indented list, one line per section: `- Heading (p. 4) [#123]`. Capped at 500 sections, with `truncated` | reader |
| `get_section` | **What a section says**: its heading path, its text, the source and page. The tool an agent reads with | S2 | `mcp_sections` first. Then `kn_section_markdown(id)` **only when `status <> 'forgotten'`** | `section` (id), `with_neighbours?` (true) | `{section_id, base_id, source: {id, title}, heading_path, kind, level, page_no, status, extracted_version, char_count, markdown, prev_section_id, next_section_id}`. Forgotten sections: `markdown = "# <heading_path>\n\n(forgotten)"` and no text. `maludb_document_id` only for `curate` | reader |
| `source_page` | One page of a paged source (PDF page, slide, sheet): the sections on it | S2 | `mcp_source_pages` (the page's range), `mcp_sections` whose `[char_from, char_to)` meets it, not forgotten | `source`, `page` (≥ 1) | `{source: {id, title}, page, page_count, sections: [{section_id, heading_path, markdown}]}`, ≤ 12 sections / 24 kB, then `truncated` | reader |
| `source_excerpt` | The text around a citation: the words of a quote with a little before and after, **inside its section** | S2 | `mcp_sections` (or `mcp_citations` → its section) | `section` or (`question` + `n`), `quote?` (≤ 500, default the citation's), `window?` (100–1,500, default 600 characters each side) | `{section_id, heading_path, source, page_no, found: bool, before, match, after}`. Matching ignores case and whitespace runs. If the quote is not found you get `found: false` and the section's first `2 × window` characters. The excerpt never crosses the section's edges | reader |
| `section_graph` | What a section taught the graph: its subjects and statements | S3 | bridge `section_graph` → `kn_engine_neighbors('document', maludb_document_id)` | `section` | `{section_id, extracted_version, subjects: [{subject_id, name, type}], statements: [{subject, verb, object, statement_id}]}`. A section never extracted gets `subjects: []` and `extracted: false` | reader |
| `source_graph` | The same for a whole source | S3 | bridge `source_graph`: the neighbours of each of its extracted sections' documents (≤ 50 sections) | `source`, `limit` (subjects, ≤ 100) | `{source_id, sections_read, sections_total, subjects: [{subject_id, name, type, sections: [section_id]}]}`, by section count | reader |
| `sections_for_subject` | Which sections mention subject Y | S4 | bridge `sections_for_subject` → `kn_engine_neighbors('subject', id)`, then document → section | `base`, `subject` (id or name), `limit` | `{subject: {subject_id, name}, sections: [{section_id, heading_path, source, page_no}]}` | reader |
| `find_feeds` | The feeds the base follows and when each last synced | S5 | `mcp_feeds` | `base?` (required unless `q`), `q?` (provider.tool), `enabled?`, `limit` | rows `{feed_id, base_id, provider, tool, arguments, focus_area_id, every_minutes, enabled, last_sync_at, next_sync_at, last_status (ok, failed, no_connection, refused), last_error (≤ 300), items}` | curate |

### Guidelines and areas (G1–G5)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_guidelines` | Base X's guidelines by kind, and whether each shapes the graph. `q` alone resolves one by title | G1 | `mcp_guidelines`, `mcp_bases.guideline_version` | `base?` (required unless `q`), `kind?` (extraction, vocabulary, exclusion, answering, audience), `include_inactive?`, `q?` | `{base_guideline_version, guidelines: [{guideline_id, kind, shapes_graph, title, body, position, is_active, origin, version_no, updated_at}]}`, by kind then position | curate |
| `guideline_versions` | A guideline's history, and which version the graph is built under | G1 | `mcp_guideline_versions`; `mcp_sections` grouped by `extracted_version` | `base`, `guideline?`, `limit` | `{base_guideline_version, built_under: [{extracted_version, sections}], stale_sections, versions: [{guideline_version_id, guideline_id, version_no, kind, title, body, is_active, base_guideline_version, saved_by, saved_at}]}` | curate |
| `extraction_preview` | What these guidelines would extract from this section: try a draft before spending | G2 | bridge `preview` → compile (`docs/build-specs/guidelines-compile.md`) the saved set, or the saved set with `draft` overlaid, then `kn_engine_preview(namespace, section text, hints)` | `section`, `draft?` (≤ 10 `{kind, title, body (≤ 4,000)}` replacing or adding to the saved ones of that kind) | `{section_id, documents_prompt, generic_prompt, hints[], note: "a dry run of the prompt, not of the model (MaluDB M5)"}`. Nothing is saved | curate |
| `find_vocabulary` | The vocabulary: terms, aliases, types. `q` alone resolves a term | G3 | `mcp_vocabulary_terms` | `base?` (required unless `q`), `q?` (name or alias), `subject_type?`, `limit`, `offset` | rows `{term_id, canonical_name, aliases, subject_type, description, seeded (maludb_subject_id IS NOT NULL), updated_at}` | curate |
| `unused_terms` | Terms the graph never met | G3 | `kn_unused_terms(base_id)` | `base`, `limit` | rows `{term_id, canonical_name, aliases, subject_type, seeded}` | curate |
| `find_focus_areas` | The focus areas with terms, example questions and size; which are proposed and by whom. `q` alone resolves an area across my bases | G4 | `mcp_focus_areas` | `base?` (required unless `q`), `q?` (slug or name), `status?` (proposed, active, archived; default active + proposed), `limit` | rows `{focus_area_id, base_id, name, slug, description (≤ 500), seed_terms, example_questions, status, proposed_by_name, proposal_note, source_count, position}` | reader |
| `get_focus_area` | One area: its description, terms, example questions, its sources | G4 | `mcp_focus_areas`, `mcp_source_focus_areas` → `mcp_sources` (≤ 50) | `area` (base + slug, or id) | `{area, sources: [{source_id, title, status, sections_count}], section_count}`. `maludb_project_id` only for `curate` | reader |
| `unassigned_sources` | Sources in no area | G5 | `kn_unassigned_sources(base_id)` | `base`, `limit` | rows `{source_id, title, kind, status, sections_count, added_at}` | curate |
| `empty_areas` | Areas with no source | G5 | `kn_empty_areas(base_id)` | `base` | rows `{focus_area_id, name, slug, status, created_at}` | curate |

### Builds (L1–L3)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `build_progress` | Is a build running on base X, how far, what failed | L1 | `kn_build_progress(build_id)`; `mcp_build_items` for `items` | `build`, or `base` (its running build, else its last), `items?` (queued, running, done, failed, skipped), `limit` | the function's object with `failed_items` cut to 50 (`failed_items_truncated`), and `items: [{build_item_id, section_id, heading_path, status, chars, started_at, finished_at, error (≤ 300)}]` when `items` is given | curate |
| `find_builds` | The builds of a base, newest first | L1 | `mcp_builds` | `base?` (admin: all), `status?`, `kind?` (extract, rebuild_all, reindex, embed, forget), `since?`, `limit`, `offset` | rows `{build_id, base_id, kind, status, guideline_version, started_by_name, requested_at, started_at, finished_at, items_total, items_done, items_failed, chars_processed, subjects_added, statements_added, error (≤ 300)}`. `usage` only with `costs.read` | curate |
| `build_estimate` | What a build would do now: sections, characters, the monthly limit | L2 | `kn_build_estimate(base_id, kind)` | `base`, `kind?` (extract, rebuild_all; default extract) | the function's object verbatim | curate |
| `base_usage` | How much text and how many engine calls base X used this month | L3 | `kn_usage(base_id, month)`; with no base, `mcp_ai_usage` grouped by base | `base?` (no base requires `costs.read`: every base), `month?` (YYYY-MM, default this month) | rows `{base_id?, kind (extract, answer, embed), calls, input_tokens, output_tokens, chars, updated_at}` with `month_limit_chars`, `month_used_chars`. **Never dollars**: prices are the kernel's AI statement (K24) | curate |

### The graph (K1–K7), all through the bridge
| Tool | Call it when | Answers | Engine call | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `graph_stats` | The size and shape of base X's graph: nodes, edges, communities, top relationships | K1 | `kn_engine_stats(namespace)` (its base-level `documents` only) + `kn_engine_god_nodes(namespace, 10)`; the cached counts from `mcp_bases` | `base` | `{base_id, documents (live), documents_cached, subjects_count, statements_count, communities_count, last_built_at, top_subjects: [{subject_id, name, degree}], top_verbs: [{verb, count}] (from the god nodes' edges)}`. The engine's tenant-wide figures are never returned. If the engine is down you get the cached counts with `engine: "unavailable"` | reader |
| `communities` | The communities of base X | K2 | `kn_engine_communities(namespace)` | `base`, `limit` | `{communities: [{community_id, label, size}], note}`. **Usually empty** until MaluDB detects communities (M6). The note then says so and points to `god_nodes` | reader |
| `community_members` | What is in community C | K2 | the id must be in `kn_engine_communities(namespace)`, then `kn_engine_community_members(id)` | `base`, `community`, `limit` (≤ 200) | `{community_id, members: [{kind, id, name}]}`, confined | reader |
| `get_subject` | What subject Y is: type, aliases, statement and neighbour counts, the sections behind it | K3 | `kn_engine_find_subject(name)` or the id, then `kn_engine_neighbors('subject', id)`, confined | `base`, `subject` (id or name) | `{subject_id, name, type, aliases (as the engine keeps them: M7), statements, neighbours, sections: [{section_id, heading_path, source, page_no}] (≤ 20)}`. A subject the base's documents never touch is `not_in_base` | reader |
| `subject_neighbors` | Y's neighbours | K3 | `kn_engine_neighbors('subject', id)`, confined | `base`, `subject`, `kind?` (subject, document, statement), `limit` | `{subject, neighbours: [{kind, id, name, verb?, section?}]}` | reader |
| `subject_statements` | What the base says about Y, by verb | K3 | neighbours of kind statement, grouped by verb | `base`, `subject`, `verb?`, `limit` | `{subject, by_verb: [{verb, statements: [{statement_id, text, object, section}]}]}` | reader |
| `graph_path` | How Y and Z are related | K4 | `kn_engine_path([kind, id], [kind, id], max_depth)` | `base`, `from`, `to` (subject id or name each), `max_depth?` (1–8, default 6) | `{found, steps: [{kind, id, name, verb?, section?}]}`. If a document node on the path lies outside the base, or a subject node touches none of its documents (checked for ≤ 12 nodes), the answer is `no_path_in_base` | reader |
| `god_nodes` | The most connected subjects | K5 | `kn_engine_god_nodes(namespace, limit)` | `base`, `limit` (≤ 50, default 10) | rows `{kind, id, name, degree, section?}`, confined | reader |
| `surprises` | Surprising links between distant parts of the graph | K5 | `kn_engine_surprises(namespace, limit)`, confined like the god nodes | `base`, `limit` (≤ 50) | rows `{from: {kind, id, name}, to: {…}, verb, score, sections[]}` | reader |
| `graph_query` | The subgraph around a phrase | K6 | `kn_engine_graph_query(text, namespace, depth, max_nodes)` | `base`, `text` (2–400), `depth?` (1–3, default 2), `max_nodes?` (1–100, default 50) | `{seeds[], nodes[], edges[]}` with document nodes replaced by sections | reader |
| `duplicate_subjects` | Subjects that look like duplicates the vocabulary should merge | K7 | the subjects of up to 200 of the base's documents (`kn_engine_documents` + neighbours), grouped by a normalised name (lower case, unaccented, punctuation and a trailing plural `s` dropped) and by the vocabulary's aliases | `base`, `limit` (groups, ≤ 50) | `{groups: [{normalised, subjects: [{subject_id, name, type}], vocabulary_term_id?}], documents_read, documents_total}`. Cached 10 min | curate |

### Asking (Q1–Q7)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `ask` | **Answer this from base X, or area A, with citations.** The one tool behind every door. Call it before answering any question about a base's content, and never answer from memory. Not for raw passages (`search`) | Q1 | bridge `ask` → `kn_ask()` | below | `os.knowledge-answer/1` (§ The answer) | reader; `kernel` as a share |
| `search` | What evidence base X holds on this: passages and statements, no composed answer. Use it when you will compose yourself, or when `ask` was `unverified` | Q2 | bridge `search` → `kn_engine_search(query, namespace, limit)` | `base`, `query` (1–2,000), `focus_area?`, `limit?` (1–20, default 8) | `os.knowledge-evidence/1` | reader; `kernel` as a share |
| `suggested_questions` | What base X or area A could answer, for an empty ask window or an agent offering help | Q3 | `mcp_focus_areas.example_questions`, active `mcp_pinned_answers` titles, and (bridge `suggest`, engine optional) "What does the base say about <subject>?" for the top 5 god nodes | `base`, `focus_area?`, `limit` (≤ 20) | rows `{question, from (area, pin, graph), focus_area_id?, pinned_answer_id?}`. Never another person's question | reader |
| `my_questions` | What I asked and what was answered. `q` alone resolves one of my questions | Q4 | `kn_questions(base, …, p_asked_by = me)`; with no base, `mcp_questions WHERE asked_by_member_id = me` | `base?`, `status?`, `since?`, `conversation_id?`, `q?` (≤ 200), `limit`, `offset` | rows `{question_id, base_id, focus_area_id, conversation_id, turn_no, door, question (≤ 300), status, confidence, citation_count, verified_citations, my_rating, pinned, created_at}` | own |
| `get_question` | One question in full: its answer, citations and evidence | Q4 | `mcp_questions`, `mcp_citations`, my row of `mcp_question_feedback` | `question` (id) | the `os.knowledge-answer/1` document rebuilt from the row, plus `{asker_name, key_label?, application_key?, door, created_at, my_rating}`. `usage`, `model` and `latency_ms` only for `curate` | own, or curate (the view) |
| `questions` | What was asked of base X: by whom or which key, answered or not, rated how | Q5 | `kn_questions(base, status, since, asked_by, door, token_id, focus_area_id, search, limit, offset)` | `base`, `status?`, `since?`, `asked_by?` (member), `door?` (web, api, mcp, share, agent), `key?`, `focus_area?`, `q?`, `limit`, `offset` | the function's rows with `question` cut to 300 and `answer_md` to 600 characters | curate (the function answers own rows to a reader: the tool says `curators_only` instead) |
| `question_feedback` | The ratings and comments on base X's answers | Q5 | `mcp_question_feedback` on curated bases | `base`, `rating?` (up, down), `since?`, `limit` | rows `{feedback_id, question_id, member_name, rating, comment, created_at, question (≤ 200), status}` | curate |
| `question_gaps` | What the base could NOT answer, clustered | Q6 | `kn_question_gaps(base_id, since)` | `base`, `since?` (default 30 days), `limit` (clusters) | rows `{cluster_key, question_count, sample_question (≤ 200), last_asked_at, question_ids (≤ 20)}` | curate |
| `pinned_answers` | The pinned answers of a base: owner, review date. `q` alone resolves one by title | Q7 | `mcp_pinned_answers` | `base?` (required unless `q`), `q?`, `status?` (active, retired; default active), `limit` | rows `{pinned_answer_id, base_id, title, question_text, answer_md, owner_name, review_until, review_due, verified_at, source_id, status}` | reader |
| `pins_due` | Pinned answers past their review date | Q7 | `mcp_pinned_answers WHERE review_due` | `base?`, `owner?` (me), `limit` | as above | reader |

**`ask` in full.** Parameters: `base` (key or id; required unless `conversation_id` names one of the caller's), `question` (1 to
`kn_settings.max_question_chars`, 2,000), `focus_area?` (slug or id, an area of that base), `conversation_id?` (≤ 120). `kn_ask()`
works in this order:

1. **Gate.** The base must be readable, or the result is `not_found` and nothing is stored. The question length is checked
   (`invalid_argument`). A key (token API only) must reach the base and pass `kn_rate_ok(token_id)`. **A person, an agent and a share
   are never rate-counted: `kn_rate_ok` applies to keys alone** (its grant is `knowledge_rw`'s; the read roles cannot call it).
2. **Turns.** Up to `conversation_turns` (3) earlier turns of the same conversation, the same base and the **same asker** (member,
   key or application). A `conversation_id` of someone else's starts a new conversation and never reads their turns. A new one is
   `c_` + 24 hex. `turn_no` = the last one + 1.
3. **Built?** No section of the base is in the engine yet → `refused` (`error_code` `base_not_built`); a named area with no MaluDB
   project yet → `refused` (`area_not_built`). The question is stored and logged, never sent to the engine. There is no pre-match of
   exclusion guidelines against a question: their answer-time half is the `LEAVE OUT OF ANSWERS` block of the compiled `system` string
   (`docs/build-specs/guidelines-compile.md`), which the composer obeys.
4. **Answer.** M1, `kn_engine_answer(namespace, question (aliases expanded: kn_engine_expand_aliases), project, history, system =
   the compiled answering, audience and exclusion guidelines, max_evidence, min_similarity = evidence_threshold)`. If the route is missing
   (`answer_unavailable`) **or the model call fails** (`model_failed`, `model_not_configured`, `model_api_key_missing`),
   `kn_engine_search()` answers with passages instead: `evidence_only` when any passage maps to the base (the code kept in `error`, the
   `message` saying why), else `no_evidence`.
5. **Mapping.** Each evidence item's `document.metadata {base, source, section, page, heading_path, position}` gives its section. A
   citation joins its evidence by `document_id`. If metadata is missing, `sections.maludb_document_id` is used. The section must belong
   to this base and must not be forgotten, or the item is dropped and counted in `dropped`.
6. **Row.** A `questions` row (door `mcp` for a person's token, `agent` under a run token, `share` for the kernel, `web` from the
   window, `api` from a key), its `citations` and the evidence go into the database, and the reply is returned.

| Status | When | `answer_md` | Evidence | A gap |
|---|---|---|---|---|
| `answered` | M1 composed, and its quotes are verified | yes | yes | no |
| `evidence_only` | the answer route is absent (before the M1 deploy), or the model call failed while passages exist (`error_code` `answer_unavailable` or the `model_*` code, with a `message` saying why). **Not pinnable**: nothing was composed | `null` | yes (≤ 12) | no |
| `no_evidence` | nothing passed the threshold, so no model was called (or the fallback found no passage) | `null` | `[]` | **yes** |
| `unverified` | M1 withheld an answer whose quote it could not verify, or none of its citations maps to a section of the base | `null` | yes | **yes** |
| `refused` | the base or the area is not built yet (`base_not_built`, `area_not_built`), or the engine would not take the question as sent | `null` | `[]` | no |
| `failed` | the engine cannot be reached or errs: `engine_unavailable`, `engine_timeout`, `engine_error` and the codes collapsed into it (stored with `error`; `error_code` in the reply). A gate refusal is not a status: it answers `not_found` or `invalid_argument` and stores nothing | `null` | `[]` | no |

**Logged** (D14): `question.ask` on every ask, source `mcp`, `agent`, `application`, `api` or `web`, with `entity = question`,
`base_id`, `token_id` (a key) and `after = {question_id, door, focus_area_id, conversation_id, turn_no, status, citation_count,
verified_count, latency_ms, error_code, question_excerpt (≤ 120 characters), key_label?, application?, eval?}`. Then the status event:
`question.answer` (answered, evidence_only, failed), `question.no_evidence`, `question.unverified` or `question.refuse`. **Never the answer,
an evidence text, a quote, a guideline or a key.**

### Keys, agents, proposals (A1–A4)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_keys` | Which keys exist for base X: limits, last use, today's count. `q` alone resolves a key by label | A1 | `mcp_keys` | `base?` (admin without base: every base, `keys.all`), `q?`, `live?` (default true), `limit` | rows `{key_id, base_id, label, focus_area_id, rate_per_minute, rate_per_day, calls_today, last_used_at, expires_at, revoked_at, rotated_from, is_live, minted_by_name, created_at}`. **Never a key's value or hash** (no view selects it) | curate |
| `base_readers` | Which agents and applications may read which bases | A2 | `mcp_base_members WHERE principal_kind IN ('agent', 'application')` | `base?`, `kind?` (agent, application), `limit` | rows `{base_id, base_name, principal_kind, principal_id, principal_ref, principal_name, level, created_at}` | curate (admin: all) |
| `archivist_proposals` | What the Archivist proposed, and what was accepted. `q` alone resolves a proposal by title | A3 | `mcp_archivist_proposals` | `base?`, `status?` (proposed, accepted, dismissed), `kind?` (area, merge, gap, stale_source, failed_source, review_pin, unassigned, unverified, feed_stopped, limit_near, url_changed), `q?`, `limit` | rows `{proposal_id, base_id, kind, subject_type, subject_id, title, reason, payload, status, proposed_by_name, decided_by, decided_at, created_at}` | curate |
| `agent_dispatches` | Which dispatches are pending or failed | A4 | `mcp_agent_dispatches` | `status?` (sent, answered, refused, failed, awaiting_approval), `agent?`, `base?`, `kind?` (ask, propose_areas, draft_guidelines, report), `limit` | rows `{dispatch_id, record_type, record_id, agent_name, kind, via, status, reply_excerpt (≤ 300), detail, attempts, run_id, request_id, base_id, build_id, created_at, answered_at, next_attempt_at}` | admin (`agents.settings`); otherwise the ones the caller caused, received or curates (the view) |

### People, notes, mine (names for the resolver; reach for the views no question names)
| Tool | Call it when | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|
| `find_members` | A person or an agent by name, e.g. to share a base. `q` alone resolves one | `mcp_members` | `q?`, `kind?` (human, agent), `department?`, `limit` | rows `{member_id, display_name, member_kind, is_agent, is_guest, job_title, roles, department_ids}`. `email` is only one's own | the view: insiders see the admitted, a guest the people on their bases |
| `find_departments` | A department by name, as a principal | `mcp_departments` | `q?`, `include_archived?`, `limit` | rows `{department_id, name, description, parent_id, archived_at}` | insider |
| `find_notes` | The notes on a base, a source or a section | `mcp_notes` | `record_type` (base, source, section), `record_id`, `limit` | rows `{note_id, member_name, body, created_at}` | reader |
| `my_notifications` | My notices, unread first, and my preferences | `mcp_notifications`, `mcp_notification_prefs` | `unread_only?`, `limit` | `{prefs, notifications: [{notification_id, kind, title, body, record_type, record_id, read_at, created_at}]}` | own |
| `my_tokens` | My own MCP tokens and the keys I minted: label, scope, base, last use | `mcp_access_tokens_mine` | `scope?` (mcp, api) | rows of the view. **Never a value or a hash** | own |

### The long tail
`records_search`: one read-only `SELECT`/`WITH` over the `mcp_*` views as the asking member. It is the contract's guarded search:
one statement with no `;`, the keyword deny-list, `SET LOCAL statement_timeout = '5s'`, wrapped `SELECT * FROM (…) _q LIMIT 200`, run
by `db.run_search` under `knowledge_records_ro`. The docstring lists the 31 readable views by name (db/004 `mcp_app_roles`, and the
30 of db/014 but `mcp_activity_log`) and the db/013 functions. **`SEARCH_DENY` adds `kn_section_markdown`** (it returns a forgotten
section's text: see Divergences) and keeps `mcp_admit_agent`, `mcp_resolve_token`, `set_config` and `pg_`. **It is not Q2:** the
evidence tool is `search`.

## The answer, the evidence and the bases: the three documents

**One citation shape everywhere** (MCP, K7, token API):
`{"n": 1, "quote": "…", "verified": true, "section": {"id": 412, "heading_path": "Handbook › Leave › Carry-over", "source": {"id": 37,
"title": "Staff Handbook 2026", "kind": "file"}, "page": 14}, "url": "https://knowledge.<domain>/sections/412#c1"}`. `url` is the
section view at that citation (absolute, from `APP_URL`; slice 2 fixes the route).
**One evidence shape**: `{"n", "text" (the passage the engine holds, ≤ 1,500 characters, part of the section), "similarity", "subject",
"verb", "section": {…as above}}`.

`os.knowledge-answer/1` (`ask`, the K7 `ask` share, `POST /api/v1/ask`, `GET /api/v1/questions/{id}`):
```json
{"schema": "os.knowledge-answer/1", "generated_at": "2026-10-05T14:02:11+00:00", "application": "knowledge",
 "base": {"key": "handbook", "name": "Staff Handbook"}, "focus_area": {"slug": "leave", "name": "Leave"} | null,
 "question_id": 9021 | null, "conversation_id": "c_5f…", "turn_no": 2,
 "status": "answered|evidence_only|no_evidence|unverified|refused|failed",
 "answer_md": "…" | null, "confidence": 0.82 | null,
 "citations": [ …citation… ], "evidence": [ …evidence… ], "dropped": 0,
 "guideline_version": 4, "message": "plain words for evidence_only, no_evidence, unverified, refused or failed" | null,
 "error_code": null | "answer_unavailable|model_failed|model_not_configured|model_api_key_missing|base_not_built|area_not_built|citations_unmapped|engine_unavailable|engine_timeout|engine_error"}
```
Caps: `answer_md` ≤ 16 kB, ≤ 12 evidence items, ≤ 12 citations. The largest document is well under 64 kB. There is no usage, no model,
no asker and no key in this document.

`os.knowledge-evidence/1` (`search`, the K7 `search` share, `POST /api/v1/search`):
`{"schema", "generated_at", "application": "knowledge", "base": {key, name}, "focus_area": {slug, name} | null, "query",
"status": "evidence_only|no_evidence|failed", "evidence": [ …evidence… ] (≤ 20), "subjects_tried": [names], "error_code": null | …}`.
Nothing is stored. `search` logs nothing for a person (a read). As a share it logs `share.read`. Through the API it logs
`question.search` under the key. Nothing beyond the `query_excerpt` (≤ 120 characters) is logged.

`os.knowledge-bases/1` (the K7 `bases_index` share, `GET /api/v1/bases`):
`{"schema", "generated_at", "application": "knowledge", "bases": [{"key", "name", "description" (≤ 500), "template", "language",
"status", "last_built_at", "source_count", "section_count", "areas": [{"slug", "name", "description" (≤ 300), "example_questions"
(≤ 5)}]}]}`. It is people-free: no owner, curator, member or key.

## The shares, for the kernel's token alone (K7, design §8)

**Who is asking comes from the kernel (K26, built 2026-10-06, kernel db/173).** The kernel's call carries two HTTP headers set from the
consumer's registration — `X-OS-Consumer` (its catalog key) and `X-OS-Consumer-Agent` (its expert agent's member id, when it has one) — and
strips any `consumer`, `consumer_agent_id` or `as_agent` the consumer put in the arguments. The records server's kernel-token gate reads the
headers into request-scoped variables (Phase 4) and passes `consumer` to the three share functions; a call with no `X-OS-Consumer` (not the
kernel's) answers `consumer_unknown` and shares nothing. `ask` and `search` declare `timeout_seconds: 60` in `maludb-os.json`; `bases_index` keeps 8.

| Tool | Document | Arguments (flat from the kernel) | Answers |
|---|---|---|---|
| `ask` | `os.knowledge-answer/1` | `base` (key), `question`, `focus_area?` (slug), `conversation_id?` — the consumer from the header `X-OS-Consumer` (K26), never an argument | `kn_ask()` as the application principal: door `share`, `application_key = consumer`, no member |
| `search` | `os.knowledge-evidence/1` | `base`, `query`, `focus_area?`, `limit?` (≤ 20) | evidence over that base |
| `bases_index` | `os.knowledge-bases/1` | `q?` (name ILIKE) | the bases shared with the consumer |

- **The gate is the application principal:** `base_members.principal_kind = 'application' AND principal_ref = consumer` through
  `kn_application_base_ids(consumer)` (db/006, archived bases excluded). A base not in that set answers `{"schema", "application",
  "error": {"code": "not_shared", "message": "No such base is shared with this application."}}`, in the same words as a missing base.
  It is a normal result, not `isError`, so the kernel passes it back rather than calling it `provider_failed`.
- **No member identity crosses**, in either direction. The answer names sections and sources, never a person. The question is stored
  with `application_key` and no asker, and a consumer's conversation turns are its own.
- **What the kernel passes and enforces today** (`application_read()`, `mcp_call_tool_as_kernel()`): it checks an approved
  connection, refuses an answer over **256 kB** (`APP_READ_MAX_BYTES`), and waits **8 s** per MCP post (`mcp_http_post` default),
  not 10 s. The shares are unscoped (`"scoped": false`), so no `scope_id` is sent. The bridge budgets a share's engine call at 7 s. An M1
  answer that would take longer comes back `failed`, `error_code: engine_timeout`, `message: "call search for the passages"`.
- **Logged:** `share.read` (source `application`, `after = {application, tool, base_id, outcome, ms}`) on every share call. A share
  `ask` also writes `question.ask` with `application`.

## The token API (bearer = a key; `/api/v1/` on the vhost's public allow-list; design §4, §8)

A key is an `mcp_access_tokens` row of scope `api` bound to ONE base (`kn_resolve_key(sha256)`). **A key is not its minter:** it
reaches its base and no other (`api_require_base` → `kn_key_reaches_base` and the minter's `can_read_base`; anything else is a 404).
**Every call except `/health` is counted first** by `kn_rate_ok(token_id)` (`api_rate_limit()`), whose defaults are 30 a minute and
2,000 a day. A refused call is never charged to the day. A browser session calling the API is not counted. Errors share one body,
`{"error": {"code", "message", …}}` (`json_error`). Server to server: CORS from the allow-list only, never `Allow-Credentials`.

| Call | Request | 200 response | Refusals |
|---|---|---|---|
| `POST /api/v1/ask` | `{"question": "…" (req, ≤ 2,000), "focus_area": "slug" \| id (opt), "conversation_id": "…" (opt, ≤ 120)}`. The base is the key's (a session caller sends `"base"`) | `os.knowledge-answer/1`: `question_id`, `status`, `answer_md`, `confidence`, `citations[{n, section: {id, heading_path, source, page}, quote, verified, url}]`, `evidence[]`, `conversation_id`, … | 401, 403 `area_not_allowed` (the key is bound to another area), 404, 422 `invalid` (`fields`), 429, **503 `engine_unavailable` / `engine_timeout`** (a `failed` answer, with `question_id` in the error) |
| `POST /api/v1/search` | `{"query": "…" (req, ≤ 2,000), "limit": 1–20 (8), "focus_area": … (opt)}` | `os.knowledge-evidence/1` | 401, 403, 404, 422, 429, 503 |
| `GET /api/v1/bases` | none | `os.knowledge-bases/1` with the key's one base | 401, 429 |
| `GET /api/v1/questions/{id}` | none | `os.knowledge-answer/1` of a question asked under this key, or the key it was rotated from | 401, 404, 429 |
| `POST /api/v1/questions/{id}/feedback` | `{"rating": "up" \| "down" (req), "comment": "…" (opt, ≤ 2,000)}` | `{"question_id", "rating", "comment", "updated_at"}`. One rating per question per key: it replaces | 401, 404, 422, 429 |
| `GET /api/v1/health` | none, no auth | `{"ok", "application": "knowledge", "version", "database", "maludb", "ingest_lag", "directory", "engine": "ok" \| "unavailable" \| "answer_unavailable"}`. `engine` is added by slice 7 from `kn_engine_available()` | 503 when the database is down |

- **429** comes from `kn_rate_ok`: `{"error": {"code": "rate_limited", "message": "Too many calls (minute).", "limit": "minute" \| "day"}}`
  with `Retry-After` = `retry_after` seconds (≥ 1), logged `key.rate_limited` (`after = {limit_hit, key_label}`).
- **401** has one body for everything: no key, a wrong key, a revoked or expired key (`kn_rate_ok` `no_key`/`revoked`), an `mcp`
  token, or a minter no longer admitted. `{"error": {"code": "unauthorized", "message": "A valid API key is required."}}`.
- **Logged**, source `api` with `token_id`: `question.ask` (as above, `key_label` in `after`), `question.search`, `question.feedback`.
  The key itself is never logged.

## Activity server: `knowledge_activity_mcp`

| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `record_history` | Who did what to a record, in order: a base, a source, a section, a guideline, an area, a build, a question, a key, a feed, a proposal | ACT1 | `mcp_activity_log` | `entity_type` (base, source, section, guideline, vocabulary_term, focus_area, build, question, pinned_answer, key, feed, proposal, dispatch), `entity_id`, `limit` (≤ 200) | rows `{activity_id, occurred_at, actor_name, actor_is_agent, source, action, before, after, request_id, agent_run_id}`, oldest first | the view: rows of readable bases, one's own, the admin all |
| `actor_timeline` | What person, key or agent X did here since a time | ACT2 | `mcp_activity_log` | `member?` or `key?` (one of them), `since?` (7 days), `base?`, `limit` | rows as above + `base_id`, newest first | own trail: anyone. Another person's or agent's, or a key's: only rows on bases the caller curates (`kn_curated_base_ids()`). The admin: all |
| `recent_activity` | What changed since I last looked, across what I may see | ACT3 | `mcp_activity_log` | `since` (required), `base?`, `exclude_mine?` (true), `limit` | rows + `base_id`, newest first | the view |
| `share_reads` | Which siblings read us, how often, with what outcome | ACT4 | `mcp_activity_log WHERE action = 'share.read'` | `since?` (30 days), `application?`, `group?` (true: by application and tool) | grouped `{application, tool, calls, refused, last_at}` or rows | admin |
| `activity_search` | The trail by a phrase in actions, record titles, labels and names | ACT5 | `mcp_activity_log` (`action`, `entity_type`, `after->>'title'`, `->>'name'`, `->>'label'`, `->>'key_label'`, `->>'question_excerpt'`) | `q` (2–200), `since?`, `base?`, `limit` | rows | the view |
| `activity_query` | The contract's guarded long-tail search: one `SELECT` over `mcp_activity_log` and the five name views | — | as listed | `sql` | ≤ 200 rows, 5 s | the view |

### The activity vocabulary (design §6.4): what the log must carry for these tools (the manifest's log-payload rules)
Every row: `actor_member_id` (null for a key's minter-less or a share's), `source` (`web`, `agent`, `assistant`, `mcp`, `api`,
`application`, `cron`), `action` (`entity.verb`), `entity_type` + `entity_id`, **`base_id` whenever the event concerns a base**
(the visibility test reads it), and `token_id` for a key's call. **Never a source's, a section's, an answer's, an evidence's or a
guideline's text, never more than 120 characters of a question, never a key, a hash or a MaluDB token.**
- `base.create|update|archive|delete`: `after.name`, `key`, `template`, `visibility`, the fields changed. `base.visibility_set`:
  `before`/`after.visibility`. `base.share|share_guest|share_application|unshare`: `principal_kind`, `principal_id` or `principal_ref`,
  `level` (a guest and an application have their own events because they pause for an agent and `base.share` does not).
  `base.admin_view`: `base_id`.
- `source.add|replace`: `source_id`, `kind`, `title`, `mime_type`, `byte_size`, `origin` (a URL's host and path; a file's name).
  `source.text_extracted`: `char_count`, `page_count`. `source.no_text|fail`: `error_code`. `source.sectioned`: `sections_count`.
  `source.remove|forget`: `sections_forgotten`.
- `section.stale|extract|fail`: `section_id`, `source_id`, `extracted_version`, `build_id`, `error_code`. No text.
- `guideline.save|delete|version`: `guideline_id`, `kind`, `title`, `version_no`, `shapes_graph`, `base_guideline_version`. **Never
  the body.** `vocabulary.save|sync`: `term_id` or `count`, `canonical_name`.
- `focus_area.save|propose|accept|dismiss|delete`: `focus_area_id`, `name`, `slug`, `status`, `proposed_by`.
- `build.start|progress|done|fail|cancel|rebuild_all`: `build_id`, `kind`, `items_total`, `items_done`, `items_failed`,
  `chars_processed`, `guideline_version`, `error_code`.
- `question.ask` (see `ask`), `question.answer|no_evidence|unverified|refuse`: `question_id`, `status`, `citation_count`.
  `question.search` (API): `query_excerpt` ≤ 120. `question.feedback`: `question_id`, `rating`, `has_comment`.
  `question.pin|unpin`: `question_id`, `pinned_answer_id`, `review_until`. `question.purge`: `count`, `older_than`.
- `key.mint|save|rotate|revoke`: `key_id`, `label`, `focus_area_id`, `rate_per_minute`, `rate_per_day`, `rotated_from`.
  `key.rate_limited`: `limit_hit`, `key_label`.
- `feed.save|sync|fail|delete`: `feed_id`, `provider`, `tool`, `items`, `last_status` (`feed.delete`: the sources forgotten). `archivist.propose|accept|dismiss`: `proposal_id`,
  `kind`, `title`. `agent.dispatch|reply|fail`: `dispatch_id`, `agent_member_id`, `kind`, `via`, `run_id`, `status`, `reply_length`.
- `settings.save`, `limits.save`, `retention.set`, `agents_settings.save`: the fields changed. `model_config.save`: `model_alias`, `embedding_model`, `namespace` (never a credential).
  `share.read` (see the shares). `token.mint|revoke`: `label`, `scope`. `member.sign_on`, `directory.sync`, `screen.view`.
- Added by the slice specs (each named in its spec's divergences): `base.restore`, `source.retry|update|url_changed`,
  `vocabulary.delete`, `focus_area.assign`, `notification.send`, `agent.dispatch|reply|fail` from a person's request, and the paused
  actions' own events above (`base.share_guest`, `base.share_application`, `feed.delete`, `limits.save`, `retention.set`,
  `agents_settings.save`). `docs/knowledge-action-manifest.md` names every action's event.

## Entity resolution for the action tools (the registry's `resolve` block)
Each tool answers the resolver's plain list when called with `q` alone: digits are an id, otherwise ILIKE with at most 6 candidates.

| Param(s) | Tool (with `q`) | id field | label |
|---|---|---|---|
| `base` | `find_bases` | `base_id` | `name` (`key`) |
| `focus_area`, `area` | `find_focus_areas` | `focus_area_id` | `name` (base name) |
| `source` | `find_sources` | `source_id` | `title` (base name) |
| `guideline` | `find_guidelines` | `guideline_id` | `title` (kind) |
| `term` | `find_vocabulary` | `term_id` | `canonical_name` |
| `question` | `my_questions` | `question_id` | `question` (first 80 characters) |
| `pinned_answer`, `pin` | `pinned_answers` | `pinned_answer_id` | `title` |
| `api_key` (an API key; a base's own `key` param is its slug, never resolved) | `find_keys` | `key_id` | `label` |
| `proposal` | `archivist_proposals` | `proposal_id` | `title` |
| `feed` | `find_feeds` | `feed_id` | `provider.tool` |
| `member`, `person`, `owner`, `agent`, `guest` | `find_members` | `member_id` | `display_name` |
| `department` | `find_departments` | `department_id` | `name` |
| `template` | `find_templates` | `template_key` | `name` |
| `section`, `build`, `dispatch`, `citation`, `note`, `attachment`, `notification`, `token`, `community`, `subject` | **ids only**, never resolved by name (read from `source_outline`, `find_builds`, `agent_dispatches`, `get_question`, `find_notes`, `get_source`, `my_notifications`, `my_tokens`, `communities`, `get_subject`) | | |

## Coverage

**Design §7 → tools**
| # | Tools |
|---|---|
| B1 | `find_bases`, `get_base` · B2 `base_members` · B3 `get_settings`, `find_templates` |
| S1 | `find_sources`, `get_source` · S2 `source_outline`, `get_section`, `source_page`, `source_excerpt` · S3 `section_graph`, `source_graph` · S4 `sections_for_subject` · S5 `find_feeds` |
| G1 | `find_guidelines`, `guideline_versions` · G2 `extraction_preview` · G3 `find_vocabulary`, `unused_terms` · G4 `find_focus_areas`, `get_focus_area` · G5 `unassigned_sources`, `empty_areas` |
| L1 | `build_progress`, `find_builds` · L2 `build_estimate` · L3 `base_usage` |
| K1 | `graph_stats` · K2 `communities`, `community_members` · K3 `get_subject`, `subject_neighbors`, `subject_statements` · K4 `graph_path` · K5 `god_nodes`, `surprises` · K6 `graph_query` · K7 `duplicate_subjects` |
| Q1 | `ask` · Q2 `search` · Q3 `suggested_questions` · Q4 `my_questions`, `get_question` · Q5 `questions`, `question_feedback` · Q6 `question_gaps` · Q7 `pinned_answers`, `pins_due` |
| A1 | `find_keys` · A2 `base_readers` · A3 `archivist_proposals` · A4 `agent_dispatches` |
| ACT1 | `record_history` · ACT2 `actor_timeline` · ACT3 `recent_activity` · ACT4 `share_reads` · ACT5 `activity_search` |
| contract | `app_roles`, `records_search`, `activity_query`, the shares `ask`/`search`/`bases_index` |

**Design §3 rights → the read tools they open** (the action tools are the manifest's; a right never widens reach beyond the base level)
| Right | Read tools |
|---|---|
| `bases.read` | every `reader` tool: `find_bases`, `get_base`, `find_sources`, `get_source`, `source_outline`, `get_section`, `source_page`, `source_excerpt`, `section_graph`, `source_graph`, `sections_for_subject`, `find_focus_areas`, `get_focus_area`, the graph tools but `duplicate_subjects`, `ask`, `search`, `suggested_questions`, `pinned_answers`, `pins_due`, `find_notes` |
| `answers.rate` | none (the action); `my_rating` on `my_questions` / `get_question` |
| `questions.own` | `my_questions`, `get_question`, `ask` with `conversation_id` |
| `bases.create`, `sources.add` | none (actions) |
| `base.manage` | `find_guidelines`, `guideline_versions`, `extraction_preview`, `find_vocabulary`, `unused_terms`, `unassigned_sources`, `empty_areas`, `duplicate_subjects` (on curated bases) |
| `sources.manage` | `find_sources` with `include_forgotten`; `get_section`'s engine id |
| `builds.run` | `build_progress`, `find_builds`, `build_estimate`, `base_usage` |
| `base.share` | `base_members`, `base_readers`, `find_members`, `find_departments` |
| `keys.manage` | `find_keys` (its bases) · `keys.all`: `find_keys` with no base |
| `answers.pin` | `pinned_answers`, `pins_due` (the curator's view of review dates) |
| `questions.review` | `questions`, `question_feedback`, `question_gaps`, `get_question` of another asker |
| `feeds.manage` | `find_feeds` |
| `settings.manage`, `limits.manage`, `retention.manage` | `get_settings` (every field), `share_reads` |
| `model.manage` | `get_settings`' `model_alias`, `embedding_model` (the view's `kn_is_admin()`) |
| `costs.read` | `base_usage` with no base, `usage` on `find_builds` / `get_question` |
| `agents.settings` | `agent_dispatches` (all), `archivist_proposals` (all bases) |

**No view unreachable.** db/004 + db/014 define 31 `mcp_*` views. Each is read by a named tool (as well as by `records_search`):
`mcp_app_roles` → `app_roles` · `mcp_members` → `find_members` · `mcp_departments` → `find_departments` · `mcp_settings` → `get_settings` ·
`mcp_templates` → `find_templates` · `mcp_bases` → `find_bases` · `mcp_base_members` → `base_members`, `base_readers` · `mcp_sources` →
`find_sources`, `get_source` · `mcp_source_pages` → `get_source`, `source_page` · `mcp_sections` → `get_section`, `source_page`,
`source_excerpt` · `mcp_attachments` → `get_source` · `mcp_notes` → `find_notes` · `mcp_guidelines` → `find_guidelines` ·
`mcp_guideline_versions` → `guideline_versions` · `mcp_vocabulary_terms` → `find_vocabulary` · `mcp_focus_areas` → `find_focus_areas`,
`get_focus_area` · `mcp_source_focus_areas` → `get_focus_area`, `find_sources` (`area`) · `mcp_builds` → `find_builds` ·
`mcp_build_items` → `build_progress` (`items`) · `mcp_questions` → `my_questions`, `get_question` · `mcp_citations` → `get_question` ·
`mcp_question_feedback` → `question_feedback` · `mcp_pinned_answers` → `pinned_answers`, `pins_due` · `mcp_keys` → `find_keys` ·
`mcp_feeds` → `find_feeds` · `mcp_ai_usage` → `base_usage` · `mcp_agent_dispatches` → `agent_dispatches` · `mcp_archivist_proposals`
→ `archivist_proposals` · `mcp_notifications`, `mcp_notification_prefs` → `my_notifications` · `mcp_access_tokens_mine` → `my_tokens` ·
`mcp_activity_log` → the activity server. Every db/013 function has a tool: `kn_base_overview` → `get_base`, `kn_source_outline` →
`source_outline`, `kn_section_markdown` → `get_section`, `kn_questions` → `questions`/`my_questions`, `kn_question_gaps` →
`question_gaps`, `kn_build_progress` → `build_progress`, `kn_build_estimate` → `build_estimate`, `kn_usage` → `base_usage`,
`kn_unassigned_sources`, `kn_empty_areas`, `kn_unused_terms` → their namesakes.

## Divergences from the design

1. **The engine bridge.** The design names graph, answer and preview tools on the records server, but the MaluDB token is readable in
   `engine.php` alone and `kn_ask()` writes a question, and neither is possible from a Python read role. *Resolution:* the records
   server posts to `/internal/bridge.php` on the internal port (HMAC over the body with `ACTIONS_RELAY_KEY`, prefix `kn-bridge:`,
   loopback, ±30 s). PHP applies the same reach functions and calls `engine.php` / `kn_ask()`. The read servers still never write.
   No new env key is needed.
2. **`search` is not the guarded search.** Design §7 says "one guarded `search` on each server (the records one IS Q2)". Q2 is the
   engine's evidence and cannot be a SQL tool. *Resolution:* `search` stays Q2 and the K7 share under its fixed name. The contract's
   guarded SELECT is `records_search`. On the activity server ACT5 keeps `activity_search` (a phrase search), and the guarded SELECT is
   `activity_query`. That makes six activity tools, as the design counts them.
3. **The K7 shares know their consumer from the kernel's headers (K26, built 2026-10-06).** `application_read()` once passed a provider nothing
   but the arguments and the site, so `kn_application_base_ids()` could not be applied. *Resolution, as built:* the kernel sends `X-OS-Consumer`
   and `X-OS-Consumer-Agent` on every message of its call (headers, not arguments — the siblings' argument models forbid extras) and strips a
   consumer-supplied `consumer`, `consumer_agent_id` or `as_agent`; a share declares `timeout_seconds` (`ask` and `search`: 60). The gate reads
   the headers beside the token; **a call with no `X-OS-Consumer` answers `{"error": {"code": "consumer_unknown"}}`: fail closed, never all bases.**
4. **`graph_stats` is base-confined, not the engine's.** `graph/stats` is tenant-wide and would leak the size of other bases.
   *Resolution:* the cached counts on `knowledge_bases`, the live document count from the engine and the confined god nodes.
5. **`duplicate_subjects` has no engine route.** No MaluDB route lists a namespace's subjects. *Resolution:* the bridge walks up to
   200 of the base's documents' neighbours, groups them by a normalised name and the vocabulary's aliases, caches the result for
   10 min and reports `documents_read` / `documents_total`.
6. **`communities` usually answers empty** (M6). It returns what the engine holds, with a note. The expert proposes areas from
   `god_nodes` and `graph_query` (design §0).
7. **`source_page` returns the sections on the page, and `source_excerpt` stays inside one section.** `source_text` is deliberately in
   no `mcp_*` view, and a section's text is trimmed, so a page cannot be clipped exactly. This also keeps the rule that no text
   reaches a reader beyond a section.
8. **Support tools not named in §7:** `find_members`, `find_departments` (the `base_share` resolver), `find_notes`,
   `my_notifications`, `my_tokens` (views no question names). Records tools come to **61**, against the design's estimate of
   "about 42".
9. **`ask` under `is_eval` persists nothing** (see the servers). The design says only "`is_eval` refuses every write". A question row
   is a write, and evaluating answers is what evals are for.
10. **One tool shares its name with an action**: `question_feedback` reads here and the manifest's `question_feedback` writes. They
    live on different servers, and the harness prefixes the server, so no rename.

## What the schema lacks that a tool needs (db/ not modified)

- **`kn_section_markdown()` returns a forgotten section's text** (db/013 concatenates `s.text` whatever the status, while
  `mcp_sections` blanks it). `get_section` reads the status first and never calls it for a forgotten section, and `records_search`
  denies the function. *Proposed:* an additive `db/015` replaces the function so that a forgotten section yields its heading and
  `(forgotten)`.
- **No feedback by a key:** `question_feedback.member_id` is NOT NULL and UNIQUE per (question, member). `POST
  /api/v1/questions/{id}/feedback` records the rating under the key's minter, one per question per key, replacing. A curator sees the
  minter's name with the key's label beside it on `question_feedback`. That is acceptable for v1. A `token_id` column would be the
  additive fix.
- **No read function lists an application's bases with their fields for a role without a member** (`kn_application_base_ids` gives
  ids only, and `mcp_bases` needs `app.member_id`). `bases_index` is therefore served by the bridge reading as `knowledge_rw`. An
  additive `kn_application_bases(app_key)` would let the records role answer it directly. It is not needed.
- **`mcp_questions` exposes `usage` and `evidence` to the asker.** The named tools trim `usage` to curators. `records_search` can read
  a caller's own questions' token counts, which are tokens and never dollars. This is accepted.
- **`mcp/db.py`'s `SEARCH_DENY` carries Help Desk's `kn_secure_link_` leftover** and lacks `kn_section_markdown`. Phase 4 fixes the
  list. This is code, not schema.

## Size
Records: **61 tools** (53 from §7, `app_roles`, `records_search`, `bases_index`, and 5 support tools), of which 3 are shares (`ask`
and `search` are shared with people and agents, and `bases_index` is the kernel's alone) and 18 call the engine through the bridge.
Activity: **6 tools**. The resolve block names 13 entities. There are 3 share documents and 6 token-API calls. Phase 4 builds them as
`mcp/records_server.py` with `kn_bases`, `kn_sources`, `kn_guidelines`, `kn_builds`, `kn_graph`, `kn_asking` and `kn_admin` (each
`register(mcp, q, bridge)`), plus `mcp/activity_server.py`, over the views and functions the screens read and the one bridge into
`engine.php` and `kn_ask()`.

## Open questions
(none)

## Phase 4 — built and proven (2026-10-10)

The two servers are built as the document specifies: `mcp/records_server.py` (**61 tools**: 58 from the tables above, `app_roles`, `records_search`, `bases_index`; 60 are offered to a person or an agent, `bases_index`
only to the kernel's token) over `mcp/kn_{bases,sources,guidelines,builds,graph,asking,admin}.py` (each `register(mcp, q, bridge)`, shared pieces in `kn_common.py`), and `mcp/activity_server.py` (**6 tools**).
`mcp/server_common.py` is the gate (the three token shapes, the run-facts call, fail closed, the kernel's headers); `mcp/db.py` gained the K26 context variables. `tests/phase4/run.sh` proves them (see "Proof"). No migration
was needed (db/ is unchanged); one bridge op was added (`preview`). Decisions taken while building, each a small choice the surface left open or a correction of a sentence that no longer matched the code:

1. **`extraction_preview` needed a bridge op the PHP side did not have.** `preview` is added to `app/knowledge/bridge.php` (`kn_bridge_preview`): a member caller, the section readable (`mcp_sections`), the base *curated*
   (`curators_only` otherwise), at most 10 drafts of `{kind, title, body ≤ 4,000}`; **a draft whose kind and title match a saved guideline replaces it, any other is added** ("replacing or adding to the saved ones of that
   kind"); nothing is saved. `documents_prompt` is the compiled prompt (`kn_compile_prompt_from`) with the section in place of `{{chunk}}`; `generic_prompt` is the engine's own dry run (`kn_engine_preview`) or `null`
   with `engine: engine_unavailable|engine_timeout|engine_error` when the engine cannot give it; `hints[]` come from the vocabulary and the section's areas' seed terms; `lines` (EXTRACT/TERM/EXCLUDE counts) are added.
   `tests/phase3/slice5/bridge.php` asserted that `preview` was an unknown op; it now uses `nonesuch`.
2. **`graph_path` has no `max_depth`.** The PHP graph module (`graph_path()`) fixes the engine's search at depth 6 and Phases 2–3 are not changed; a parameter that is silently ignored would mislead an agent. The description says
   "The search depth is fixed at 6 steps".
3. **`get_question` is built in Python** from `mcp_questions` + `mcp_citations` + the caller's `mcp_question_feedback` row (as the surface says), with the same sentences as `app/features/ask/present.php`
   (`ask_status_words`, the engine's error words) — and `tests/phase4/asking.php` compares it field for field with the PHP presenter's document for an answered, a `no_evidence`, an `evidence_only` and a `failed` question.
4. **`source_excerpt`, quote not found:** `found: false`, `before` and `match` empty, and the section's first `2 × window` characters in `after`. The citation form (`question` + `n`) reads `mcp_citations.rank`.
5. **The kernel's flat arguments:** `server_common` drops `consumer`, `consumer_agent_id` and `as_agent` (the three names the kernel strips) before wrapping the rest in `params`; the models stay `extra="forbid"`. The consumer
   is the header alone: a call without a valid `X-OS-Consumer` (`[a-z0-9_]{1,60}`) reaches the share functions as `null` and answers `consumer_unknown` as a normal result, logged `share.read`. A person's token carrying the
   header is still a person (door `mcp`).
6. **Waits.** The surface said a share waits 7 s; since K26 the kernel waits 60 s for `ask` and `search` and PHP budgets the engine at 50 s, so the Python side waits **55 s** for `share_ask` / `share_search`, 7 s for
   `share_bases`, **65 s** for a person's `ask` and 35 s for `search` (the surface said 25 s for both; the engine's own limits are 60 s for a route that calls the model — `KN_ENGINE_MODEL_TIMEOUT` — and 30 s for one that does not, and a Python wait shorter than the engine's would answer `engine_timeout` for a question PHP then stores anyway — a deviation from the approved text, **confirmed by the planning model 2026-10-10**: a wait shorter than the engine's reports a failure for an answer PHP then writes, so an agent retries, the question is stored twice and paid twice; the paragraph above is corrected to match), 20 s for `graph_query` and `duplicate_subjects`, 15 s for anything else. A timeout of an engine op is `engine_timeout` (retry), of any other `bridge_unavailable`.
7. **The bridge's error words.** PHP's `invalid` is `invalid_argument` (with `fields`); `not_found`, `curators_only`, `not_in_base`, `no_path_in_base`, `engine_unavailable`, `engine_timeout`, `engine_error` (with `detail`) pass
   through; a 401, an Apache 5xx or anything unrecognised is `bridge_unavailable`. `ask` and `search` answers (which are documents, even when `failed`) pass through verbatim.
8. **`records_search` / `activity_query`:** `SEARCH_DENY` now carries `kn_section_markdown`, `kn_application_base` (both `kn_application_bases` and `kn_application_base_ids`: db/015 lets the records role call the first,
   and it names the private bases shared with an application), `kn_base_curator_ids`, `kn_record_base_id`, `kn_key_calls_today`, `kn_key_reaches_base`; Help Desk's leftover is gone. A refusal is the one error shape
   (`{ok:false, error:{code:"invalid_argument", …}}`), not Help Desk's `{error: "…"}`.
9. **Reach choices the surface left to the tool.** `find_keys` without a base needs the `keys.all` right (or `q`); `find_builds` without a base is the admin's; `base_usage` without a base needs `costs.read`;
   `find_sources` shows forgotten sources only on bases the caller curates (silently, with `include_forgotten`); `get_base` calls the bridge `admin_view` only for an admin reading a private base;
   `get_section` and `get_focus_area` add the engine's ids (`maludb_document_id`, `maludb_project_id`) only for a curator.
10. **`suggested_questions`** is the PHP `suggest` op (the areas' example questions, then the pinned answers' questions — the screens' own list) plus, when there is room and the engine is up, "What does the base say
    about <subject>?" from the base's five most connected subjects (`god_nodes`, confined). **`share_reads.refused`** counts the outcomes `not_shared`, `consumer_unknown` and `invalid`.
11. **`find_feeds` with `q` alone** answers `{feed_id, tool: "provider.tool", base_id}` (the wrapper's `label_field` is `tool`); `my_questions` with `q` alone answers `{question_id, question (80), base_id}`.
12. **A parameter that fails validation is an answer** — `{ok:false, error:{code:"invalid_argument", fields:[…]}}`, a normal result, naming the parameters (`question`, `draft`, …) and never echoing what was sent (`server_common.invalid_argument`).
13. **Process.** The servers are started by the proofs *without* `MALUDB_KB_API_TOKEN` in their environment and work: the Python side never holds it, never inserts a question and holds no write privilege (the roles have none).

**Proof** — `tests/phase4/run.sh` (a scratch database `kn_dev_p4`, the application under `php -S`, or `KN_APP=apache` under a real Apache; the fake kernel, the fake engine, the two servers on :8504/:8505 from `mcp/venv`;
every call through initialize, `tools/list`, `tools/call`): `gate` (the surface; the token shapes; agents under run facts, fail closed, first contact; the kernel's token; an eval run), `bridge` (the door, `preview`, `ask` with two
citations, the graph, the engine down, the door closed), `wall` (what each kind of person reads; curators only; forgotten words; no key or hash; the guarded search), `reads`, `asking` (`get_question` equals the PHP
document), `shares` (with and without `X-OS-Consumer`), `activity`, `registry` (the kernel's own `application_actions` loader, resolver and tool factory; an agent's `key_mint` pauses; the Archivist holds two actions
and none that mints, deletes or shares), `coverage` (every tool called; every view and read function read). 519 checks (62 + 68 + 98 + 78 + 43 + 31 + 51 + 76 + 12), the same under `php -S` and under a real Apache; the registry proof is the kernel's own code, imported read-only.
