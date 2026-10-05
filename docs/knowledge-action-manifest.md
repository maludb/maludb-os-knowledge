# Knowledge Action Manifest

2026-10-05 · **Phase 1, for the checkpoint**

> The registry that the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve against.
> A screen or action missing here cannot be reached by voice or by an agent. That is unfinished design, like a question with no tool.
> Pairs with `db/` (tables), `docs/knowledge-mcp-tool-surface.md` (read tools) and the ten slice specs in `docs/build-specs/`.
> `bin/build_action_registry.php` generates `mcp/action_registry.json` from this file. An action whose file does not exist yet is
> registered but not exposed. **The approval categories here ARE `maludb-os.json` `approvals[]`**: `php bin/build_action_registry.php
> --approvals` rewrites that block from this file, and `--check` (in every proof) fails when the registry or the approvals are stale.
> The installer reads the resolve block from `deploy/kernel-registry-knowledge.json`.

## Conventions

### Screens
- **Ids** are kebab-case: `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-knowledge.conf` (and `tests/dev_router.php`): `/{x}/new` → `form.php`,
  `/{x}/{id}/edit` → `form.php?id=`, `/{x}/{id}` → `view.php?id=`, `/{x}/{id}/{page}` → `{page}.php?id=`. A base's own pages are
  `/bases/{id}/{page}` → `html/bases/{page}.php`. Every id is an integer. The ask window and a section are read at 375 px first. The
  graph explorer is designed at 1280 px and works at 375. No screen is a modal.
- **Prefill params** become query-string values that the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity` and `data-record-id` on `#page-content`, so "this base", "pin that"
  and "share it with Priya" resolve against the page the user is on.
- **Doors that are not screens** have no row here: the token API (`/api/v1/*`, a key's), the engine bridge (`/internal/bridge.php`,
  loopback only), the polling partials (`/bases/{id}/sources-status`, `/builds/{id}/progress`, `/questions/{id}/expert-status`,
  `/home/builds`), `/guidelines/preview.php` (a read that writes nothing and logs nothing), `/files/{id}`, `/sso` and `/sso/logout`.

### Actions
- **Name** `{entity}_{verb}`. The **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with `base_id`
  on every row that concerns a base). It is also what the kernel's approval policies match (`approval_policies.action_pattern` = the
  registry's `log_event`). So **an action that pauses for an agent has a log event no free action shares**: `base.share_guest` and
  `base.share_application` beside the free `base.share`, `feed.delete` beside the free `feed.save`, `limits.save`, `retention.set`
  and `agents_settings.save` beside `settings.save`.
- **Endpoints** are POST to `/{base}/{file}` with `kn_handler_begin()` (`require_post()`, `require_login()`, `verify_csrf()` or the
  relayed action token), the right (`require_right()`), the base's reach (`require_base_right()`: `none` → 404, `read` → 403, `own`
  allowed, `curate` allowed with the right), `kn_guard()` around the database's verb, `log_activity()`, `emit_action_status()` and
  `kn_done()`. PHP never re-implements a rule the database keeps (a namespace set once, one build per base, a key bound to one base,
  one open proposal per subject). A create's `location` ends in the new record's id.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the last
  parameter). A param named for a record accepts an id or a name, resolved through the application's own records tool (the resolve
  block in `deploy/kernel-registry-knowledge.json`, the tool surface's resolution table): `base`, `source`, `guideline`, `term`,
  `focus_area` and `area`, `question`, `pinned_answer`, `api_key`, `proposal`, `feed`, `member`, `guest`, `expert`, `archivist`,
  `department`, `template`. `section`, `build`, `base_member`, `dispatch`, `notification`, `token` and `subject_id` are ids only.
  `any field of X` means X's fields, all optional: a partial update.
