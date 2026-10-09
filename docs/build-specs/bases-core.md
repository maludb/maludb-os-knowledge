# Build spec: bases, templates, sharing and settings — THE CRUD EXEMPLAR (slice 1)

What exists at the end: a member makes a knowledge base in one step — a name and a template, done — and lands on it with **"Add
documents"** as its first action; they own it and curate it; they share it with people, departments and agents to read or to curate,
with a guest or a sibling application (both pause for an agent), or with everyone inside by making it internal (pauses for an agent);
they rename it, give it a key, switch its template while it is empty, set its extraction and sensitivity, archive it, restore it,
delete it (its engine documents forgotten first). A Knowledge admin sees every base, private ones included — logged — and keeps the
application's settings, limits and retention. Every screen is cards for named things and a full-page form; every save is a handler on
the kit's shape; every row is logged. This slice fixes the shape every later CRUD screen copies.

Schema (db/001–014, never modified): `knowledge_bases`, `base_members`, `kn_base_before_insert()`, `kn_base_before_update()`,
`kn_base_members_before()`, `kn_base_visibility_after()`, `kn_visible_base_ids()`, `kn_curated_base_ids()`, `kn_can_read_base()`,
`kn_can_curate_base()`, `kn_base_level()`, `kn_application_base_ids()` (db/006); `base_templates`, `kn_settings`, `kn_setting_int()`
(db/005); `guidelines`, `kn_base_copy_template()`, `kn_guidelines_after()`, `kn_graph_shape_changed()` (db/008); `builds` (kind
`forget`, db/009); `kn_base_overview()` (db/013); `kn_notify()`, `kn_share_notify()`, `kn_base_curator_ids()` (db/012);
`kn_has_right()`, `kn_is_admin()`, `kn_member_is_guest()` (db/004); the views `mcp_bases`, `mcp_base_members`, `mcp_templates`,
`mcp_settings`, `mcp_members`, `mcp_departments`, `mcp_activity_log` (db/014). **The database is the referee**: a namespace set once as
`kb:<id>`, a new base private, the key unique among live bases, a template switched only while empty, the `everyone` row following
visibility and never written by hand, an application principal reading only, the settings' bounds — the handlers call the write inside
`kn_guard()` and present a `P0001`/`23505`/`23514` as its sentence on the field; PHP decides nothing twice.

## Divergences from the design
1. **Switching template replaces only the untouched shipped guidelines.** db/008 copies the template's set into `guidelines` once (origin
   `template`); db/006 allows a switch only while the base is empty but does not re-copy. **Resolution:** the switch (in one transaction
   with the update) deletes the base's guidelines with `origin = 'template' AND version_no = 1` and inserts the new template's set with
   `origin = 'template'` (the same INSERT as `kn_base_copy_template()`); guidelines a person wrote or edited stay. Deleting a shipped
   extraction or exclusion guideline bumps `guideline_version` (the row trigger) — harmless on an empty base, and recorded.