- **Who** is enforced in the endpoint: `own` = the record's own person (their questions, notifications, tokens); a right
  (`bases.create`, `base.manage`, `sources.add`, `sources.manage`, `builds.run`, `base.share`, `keys.manage`, `answers.rate`,
  `answers.pin`, `questions.review`, `feeds.manage`, `settings.manage`, `limits.manage`, `retention.manage`, `model.manage`,
  `agents.settings`, `bases.read`) is held through `kn_has_right()`. **`curate`** means the right applies only on a base where the
  caller is the owner, holds a `curate` or `own` share, or is the admin (`require_base_right()`). An owner who holds only Member
  manages their own base. A super-admin holds every right. An agent is granted like a person.
- **Undo** is the inverse `undo_last` applies. `—` means it cannot be undone, and the reply says so.
- **Confirm (✔)** = the command bar asks first (destructive, it reaches people or systems outside the base, or it spends).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it (design §5): `external_send`
  (content reaches someone outside, or a system: sharing with a guest or an application, making a base internal, minting or rotating
  a key), `deletion` (deleting a base, removing a source, deleting a guideline, a term, an area or a feed, revoking a key, purging
  questions), `other` (configuration and spend: removing someone from a base, the settings, the limits, retention, the model, the
  named agents, rebuilding everything). People are never paused. **Asking, reading, adding sources, writing guidelines, proposing
  areas and building under the limit are free.** The categories are exactly `maludb-os.json` `approvals[]`.
- **Agent** says whether the endpoint admits an agent's token at all: `yes`, or `people` (the handler calls `require_human()`, and
  an agent's call answers 403). An action that pauses is still `yes`: the pause comes first, then the call.
- **record_id** is what the JSON answer's `record_id` names: the record made (a create) or the record acted on.
- **Refresh:** a data action returns `HX-Trigger: {entity}Changed` (camelCase entity).
- **Log payloads** (what `before`/`after` must carry) are in the tool surface's activity vocabulary. **A source's, a section's, an
  answer's, an evidence passage's or a guideline's text, a description's words, a feedback comment, more than 120 characters of a
  question, a key, a hash or a MaluDB token are never in a payload** (ids, titles, names, counts, kinds, statuses and error codes are).

### Result contract
```json
{"status": "success", "did": "Saved — version 4: 212 sections need a rebuild", "record_id": 88, "undo_id": "act_31", "refresh": "guidelinesChanged"}
{"status": "pending_approval", "did": "Minting a key for \"Field Manuals\" waits for a person's approval", "approval_request_id": 17}
{"status": "error", "message": "A build is already running on \"Field Manuals\"."}
```

## Home, me and the shell (Phase 2 and slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `home` | `/` | home: a new person's one button (Create a knowledge base); otherwise my bases, builds running, my recent questions, pins due for review, the Archivist's last note; a curator's gaps and thumbs-down this week; the admin's usage, failed builds, pending dispatches and open proposals |
| `activity` | `/activity` | my own activity trail, or a record's history (params: `base`, `entity_type`, `entity_id`, `since`) |
| `notifications` | `/notifications` | what the bell holds, and to mark it read (params: `unread`) |
| `settings` | `/settings/` | how I am told: email, texts, which kinds, my time zone |
| `tokens` | `/settings/tokens/` | my own MCP tokens: mint one, revoke one |

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `prefs_save` | `prefs.php` | email_enabled (yes or no), text_enabled (yes or no), kinds[], text_kinds[] | restore prior | | | `prefs.save` | own | yes | the member |
| `notification_read` | `notifications/read.php` | notification (empty marks all) | — | | | `notification.read` | own | yes | the notification |
| `token_mint` | `tokens/mint.php` | **label**, scope (mcp) | token_revoke | | | `token.mint` | own | people | the token |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own | yes | the token |

## Bases, templates, sharing and settings (slice 1)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `base-list` | `/bases` | the bases I may read as cards: status, visibility, my level, sources and sections, last built, needs a rebuild (params: `q`, `status`, `mine`, `archived`) |
| `base-add` | `/bases/new` | to make a knowledge base: a name and a template, done (params: `template`) |
| `base-view` | `/bases/{id}` | a base at a glance: description, counts, areas, the last build, the curators, an ask box; an empty base's first action is Add documents |
| `base-edit` | `/bases/{id}/edit` | to change a base's name, key, description or template (the template only while it is empty) |
| `base-share` | `/bases/{id}/share` | who may read or curate a base and why; share with a person, an agent, a department, a guest or an application; make it internal |
| `base-settings` | `/bases/{id}/settings` | a base's auto-extract, sensitivity, language and monthly text limit |
| `admin-settings` | `/admin/settings` | the application's settings, limits and retention, each its own form |
| `templates` | `/admin/templates` | the templates and the guidelines each ships, read-only |

Actions (base `/bases/`; the admin's forms name their files absolutely):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `base_create` | `save.php` | **name**, template (a template key — general by default), description, key (lower-case letters digits and dashes — blank gives kb-id) | base_archive | | | `base.create` | bases.create | yes | the base |
| `base_update` | `save.php` | **base**, any field of base_create | restore prior | | | `base.update` | base.manage on a curated base | yes | the base |
| `base_settings_save` | `settings-save.php` | **base**, auto_extract (yes or no), sensitivity (standard or confidential or restricted), language (an ISO code such as en), monthly_text_mb_limit (megabytes — limits.manage only) | restore prior | | | `base.update` | base.manage on a curated base | yes | the base |
| `base_visibility_set` | `visibility.php` | **base**, **visibility** (private or internal) | restore prior | ✔ | external_send | `base.visibility_set` | base.manage on a curated base | yes | the base |
| `base_share` | `members/add.php` | **base**, member (a person or an agent — never a guest) or department, level (read or curate or own — own from the owner or an admin) | base_member_remove | | | `base.share` | base.share on a curated base | yes | the share row |
| `base_share_guest` | `members/add-guest.php` | **base**, **guest** (an external member holding Reader — reads only) | base_member_remove | ✔ | external_send | `base.share_guest` | base.share on a curated base | yes | the share row |
| `base_share_application` | `members/add-application.php` | **base**, **application** (a catalog key such as helpdesk — reads only) | base_member_remove | ✔ | external_send | `base.share_application` | base.share on a curated base | yes | the share row |
| `base_member_remove` | `members/remove.php` | **base**, **base_member** (the share row from base_members — never the everyone row) | — | ✔ | other | `base.unshare` | base.share on a curated base | yes | the share row |
| `base_archive` | `archive.php` | **base** (stays readable and takes no new source or build) | base_restore | ✔ | | `base.archive` | base.manage on a curated base | yes | the base |
| `base_restore` | `restore.php` | **base** | base_archive | | | `base.restore` | base.manage on a curated base | yes | the base |
| `base_delete` | `delete.php` | **base**, **confirm_name** (the base's name typed) | — | ✔ | deletion | `base.delete` | base.manage at own level | yes | the base |
| `settings_save` | `/admin/settings/save.php` | business_name, url_fetch_allowed (yes or no), evidence_threshold (0 to 1), max_evidence (1 to 50), max_question_chars (100 to 20000), conversation_turns (0 to 20), pin_review_months (1 or 3 or 6 or 12), section_target_chars, section_max_chars, limit_warn_pct (1 to 100), timezone | restore prior | | other | `settings.save` | settings.manage | yes | the settings |
| `limits_save` | `/admin/settings/limits.php` | default_monthly_text_mb (1 to 100000), max_source_bytes, url_fetch_max_bytes, key_rate_per_minute, key_rate_per_day, key_rotation_overlap_hours (0 to 168) | restore prior | | other | `limits.save` | limits.manage | yes | the settings |
| `retention_set` | `/admin/settings/retention.php` | **question_retention_days** (1 to 3650) | restore prior | ✔ | other | `retention.set` | retention.manage | yes | the settings |

## Sources and sectioning — THE FIRST EXEMPLAR (slice 2)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `sources` | `/bases/{id}/sources` | a base's sources as cards: kind, status, sections, pages, areas, stale or failed or no text (params: `kind`, `status`, `area`, `q`) |
| `source-add` | `/sources/new` | to add documents: drop many files, paste text or add a page by its address (params: `base`, `tab`) |
| `source-view` | `/sources/{id}` | one source: origin, status and its error in words, the outline, the pages, its areas, what it taught the graph; edit, replace, remove, retry |
| `source-edit` | `/sources/{id}/edit` | to change a source's title or language |
| `source-replace` | `/sources/{id}/replace` | to replace a source with a new version: its sections are forgotten and the new one is read afresh, its areas kept |
| `section-view` | `/sections/{id}` | one section: its heading path, its words, its page, previous and next; a citation's quote marked (params: `quote`, `back`) |

Actions (base `/sources/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `source_add` | `add.php` | **base**, **kind** (file or text or url — an agent sends text or url), title (up to 300 characters), text (the pasted text up to 2 MB), url (one public page), files[] (multipart up to 20 a request — people only) | source_remove | | | `source.add` | sources.add on a curated base | yes | the first source (records: every id) |
| `source_update` | `update.php` | **source**, title (up to 300 characters), language (an ISO code) | restore prior | | | `source.update` | sources.manage on a curated base | yes | the source |
| `source_replace` | `replace.php` | **source** (a live source not fed by a feed), **kind** (file or text or url), title, text, url, files[] | — | ✔ | | `source.replace` | sources.manage on a curated base | yes | the new source |
| `source_remove` | `remove.php` | **source** (its sections are forgotten in the engine within the minute) | — | ✔ | deletion | `source.remove` | sources.manage on a curated base | yes | the source |
| `source_retry` | `retry.php` | **source** (a failed one) | — | | | `source.retry` | sources.add on a curated base | yes | the source |

## Guidelines, vocabulary and focus areas (slice 3)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `guidelines` | `/bases/{id}/guidelines` | a base's guidelines by the five kinds, the version the graph is built under, what needs a rebuild |
| `guideline-add` | `/guidelines/new` | to write a guideline beside its kind's examples and preview it on a section (params: `base`, `kind`, `section`) |
| `guideline-edit` | `/guidelines/{id}/edit` | to change a guideline and preview it on a section (params: `section`) |
| `guideline-view` | `/guidelines/{id}` | a guideline's versions and the one the graph was built under |
| `guidelines-compiled` | `/bases/{id}/compiled` | what the engine is told: the compiled extraction prompt, the answer guidelines, the terms |
| `vocabulary` | `/bases/{id}/vocabulary` | a base's terms, aliases and types, alias conflicts and the terms the graph never met (params: `q`, `never`) |
| `term-add` | `/vocabulary/new` | to add a vocabulary term with its aliases and type (params: `base`, `canonical_name`, `aliases`) |
| `term-edit` | `/vocabulary/{id}/edit` | to change or delete a vocabulary term |
| `vocabulary-import` | `/bases/{id}/vocabulary-import` | to import terms from a CSV: check first, then import |
| `areas` | `/bases/{id}/areas` | a base's focus areas as cards; the proposed ones to accept or dismiss; sources in no area and areas with no source |
| `area-add` | `/areas/new` | to name a focus area: description, seed terms, example questions (params: `base`) |
| `area-edit` | `/areas/{id}/edit` | to change a focus area |
| `area-view` | `/areas/{id}` | one focus area: its terms, example questions, sources and its engine link |

Actions (base `/guidelines/`; the vocabulary, the areas and a source's areas name their files absolutely):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `guideline_save` | `save.php` | **base**, **kind** (extraction or vocabulary or exclusion or answering or audience), **body** (up to 20000 characters), title (up to 200 characters), is_active (yes or no — an agent's draft is no), position, guideline (to change an existing one) | restore prior | | | `guideline.save` | base.manage on a curated base | yes | the guideline |
| `guideline_delete` | `delete.php` | **guideline** | — | ✔ | deletion | `guideline.delete` | base.manage on a curated base | yes | the guideline |
| `vocabulary_save` | `/vocabulary/save.php` | **base**, **canonical_name** (up to 200 characters), aliases[] (at most 20), subject_type (lower-case letters and underscores), description (up to 2000 characters), term (to change an existing one) | restore prior | | | `vocabulary.save` | base.manage on a curated base | yes | the term |
| `vocabulary_delete` | `/vocabulary/delete.php` | **term** | — | ✔ | deletion | `vocabulary.delete` | base.manage on a curated base | yes | the term |
| `vocabulary_import` | `/vocabulary/import.php` | **base**, **csv** (the CSV text or file — at most 500 rows), **step** (check writes nothing and apply writes) | — | ✔ | | `vocabulary.save` | base.manage on a curated base | yes | the base |
| `focus_area_save` | `/areas/save.php` | **base**, **name** (up to 120 characters), description (it becomes the area's classifier), seed_terms[] (at most 30), example_questions[] (at most 10), position, status (active or archived), focus_area (to change an existing one) | restore prior | | | `focus_area.save` | base.manage on a curated base | yes | the area |
| `focus_area_propose` | `/areas/propose.php` | **base**, **name**, **proposal_note** (why — up to 1000 characters), description, seed_terms[], example_questions[] | focus_area_dismiss | | | `focus_area.propose` | base.manage on a curated base | yes | the area |
| `focus_area_accept` | `/areas/accept.php` | **focus_area** (a proposed one) | — | | | `focus_area.accept` | base.manage on a curated base | yes | the area |
| `focus_area_dismiss` | `/areas/dismiss.php` | **focus_area** (a proposed one) | — | | | `focus_area.dismiss` | base.manage on a curated base | yes | the area |
| `focus_area_link` | `/areas/link.php` | **focus_area** (links it in the engine now) | — | | | `focus_area.save` | base.manage on a curated base | yes | the area |
| `focus_area_delete` | `/areas/delete.php` | **focus_area** (its engine project is removed first) | — | ✔ | deletion | `focus_area.delete` | base.manage on a curated base | yes | the area |
| `focus_area_assign` | `/areas/assign.php` | **focus_area**, **source**, assigned (yes or no — yes by default) | focus_area_assign | | | `focus_area.assign` | base.manage on a curated base | yes | the area |
| `source_areas_set` | `/sources/areas.php` | **source**, areas[] (the full set of its areas — ids) | restore prior | | | `focus_area.assign` | base.manage on a curated base | yes | the source |

## The build (slice 4)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `builds` | `/bases/{id}/builds` | what a build would do now against the month's limit, start it, rebuild everything, the builds so far |
| `build-view` | `/builds/{id}` | one build's progress, counts, usage, failed sections with their reasons, the items; cancel |

Actions (base `/builds/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `build_start` | `start.php` | **base** (new and stale and failed sections — an agent's build must fit the month) | build_cancel | | | `build.start` | builds.run on a curated base | yes | the build |
| `build_rebuild_all` | `rebuild-all.php` | **base**, **confirm** (yes — every live section is forgotten and read again) | build_cancel | ✔ | other | `build.rebuild_all` | builds.run on a curated base | yes | the build |
| `build_cancel` | `cancel.php` | **build** (a queued or running one) | — | ✔ | | `build.cancel` | builds.run on a curated base | yes | the build |

## Ask — THE SECOND EXEMPLAR (slice 5)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `ask` | `/ask` | to ask a base a question and get an answer with citations that open their sections, or a plain nothing on this (params: `base`, `area`, `c`, `q`) |
| `conversations` | `/ask/conversations` | my conversations with the bases (params: `base`) |
| `question-view` | `/questions/{id}` | one question in full: the answer, its citations, the evidence, the feedback, pin or unpin |
| `questions` | `/bases/{id}/questions` | the base's question log for curators: who or which key asked, answered or not, rated how (params: `status`, `door`, `key`, `area`, `asker`, `since`, `q`, `page`) |
| `gaps` | `/bases/{id}/gaps` | what the base could not answer, clustered, and the thumbs-down answers (params: `since`) |
| `pinned` | `/pinned` | the pinned answers across my bases and which are due for review (params: `base`, `due`) |

Actions (base `/questions/`; the window and the pins name their files absolutely):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `question_ask` | `/ask/send.php` | **base**, **question** (1 to 2000 characters), area (a focus area of the base), c (the conversation to continue) | — | | | `question.ask` | bases.read and the base's reach | yes | the question |
| `question_feedback` | `feedback.php` | **question**, **rating** (up or down), comment (up to 2000 characters) | question_feedback | | | `question.feedback` | answers.rate and the question visible | yes | the question |
| `answer_pin` | `pin.php` | **question** (an answered one — an evidence_only answer has no words to pin), title (up to 300 characters) | answer_unpin | | | `question.pin` | answers.pin on a curated base | yes | the pinned answer |
| `answer_unpin` | `/pinned/unpin.php` | **pinned_answer** (an active one — its note source is forgotten) | — | ✔ | | `question.unpin` | answers.pin on a curated base | people | the pinned answer |
| `answer_pin_review` | `/pinned/review.php` | **pinned_answer** (verified again from today) | — | | | `question.pin` | answers.pin on a curated base | yes | the pinned answer |
| `question_expert` | `expert.php` | **question** (an evidence_only one of mine or of a base I curate) | — | | | `agent.dispatch` | bases.read and the question visible | people | the dispatch |

## The graph explorer (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `graph` | `/bases/{id}/graph` | a base's graph: its size, the most connected subjects, communities when MaluDB has them, surprising links, a search; a curator's duplicates (params: `q`) |
| `subject-view` | `/bases/{id}/subject` | one subject: its statements, neighbours and the sections behind it; ask about it (params: `name`) |
| `community-view` | `/bases/{id}/community` | one community and its members (params: `community`) |
| `path` | `/bases/{id}/path` | how two subjects are related inside the base (params: `from`, `to`) |

No actions: Merge opens `term-add` prefilled (`vocabulary_save`), and Ask about this opens `ask` prefilled.

## The token API, keys and K7 (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `keys` | `/bases/{id}/keys` | a base's API keys: label, area, limits, today's count, last use; mint, edit, rotate, revoke |
| `key-add` | `/keys/new` | to mint an API key for a base: label, area, limits (params: `base`) |
| `key-edit` | `/keys/{id}/edit` | to change a key's label, area or limits |
| `connections` | `/admin/connections` | which sibling applications read which bases, the shares and the reads, the last share calls; the K26 notice while the kernel does not name the consumer |

Actions (base `/keys/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `key_mint` | `mint.php` | **base**, **label** (what will use the key — up to 80 characters), focus_area (an active area of the base — empty for the whole base), rate_per_minute (blank for the default), rate_per_day (blank for the default) | key_revoke | ✔ | external_send | `key.mint` | keys.manage on a curated base | yes | the key (its value once, as key) |
| `key_save` | `save.php` | **api_key**, label, focus_area, rate_per_minute, rate_per_day | restore prior | | | `key.save` | keys.manage on a curated base | yes | the key |
| `key_rotate` | `rotate.php` | **api_key** (a live one — the old key keeps working for the overlap) | — | ✔ | external_send | `key.rotate` | keys.manage on a curated base | yes | the new key (its value once, as key) |
| `key_revoke` | `revoke.php` | **api_key** | — | ✔ | deletion | `key.revoke` | keys.manage on a curated base | yes | the key |

## Agents, proposals and feeds (slice 8)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `proposals` | `/bases/{id}/proposals` | the Archivist's proposals for a base: accept with its effect or dismiss (params: `status`, `kind`) |
| `admin-proposals` | `/admin/proposals` | every base's proposals (params: `base`, `status`, `kind`) |
| `feeds` | `/bases/{id}/feeds` | the feeds a base follows from sibling applications and how each last synced |
| `feed-add` | `/feeds/new` | to follow a feed: a Spaces space or Help Desk's published articles (params: `base`) |
| `feed-edit` | `/feeds/{id}/edit` | to change a feed's arguments, area, interval or whether it is enabled |
| `dispatches` | `/admin/dispatches` | every request to the agents: pending, answered, refused, failed; retry a failed one (params: `status`, `kind`, `base`) |

Actions (base `/proposals/`; the note, the feeds and the dispatches name their files absolutely):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `archivist_propose` | `save.php` | **base**, **kind** (area or merge or gap or stale_source or failed_source or review_pin or unassigned or unverified or feed_stopped or limit_near or url_changed), **title** (up to 300 characters), **reason**, subject_type (source or question or pinned_answer or focus_area or feed or base), subject_id, payload (a JSON object up to 16 KB) | archivist_proposal_dismiss | | | `archivist.propose` | the base's reach | yes | the proposal |
| `archivist_proposal_accept` | `accept.php` | **proposal** (a proposed one — its effect runs), groups[] (a merge's ticked groups by number), name (an area's name as edited), description, seed_terms[], example_questions[] | — | ✔ | | `archivist.accept` | questions.review on a curated base | people | the proposal |
| `archivist_proposal_dismiss` | `dismiss.php` | **proposal**, note (why — up to 500 characters) | — | | | `archivist.dismiss` | questions.review on a curated base | people | the proposal |
| `notification` | `/notifications/send.php` | **base**, **title** (up to 200 characters), **body** (Markdown up to 4000 characters), kind (monday_note) | — | | | `notification.send` | an agent reading the base or settings.manage | yes | the base |
| `feed_save` | `/feeds/save.php` | **base**, **provider** (spaces or helpdesk), **tool** (pages_index or articles_published), arguments (a JSON object such as the space key), focus_area, every_minutes (5 to 10080 — 60 by default), enabled (yes or no), feed (to change an existing one) | restore prior | | | `feed.save` | feeds.manage on a curated base | yes | the feed |
| `feed_sync` | `/feeds/sync.php` | **feed** (synced on the next worker pass) | — | | | `feed.sync` | feeds.manage on a curated base | yes | the feed |
| `feed_delete` | `/feeds/delete.php` | **feed** (its sources are forgotten first) | — | ✔ | deletion | `feed.delete` | feeds.manage on a curated base | yes | the feed |
| `dispatch_retry` | `/admin/dispatches/retry.php` | **dispatch** (a failed or refused one) | — | | | `agent.dispatch` | agents.settings | yes | the dispatch |
| `areas_propose_request` | `/agents/propose-areas.php` | **base** (one with an extracted section) | — | | | `agent.dispatch` | base.manage on a curated base | people | the dispatch |
| `guidelines_draft_request` | `/agents/draft-guidelines.php` | **base** (one with an extracted section) | — | | | `agent.dispatch` | base.manage on a curated base | people | the dispatch |

## Home, admin, usage and tokens (slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `admin` | `/admin/` | the admin area: settings, templates, the model, usage, every base, every key, the agents, connections, proposals, dispatches, purge |
| `admin-purge` | `/admin/purge` | to purge questions older than N days now, after seeing how many would go (params: `days`) |
| `admin-model` | `/admin/model` | the engine's model and embedding model, its health, a base's namespace; change the model; test a base's prompt on a section (params: `base`) |
| `admin-usage` | `/admin/usage` | the text extracted and the engine calls by base and kind for a month (params: `month`, `base`) |
| `admin-bases` | `/admin/bases` | every base, the private ones marked (opening one logs the admin's look) (params: `visibility`, `status`, `archived`) |
| `admin-keys` | `/admin/keys` | every API key of every base; revoke (params: `base`, `status`) |
| `admin-agents` | `/admin/agents` | the agents this application declares, the agent members here, which is the expert and which the Archivist, their dispatches |

Actions (base `/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `question_purge` | `purge.php` | **days** (1 to 3650 — the retention setting by default), **confirm** (yes — answers behind an active pin are kept) | — | ✔ | deletion | `question.purge` | retention.manage | yes | the settings |
| `model_config_save` | `model.php` | model_alias (the provider's model id), embedding_model, rebuild_confirmed (yes — required when the embedding model changes) | restore prior | ✔ | other | `model_config.save` | model.manage | yes | the settings |
| `agents_settings_save` | `agents.php` | expert (an agent member — empty for none), archivist (an agent member — empty for none) | restore prior | | other | `agents_settings.save` | agents.settings | yes | the settings |