2. **Deleting a base forgets first.** `knowledge_bases` cascades to `sections`, so a hard delete would lose the `maludb_document_id`s the
   engine must be told to forget. There is no "delete requested" column. **Resolution:** `base_delete` (one transaction) renames the key to
   `deleting-<id>`, marks every live source `forgotten` (their sections follow, ids kept), queues a **`forget` build** (db/009 allows the
   kind; `kn_base_recompute_status()` ignores it) and archives the base. When nothing of the base remains in the engine the worker deletes
   the row (slice 2 builds that step, `kn_worker_purge_bases()`; slice 3 extends it to the areas' projects). A base that never reached the
   engine (no section with a document id, no area with a project) is deleted at once by the handler. A key beginning `deleting-` is refused
   on the form.
3. **`base_visibility_set` pauses in both directions** for an agent: the manifest's approval is per action key (`external_send`); making a
   base private again is rare and harmless to pause.
4. **The admin's Settings, Limits and Retention are built here** (the brief); slice 9 builds the admin index, the model screen
   (`model_config_save`, which needs the engine's reference namespace — `guidelines-compile.md`), usage and the rest. Until slice 9 the
   admin reaches `/admin/settings` from the Bases list.
5. **Events beyond design §6.3**: `base.restore` (the archive's undo). Base settings changes log `base.update` with `after.form = 'settings'`.

## Who may do what — one rule for every base-scoped write (`require_base_right()`, added here, used by every later slice)
`require_base_right(int $baseId, string $right)`: `kn_base_level($baseId)` — `none` → 404 "Knowledge base not found." (a base one may not
read does not exist to one); `read` → 403 "You may not change this knowledge base."; `own` (the owner, or an admin) → allowed; `curate` →
allowed only with `has_right($right)`, else 403 in the right's words (`require_right()`'s). So an owner who holds only Member (`user`)
manages their own base (design §3: "a base of my own — I am its curator"), and a curate-level share acts with the Curator role's rights.

| Action | Right | Level |
|---|---|---|
| create | `bases.create` | — |
| update (name, key, description, template), settings, visibility, archive, restore | `base.manage` | curate |
| share, unshare | `base.share` | curate |
| delete | `base.manage` | **own** (the owner or an admin) |
| the base's monthly limit (on base-settings) | `limits.manage` (admin) | any |
| admin settings · limits · retention | `settings.manage` · `limits.manage` · `retention.manage` | — |

## Screens (375 px first; cards for bases and templates; a table for who may read; full-page forms; no modals)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `base-list` | `/bases?q=&status=&mine=&archived=` | the bases I may read as cards (`mcp_bases`): name, status chip, visibility chip (private `dark` / internal `light`), my level chip (own / curate / read), sources · sections, last built ("never"), "needs a rebuild" `warning` when `stale_count > 0`, a building bar when `running_build_id`; **Create a knowledge base** for a holder of `bases.create`; filters: search, status, Mine (own or curate), Archived; an admin sees every base and a card of a private base they do not belong to carries `admin view` `danger` (no log on the list — the log is on opening); an admin also sees a **Settings** link (`/admin/settings`) until slice 9's admin index exists |
| `base-add` | `/bases/new` | **the first run**: Name (autofocus), Template as cards (`mcp_templates` active ones, `general` preselected; each card: name, description, its kinds of shipped guideline), **Done**. A `<details>` "More" holds description and key (blank = `kb-<id>`). Nothing else: visibility is private, extraction automatic |
| `base-view` | `/bases/{id}` | `kn_base_overview()`: name, description, status chip, visibility chip, the owner and the curators (`curators`), counts (sources by status, sections, characters), the areas as chips (`areas`), the last build (`last_build`), an **ask box** (a GET form to `/ask?base={id}` — slice 5's screen); **an empty base (no live source) shows one primary button, "Add documents"** (→ `/sources/new?base={id}`, slice 2) above everything else; the base's tabs (below); for a curator also: guidelines by kind, vocabulary count, keys, open proposals, gaps, the build estimate; Edit · Share · Settings · Archive/Restore · Delete by my level; the timeline (`base_timeline()`) |
| `base-edit` | `/bases/{id}/edit` | name, key (pattern shown; `deleting-…` refused), description, template (a select — **disabled with the sentence "A base switches template only while it is empty" once it holds a source**; the database refuses anyway) |
| `base-share` | `/bases/{id}/share` | the table of who may read or curate (`mcp_base_members`: principal name with kind chip — person, department, agent `info`, guest `warning`, application `secondary`, everyone `light` —, level, granted by, when) with **Change level** (a select per row, posts `base_share`) and **Remove** (not the everyone row, not the owner); below, four full-width add forms: **A person or an agent** (a record picker over `members_for_pick()` minus guests — source `person`, design-system `record-picker.md`; never a select of everyone —, level read · curate · own — own only for the owner/admin), **A department** (`find_live_departments()`, read · curate), **A guest** (external Readers from `guests_for_pick()`, chosen through the record picker (source `guest`), read only, with the line "a guest sees only the bases named to them"), **An application** (a catalog key — a text field `[a-z0-9_]{1,60}` with a datalist of the known siblings `helpdesk`, `projects`, `spaces`, `consultant_tracking` — read only, with the line "the kernel must also approve a connection from that application"); and **Visibility** (private / internal, one line on each: "internal = everyone inside the business may read; guests never"); a Reader → 403 |
| `base-settings` | `/bases/{id}/settings` | auto-extract new sources (on by default), sensitivity (standard · confidential · restricted, one line each: "passed to the engine with every call"), language (an ISO code, `en`), the monthly text limit in MB (read-only, "the admin sets it", for a curator; editable for a holder of `limits.manage`), the namespace (read-only `kb:<id>`), the guideline version (read-only) |
| `admin-settings` | `/admin/settings` | three forms on one page, each its own action: **Settings** (`settings.manage`: business name, URL fetching allowed, evidence threshold, evidence per answer, question length, conversation turns, pin review months, section target and maximum characters, the "limit nearly spent" percentage, time zone) · **Limits** (`limits.manage`: default monthly MB per base, largest file, largest fetched page, key rate per minute and per day, key rotation overlap hours) · **Retention** (`retention.manage`: question retention days); each field shows its bounds (the CHECKs of db/005) |
| `templates` | `/admin/templates` | the templates as cards, read-only (`mcp_templates`): name, key, version, description, the shipped guidelines by kind (title + body), the subject types, the area rule |

**The base's tabs** (`BASE_TABS` in `app/features/bases/present.php`, one row per tab: label, path, file, who): Ask (`/ask?base=`,
slice 5), Sources (`/bases/{id}/sources`, slice 2), Areas (`/bases/{id}/areas`, 3), Guidelines (`/bases/{id}/guidelines`, 3, curators),
Builds (`/bases/{id}/builds`, 4, curators), Graph (6), Questions (5), Keys (7, curators), Share (here, curators), Settings (here,
curators). **A tab is shown only when its file exists** (`is_file(APP_ROOT . '/html/…')`) and the person's level allows it — so a later
slice lights its tab by existing, and no tab ever 404s. At 1280 the tabs are a row under the header; at 375 a horizontally scrolling pill
row inside the page (never the viewport).

## Making and changing a base
- **Create** (`base_create`): `require_right('bases.create')` (a guest or a Reader fails here, in words). INSERT with `key = ''` (the
  trigger gives `kb-<id>`, the namespace `kb:<id>`, the template version, the monthly limit from the settings, status `empty`, visibility
  private) and `owner_member_id = me`; the template's guidelines arrive by trigger. Then `kn_compile_send()` **best-effort** when
  `app/knowledge/compile.php` exists (slice 3; until then skipped) — never refusing the create. Location `/bases/{id}`.
- **Update** (`base_update`, the same handler with `base`): name, key, description, template. A field left out keeps its value (the
  partial-update target `/bases/save.php`). An archived base → "Base "X" is archived: restore it first." The key's uniqueness (`23505` on
  `knowledge_bases_key_live`) → the key field: "Another base already uses the key x." The template switch is divergence 1, in the same
  transaction; refused by the trigger once a source exists → the template field, the database's words.
- **Settings** (`base_settings_save`): auto-extract, sensitivity, language (the CHECK's pattern), and — only from a holder of
  `limits.manage` — the monthly limit (a curator posting it → 403 "You may not set the limits."). Logged `base.update` with
  `after.form = 'settings'`.
- **Visibility** (`base_visibility_set`): private ↔ internal; the trigger writes or removes the `everyone` row. **external_send** for agents.
- **Archive** (`base_archive`) / **Restore** (`base_restore`): `archived_at` set / cleared. An archived base stays readable; the database
  refuses its new sources and builds in its own words; the card shows `archived` `dark`. Restoring when its key was taken meanwhile → the
  key field's sentence (`23505`) and "change its key first". A base being deleted (a live `forget` build) cannot be restored: "This base is
  being deleted."
- **Delete** (`base_delete`): own level, a confirm with the base's name typed; refused while an `extract`/`rebuild_all` build is queued or
  running ("A build is running: cancel it first"). Divergence 2. `did` says "Deleted" or "Forgetting 340 sections in the engine — the base
  disappears when that finishes"; location `/bases`. **deletion** for agents.

## Sharing
- **Share** (`base_share`, `/bases/members/add.php`): `principal_kind` member (a person who is **not** a guest), agent, or department;
  level read · curate (· own from the owner or an admin). An existing row for the principal → its level changes (UPDATE; `after.changed`).
  A guest named here → 422 "Share with a guest under A guest — an agent's request waits for a person's approval." The database refuses a
  person as an agent and the reverse, an unknown member or department (`foreign_key_violation` → the field). The person or the department's
  members are told (`kn_share_notify()` trigger, kind `shared`).
- **Share with a guest** (`base_share_guest`, `/bases/members/add-guest.php`): a member for whom `mcp_members.is_guest` is true; level
  **read** only ("A guest reads; they never curate."). Told (`shared`). **external_send** for agents.
- **Share with an application** (`base_share_application`, `/bases/members/add-application.php`): `principal_ref` = a catalog key; level
  read (the CHECK refuses curate in its words). Nobody is told (an application is not a person). **external_send** for agents. The K7 shares
  answer from `kn_application_base_ids()` (slice 7).
- **Remove** (`base_member_remove`, `/bases/members/remove.php`): a `base_members` row of the base; the everyone row → the trigger's
  sentence ("set visibility to private instead"); the owner is not a row (owner transfer is out of scope). **other** for agents.

## The admin's look at a private base (`base.admin_view`)
`base_from_request(PDO): array` (in `app/features/bases/handler.php`, used by **every** base-scoped screen of every slice) loads the base
from `mcp_bases` (none → 404 "Knowledge base not found."), and when the caller `is_kn_admin()`, the base is **private**, and
`base_reach_without_admin($pdo, $baseId, $me)` is false (not the owner; no `base_members` row naming them, an agent they are, or one of
their departments), it logs `base.admin_view` (entity base, `after: {base_id, screen}`) **once per request** (a static flag). An internal
base, one's own, or one shared with one is never logged.

## Files (exactly these)
- `html/bases/index.php` (`base-list`) · `form.php` (`base-add`, `base-edit`) · `view.php` (`base-view`) · `share.php` (`base-share`) ·
  `settings.php` (`base-settings`) · `save.php` · `settings-save.php` · `visibility.php` · `archive.php` · `restore.php` · `delete.php`
- `html/bases/members/add.php` · `add-guest.php` · `add-application.php` · `remove.php`
- `html/admin/settings.php` (`admin-settings`) · `templates.php` (`templates`) · `html/admin/settings/save.php` · `limits.php` · `retention.php`
- `app/features/bases/{queries,present,write,handler}.php` (`handler.php`: `base_from_request()`, `require_base_right()`, `base_log()` —
  `base_id` on every row —, the admin-view log)
- `app/features/admin/{queries,write}.php` (the settings; slice 9 adds to them)
- `app/views/bases/{index,form,view,share,settings,partials/base-card,partials/base-tabs,partials/member-row,partials/template-card,partials/counts}.php`
- `app/views/admin/{settings,templates}.php`
- `tests/phase3/slice1/{run.sh,lib.php,bases.php,share.php,visibility.php,settings.php,lifecycle.php,json.php,browser.mjs}`

## Query functions (signatures fixed; reads through `mcp_*` and the read functions only)
- `find_bases(PDO, array $filters, int $limit = 100): array` (`mcp_bases`; `q` on name, `status`, `mine` = `my_level IN (own, curate)`,
  `archived` — live only by default; ordered by `updated_at DESC`) · `find_base(PDO, int $id): ?array` (`mcp_bases`)
- `base_overview(PDO, int $id): ?array` (`kn_base_overview()` decoded) · `base_members_list(PDO, int $baseId): array` (`mcp_base_members`,
  the everyone row first, then by kind and name) · `base_timeline(PDO, int $baseId, int $limit = 50): array` (`mcp_activity_log` by `base_id`,
  through `activity_sentence()`)
- `find_templates(PDO, bool $activeOnly = true): array` (`mcp_templates` by `sort_order`) · `find_template(PDO, string $key): ?array`
- `guests_for_pick(PDO): array` (`mcp_members` where `is_guest`) · `people_and_agents_for_pick(PDO): array` (`members_for_pick()` minus guests)
- `base_reach_without_admin(PDO, int $baseId, int $memberId): bool` (one query on `knowledge_bases`/`base_members`/`department_members`)
- `base_has_live_source(PDO, int $baseId): bool` · `base_engine_footprint(PDO, int $baseId): array` (`['documents' => sections with a
  maludb_document_id, 'projects' => areas with a maludb_project_id]`)
- `find_kn_settings(PDO): array` (`mcp_settings`)

## Writes (`app/features/bases/write.php`, `app/features/admin/write.php`; base tables, after the gate)
- `create_base(PDO, array $fields, int $by): int` · `update_base(PDO, int $id, array $fields, int $by): array` (`kn_diff()`; the template
  switch inside) · `switch_template(PDO, int $id, string $template): array` (`['removed' => n, 'added' => n]`)
- `save_base_settings(PDO, int $id, array $fields, bool $mayLimit, int $by): array` · `set_base_visibility(PDO, int $id, string $v, int $by): array`
- `share_base(PDO, int $baseId, string $kind, ?int $principalId, ?string $ref, string $level, int $by): array` (`['base_member_id', 'changed']`)
  · `unshare_base(PDO, int $baseId, int $baseMemberId, int $by): array`
- `archive_base(PDO, int $id, bool $archive, int $by): void` · `delete_base(PDO, int $id, int $by): array` (`['phase' => 'deleted'|'forgetting',
  'sections' => n, 'sources' => n, 'build_id' => ?int]`; deletes the attachments' files when deleted at once)
- `save_kn_settings(PDO, string $form, array $fields): array` (`form` settings | limits | retention; only that form's columns; before/after)

## Handlers (every one: `kn_handler_begin()`; the gate; `kn_guard()`; `base_log()`; `emit_action_status()` + `kn_done()`; `HX-Trigger: baseChanged`)
- `save.php` (`base_create` without `base`, `base_update` with): `bases.create` / `require_base_right(base, 'base.manage')`; `base.create`
  (`after`: name, key, template, visibility) / `base.update` (the diff; `description_chars`, never the description; `template_switch:
  {removed, added}`); location `/bases/{id}`.
- `settings-save.php` (`base_settings_save`): `base.manage`; the limit needs `limits.manage`; `base.update` (`after.form = 'settings'`, the diff).
- `visibility.php` (`base_visibility_set`): `base.manage`; `base.visibility_set` (`before`/`after` visibility); **external_send**.
- `members/add.php` (`base_share`) · `add-guest.php` (`base_share_guest`, **external_send**) · `add-application.php`
  (`base_share_application`, **external_send**): the right `base.share`; logged `base.share`, `base.share_guest` and
  `base.share_application` respectively (`after`: base_member_id, principal_kind, principal_id or principal_ref, level, changed) — **a
  paused action has its own log event**, because the kernel's approval policy matches the event and `base.share` is free. `members/remove.php` (`base_member_remove`, **other**): `base.share`; `base.unshare` (the row's facts).
- `archive.php` (`base_archive`) / `restore.php` (`base_restore`): `base.manage`; `base.archive` / `base.restore`.
- `delete.php` (`base_delete`, **deletion**): own level; `base.delete` (`after`: name, phase, sources, sections, build_id).
- `admin/settings/save.php` (`settings_save`, **other**) · `limits.php` (`limits_save`, **other**) · `retention.php` (`retention_set`,
  **other**): the right of the form; a CHECK refusal (`23514`) → the field named by the constraint ("Section maximum must be at least the
  target"); logged `settings.save`, `limits.save` and `retention.set` respectively (`after`: form, the changed fields and values — none is
  secret).

## Action manifest entries
Screens: `base-list`, `base-add`, `base-view`, `base-edit`, `base-share`, `base-settings`, `admin-settings`, `templates`.
Actions (14): `base_create`, `base_update` (both `/bases/save.php`), `base_settings_save`, `base_visibility_set`, `base_share`,
`base_share_guest`, `base_share_application`, `base_member_remove`, `base_archive`, `base_restore`, `base_delete`, `settings_save`,
`limits_save`, `retention_set`. Agent approvals (reconciled with
`maludb-os.json` `approvals[]`): `base_share_guest`, `base_share_application`, `base_visibility_set` (`external_send`); `base_delete`
(`deletion`); `base_member_remove`, `settings_save`, `limits_save`, `retention_set` (`other`). `base_share` to a person, an agent or a
department, `base_create`, `base_update`, `base_settings_save`, `base_archive`, `base_restore` are free. `PARTIAL_UPDATE_TARGETS` holds
`/bases/save.php => ['knowledge_bases', 'base', 'mcp_bases', 'base_id']` (the kit registered it).

## Activity log events (every row: `base_id`)
`base.create|update|visibility_set|share|share_guest|share_application|unshare|archive|restore|delete|admin_view`, `settings.save`,
`limits.save`, `retention.set`, `screen.view` (`base-view` with
`after.base_id`). **No row carries a description's words** (its length only) or any source text.

## Notifications this slice queues (`kn_notify()` through the db/012 triggers; the outbox is sent by slice 8's worker step)
| Step | Who is told | Kind |
|---|---|---|
| a person is named on a base | that person ("Marco shared "Field Manuals" with you") | `shared` |
| a department is named | every live member of it | `shared` |
| a guest is named | the guest | `shared` |

## Status vocabulary
Base status: empty `secondary`, building `info`, ready `success`, needs rebuild `warning`; archived `dark`; visibility private `dark` ·
internal `light`; level own `primary`, curate `info`, read `light`; principal chips as on `base-share`; `admin view` `danger`. Ids:
`base-list`, `base-card-{id}`, `base-add-form`, `base-form-field-{name}`, `template-card-{key}`, `base-tabs`, `base-tab-{key}`,
`base-add-documents`, `base-ask-box`, `base-curators`, `base-members-table`, `member-row-{id}`, `share-form-{kind}`, `visibility-form`,
`base-settings-form`, `admin-settings-{form}`.

## Out of scope for this slice
Sources and the "Add documents" target (2); guidelines, areas and the compile step beyond the best-effort call (3); builds (4); asking and
the ask box's target (5); the graph (6); keys and the K7 shares (7); the agents (8); the admin index, the model, usage (9); transferring a
base's owner; copying a base as a template (Extended).

## Proof (`tests/phase3/slice1/run.sh`: the scratch database `kn_dev1`, the app on :8501 (php -S, or `KN_APP=apache`), the fake kernel on
:8502, the fake MaluDB + MaluMail on :8506; members through dev hand-offs; curl with signed action and run tokens; headless Chromium at
375 × 740 and 1280 × 800; the registry `--check` and the approvals `--check`)
The world (`lib.php` `bases_world()`): the fixture's members — Owner (super-admin), Avery (admin, Engineering), Marco (Curator, Design),
Priya (Member, Design), Bea (Member, Accounting), Reid (Reader, Engineering), Ann (external Reader = a guest), Seamus (an agent, Member),
Watcher (an agent with no grant).
- [ ] **First run**: Priya's home shows one button; `/bases/new` → "SMOKE Field Manuals" with General → `/bases/{id}` with **Add documents**
  first; the row: key `kb-{id}`, namespace `kb:{id}`, private, status empty, limit 50, the four shipped guidelines copied with origin
  `template`; `base.create` logged with `base_id`; Priya's level `own`; Reid and Ann cannot create (403 in words); the JSON reply carries
  `record_id` and a location ending in it.
- [ ] **Update and template**: rename, a key `field-manuals`, a second base taking the same key → the key field's sentence; `deleting-x` refused;
  a description logged as `description_chars` only; a second template row inserted by the test → switching the empty base removes the
  untouched shipped rows, keeps an edited one, adds the new set (`template_switch`); with a `sources` row present the switch → the trigger's
  sentence on the field; Priya (a Member owner) updates her base though she lacks `base.manage`; Bea (no share) → 404; Reid with a read share → 403.
- [ ] **Share**: Priya shares with Marco (curate), Design (read), Seamus (read); Marco then manages it (curate + Curator role); Bea with a
  curate share but only Member → may not update (403 in `base.manage`'s words); changing Seamus to curate updates the row (`changed`); Ann
  through `base_share` → 422 pointing at A guest; Ann through `base_share_guest` at read → shared, told; at curate → refused in words;
  `helpdesk` through `base_share_application` → `kn_application_base_ids('helpdesk')` holds the base; curate for it → the CHECK's sentence;
  an everyone row written by hand → the trigger's sentence; removal of a row; `shared` notifications to Marco, to every live member of Design (the
  trigger tells each one, the sharer included) and to Ann; none for Seamus (an agent) or helpdesk.
- [ ] **Visibility and reach** (seven actors × a private and an internal base): owner, a curate share, a department share, an insider with no
  share (internal only), a guest named (the private one only — never the internal one), an agent named, an application principal (through
  `kn_application_base_ids()` only, never `kn_visible_base_ids()`); `mcp_bases` answers each exactly the cards shown; Avery (admin) opens
  Priya's private base → one `base.admin_view` per request (two screens, two rows; the list, none); opening an internal base or her own →
  none; `base_visibility_set` private → internal adds the everyone row, back removes it.
- [ ] **Settings**: a curator sets auto-extract, sensitivity, language; posting the limit → 403; Avery sets it to 10; an invalid language → the
  CHECK's field; the admin page's three forms each save only their columns; section maximum below the target → the CHECK's sentence;
  Marco → 403 on all three; `mcp_settings` hides the model columns from Marco; `settings.save` logged with the form and fields.
- [ ] **Archive, restore, delete**: archive keeps it readable, refuses `base_update` in words and a source insert in the database's words;
  restore; restore after another base took its key → the key field's sentence; delete of a base never in the engine → gone at once,
  `base.delete` phase `deleted`; delete with a section holding a `maludb_document_id` (written by the test) → phase `forgetting`, key
  `deleting-{id}`, archived, every source forgotten, one `forget` build queued, restore refused ("being deleted"); delete while an extract
  build is queued → "A build is running"; Marco (curate) cannot delete (own level).
- [ ] **JSON mode and agents**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; `_partial=1` on
  `base_update` keeps untouched fields; 422 `{error: {code: invalid, fields}}`; Seamus (run token + relay) creates a private base
  (`source agent`, `agent_run_id` set) and shares it with Design (free); its `base_share_guest`, `base_visibility_set` and `base_delete` reach the
  handler only through the kernel's hook (Phase 4 proves the pause; here the registry lists them with their categories); Watcher refused,
  never created.
- [ ] **375 × 740 and 1280 × 800**: the template cards stack; first run in three taps (name, template, Done); the tabs show only built slices'
  (Share, Settings now); the share table scrolls inside the page; every control ≥ 44 px; `scrollWidth` = the viewport; no console errors;
  JavaScript off creates, shares and saves; the registry reads 8 screens and 14 actions of this slice built.

## Built and proven
**Built 2026-10-09 by the planning model; proven by `tests/phase3/slice1/run.sh` — 231 checks under php -S and the same 231 under a real Apache
(`KN_APP=apache`)**: bases 28, share 40, visibility 26, settings 28, lifecycle 24, json 29, the browser 54 (375 × 740 and 1280 × 800, JavaScript
off), the registry and approvals in step, no PHP warning in the server's log. The registry reads the 8 screens and 14 actions built. Phase 2's
proofs updated for a built `/bases` (the placeholders still standing are listed in `tests/phase2/gates.php` `NAV_SLICES_LEFT`; the picker
registry now holds `person` and `guest`) — 198 green; Phase 0's 92 green; the installer's `plan` clean (49 steps).

What the build settled, for every later slice to copy:
- **`kn_guard_fields()`** (`app/handler.php`): the database's refusal — a RAISE, a duplicate, a CHECK or a trigger's check_violation, a missing
  reference — named on its field by constraint name or by a regex of the database's own sentence (`base_field_refusals()`,
  `share_field_refusals()`, `kn_settings_refusals()`); an unmapped trigger sentence is shown as written, an unmapped constraint message never.
  `kn_guard()` alone let a CHECK (23514) through as a 500.
- **`kn_done()`** answers JSON with the bare location (no `?notice=`), so a create's location ends in its id.
- `base_from_request(PDO, ?string $screen)` names the screen in the admin-view log; `base_screen_view()` logs a base's screen with `base_id`;
  `require_base_own()` gates the own-level actions; `require_base_live()` says "archived: restore it first".
- **An `own` share is a co-curator, not an owner**: `kn_base_level()` (db/006) answers own only for the owner and the admin, so only they may
  give own. **Adding** a share to an archived base is refused; removing one is not.
- **Delete** refuses any live build (db/009 allows one per base): a `forget` one says "This base is being deleted.", any other "A build is
  running: cancel it first."; an archived base is live under its `deleting-` key for the moment its forget build is queued (db/009 refuses a
  build on an archived base), then archived again, in one transaction; the forget build's `items_total` is the sections holding a document id.
  A base deleted at once takes its attachment rows, its notes and (after the commit) its files.
- The tabs (`BASE_TABS`) start with **Overview**; a tab is built when its controller exists and does not call `render_nav_stub()` — Ask stays
  hidden until slice 5 replaces the placeholder.
- The record picker's `person` and `guest` sources admit `bases.create` or `base.share` (an owner holding only Member shares their own base).
- The admin's settings: sizes are entered in MB (largest file) and KB (largest page) and stored in bytes (an agent may send the bytes column);
  the time zone is checked against PHP's list (the database has no rule); a form whose right the person lacks is shown read-only;
  `app/features/admin/handler.php` (one handler shape for the three forms) is beyond the file list above.
- "Add documents" is a plain link to `/sources/new?base=` until slice 2 builds it.

## Open questions
