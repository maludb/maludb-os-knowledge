# Build spec: sources and sectioning — THE FIRST EXEMPLAR (slice 2)

Built by the planning-class model. Every later slice replicates its files, ids, gates, worker discipline and proof style. This is the
application's intake: a curator drops a dozen documents onto a base at once (or pastes text, or adds a page by its address), sees each
one read, cut into sections and ready within the minute, opens a source to its outline, opens a section to its words and its page, and
can replace or remove a source — removal forgets exactly what it taught the engine within the minute. Every step is logged without a
word of the text; every rule is kept by the database; every slow thing happens in the worker, never in a request.
Schema: `sources`, `source_text`, `source_pages`, `sections`, `attachments`, `kn_sources_before()`, `kn_sources_after()`,
`kn_sections_before()`, `kn_sections_after_stmt()`, `kn_source_text_after()`, `kn_source_pages_after_ins()`/`_del()`,
`kn_base_recompute_status()`, `kn_record_base_id()` (db/007); `kn_source_statuses()`, `kn_section_statuses()`, `kn_settings`
(`max_source_bytes`, `url_fetch_allowed`, `url_fetch_max_bytes`, `section_target_chars`, `section_max_chars`) (db/005);
`source_focus_areas` (db/008 — copied on replace); `builds` kind `forget` (db/009 — base deletion, slice 1); `kn_source_outline()`,
`kn_section_markdown()`, `kn_base_overview()` (db/013); `kn_notify()` (db/012); the views `mcp_sources`, `mcp_source_pages`,
`mcp_sections`, `mcp_attachments`, `mcp_focus_areas`, `mcp_bases`, `mcp_activity_log` (db/014). The library: `app/knowledge/extract/*`
(`kn_extract_text()`, `kn_extract_url_text()`, `kn_extract_pasted_text()`, `kn_section_document()`, `kn_url_is_public()` —
`docs/build-specs/sectioning.md`, 250 checks green, never modified here); the engine: `kn_engine_forget_document()` (`app/knowledge/engine.php`);
the kit: `store_attachment()`, `attachment_path()`, `delete_attachment()` (`app/features/files/queries.php`), `html/files.php`, `bin/worker.php`.
Never modify them. **The database is the referee**: a section belongs to a source of its base; a forgotten source never comes back and
forgets its sections; a replaced source is forgotten; an archived base takes no source; the base's status follows its sections — the
handlers write inside `kn_guard()` and present a refusal as its sentence; PHP decides nothing twice.

## Divergences from the design
1. **Where the bytes live.** db/007's comment says `storage/sources/<base>/<source>/`; the kit (built, proven) stores at
   `storage/attachments/source-<source id>/<attachment id>-<sha12>` and serves only through `/files/{id}`. The kit wins.
2. **No automatic retry of text extraction.** `sources` has no attempts or next-attempt column. Extraction is deterministic, so a refusal
   (`unreadable`, `legacy_office`, …) is final; a transient failure is retried **inside the pass** (a URL fetch: twice, after 2 s and 5 s);
   a source the worker died on (`extracting_text` for 15 minutes) becomes `failed` / `worker_interrupted`. A person's **Retry**
   (`source_retry`) puts a failed source back in the queue. Nothing loops.
3. **`sectioning` is never seen committed.** Text, pages and sections are written in one transaction that passes through `sectioning` to
   `ready` (so a crash leaves `extracting_text`, recovered by 2). The status is kept for the log and a later re-cut.
4. **Removal wipes the content, not the record.** On `source_remove` and `source_replace` the old source's file, `source_text` and
   `source_pages` are deleted and its sections' `text` set to `''` in the same transaction (the `mcp_sections` view already hides a forgotten
   section's text); the rows stay for the history and the citations that named them.
5. **Events beyond design §6.3**: `source.retry`, `source.update` (title, language).

## Screens (375 px is the design for reading; adding is designed at 1280 and works at 375; cards for sources; no modals)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `sources` | `/bases/{id}/sources?kind=&status=&area=&q=` | the base's sources as cards (`mcp_sources`): kind icon (file `feather-file-text`, text `feather-type`, url `feather-globe`, note `feather-bookmark`, feed `feather-rss`), title, status chip, sections count, characters, pages, area chips (names from `mcp_focus_areas`), badges `stale` / `failed` / `no text`, added by and when; filters kind, status (incl. "removed"), area, search; **Add documents** (→ `source-add`); while any source is `uploaded` or `extracting_text` the list polls `/bases/{id}/sources-status` every 5 s while the page is visible (HTMX `every 5s [document.visibilityState=='visible']`; the partial answers **286** when nothing is pending, which stops the polling); a Reader sees the cards and no add button |
| `source-add` | `/sources/new?base={id}&tab=files|text|url` | three tabs (links, each a full page): **Files** — a drop zone over `<input type="file" name="files[]" multiple>` (accept: .pdf .docx .pptx .xlsx .html .htm .md .txt .csv .json); with JavaScript each dropped file is its own POST with a progress bar and its own result line ("Handbook.pdf — added", "setup.exe — refused: that file is a program"); without it one POST of up to 20 files; the limits shown ("up to 50 MB a file"); **Text** — title (required), the text (a textarea, up to 2 MB); **Address** — one URL, an optional title, the line "one page, fetched from the server; public addresses only" — hidden with "Fetching pages is turned off" when `url_fetch_allowed` is false or `KN_URL_FETCH=off`; Done → the base's `sources` |
| `source-view` | `/sources/{id}` | title, kind chip, origin (a file: its name and **Download** → `/files/{attachment_id}`; an address: the link, `rel="noopener nofollow"`, with "checked {when}"; pasted: "pasted text"), status chip with the error **in words** (the map below), counts (characters, pages, sections), built under vN (`built_under`) and "N sections stale", the area chips (slice 3 adds the assignment form), **the outline** (`kn_source_outline()` as a nested list: each heading a link to its section, a page chip "p. 3", its characters, a section status chip for curators), **the pages** (each page number linking to the first section on it), the curators' buttons: Edit, Replace, Remove, Retry (failed only); the timeline (`source_timeline()`); a replaced source shows "Replaced by …" linking to its successor |
| `source-edit` | `/sources/{id}/edit` | title, language |
| `source-replace` | `/sources/{id}/replace` | the same three inputs as `source-add`, one item; "the old version's sections are forgotten and the new one is read and cut afresh; its areas are kept" |
| `section-view` | `/sections/{id}?quote=` | **read at 375 first**: the heading path as a breadcrumb (the source title → `source-view`, each ancestor → its section), the page chip, the text (escaped; paragraphs at blank lines; `white-space: pre-wrap` for rows and tables), **‹ previous · next ›** in the source's order, "Open the source"; `?quote=` marks the first exact occurrence of the quoted words (`<mark id="quote">`, scrolled to; a whitespace-collapsed match when the exact one fails; "The quoted words are not in this section" when neither) — slice 5's citation chips link here; a curator also sees the section's status, extracted version and error; a forgotten section says "This section was removed with its source" and shows no text |

**The failure words** (`SOURCE_ERRORS` in `app/features/sources/present.php`): `unreadable` "The file could not be read." · `too_large`
"The file is over the size limit." · `executable` "That file is a program — it was refused." · `svg` "SVG images can carry scripts — refused." ·
`unsupported` "That kind of file is not read." · `legacy_office` "Old Office formats (.doc, .xls, .ppt) are not read — save it as .docx, .xlsx or
.pptx." · `zip_bomb` / `unsafe_xml` "The file's inside is unsafe — refused." · `pdf_unreadable` "The PDF is damaged." · `pdf_timeout` "The PDF
took too long to read." · `pdftotext_missing` "The server's PDF reader is not installed (an administrator's job)." · `file_missing` "The stored
file is gone." · `worker_interrupted` "Reading stopped part-way — try again." · `extract_error` "Reading failed — try again." · `fetch_disabled`,
`fetch_refused` "That address is not public.", `fetch_failed` "The page could not be reached.", `fetch_timeout`, `fetch_status` "The page answered
{status}.", `fetch_too_large`, `fetch_redirects` "Too many redirects.", `fetch_unsupported` "The page is not a document we read." · no text: "No
text could be read — it may be a scan. Scans are not read in this version."

## Adding sources (`source_add`, one handler for the three kinds)
- **Who**: `require_base_right(base, 'sources.add')` (slice 1's rule: the owner, or a curate-level member holding the right); an archived
  base → the trigger's sentence; an agent may add text or an address (a file cannot travel over the Actions MCP — the registry documents
  `kind` text | url).
- **Files** (`kind=file`, `files[]`, at most 20 a request): each file on its own — size ≤ the smaller of `ATTACHMENT_MAX_BYTES` and
  `kn_settings.max_source_bytes` ("Over 50 MB: Big.pdf"); the same bytes already live in this base (an attachment of a non-forgotten
  source with the same `sha256`) → refused "Handbook.pdf is already here as "Staff Handbook""; then one transaction: INSERT `sources`
  (`kind` file, `title` = `origin` = the cleaned file name, status `uploaded`), `store_attachment($pdo, 'source', $id, $file, $me)` (the
  bytes judged by finfo; a refusal is that file's sentence), UPDATE `attachment_id`, `mime_type`. One refused file never stops the others:
  `did` = "3 added, 1 refused" with a line per file; 200 when one was added, 422 (`fields.files`) when none.
- **Text** (`kind=text`, `title`, `text`): title 1–300, text 1 character to 2 MB; INSERT `sources` (`origin` = `paste`), INSERT
  `source_text` (`extractor` = `paste`, the raw text — the worker cleans and cuts it; the trigger sets `char_count` and `text_sha256`).
- **Address** (`kind=url`, `url`, `title?`): `url_fetch_allowed` and `KN_URL_FETCH` ≠ `off`, else "Fetching pages is turned off";
  `kn_url_is_public($url)` (it resolves the name; a private, loopback, link-local or metadata address, a bad port or userinfo → the field:
  "That address is not public, or it does not resolve."); the same address already live in the base → refused naming it; INSERT `sources`
  (`kind` url, `origin` = the URL, `title` = the given one or the URL).
- Every new source: `source.add` (`after`: source_id, kind, title, bytes or characters, mime_type, origin for a file or an address — never
  text); location `/sources/{id}` for one, `/bases/{id}/sources` for many; `record_id` the first, `records` every id.

## The worker — the queue discipline (`app/knowledge/worker/sources.php`, called by `bin/worker.php`)
- **One pass at a time**: `bin/worker.php` holds `pg_try_advisory_lock(hashtext('knowledge_worker'))` (built); a second pass exits 0 at
  once. A pass has a **deadline of 50 s** from its start (the timer is every minute); each step stops taking new work past it and finishes
  the item in hand. The worker has no member: it reads base tables (not `mcp_*` views, which answer nothing without one), logs with
  `actor_member_id` null and `source` `cron`, and every row it logs carries `base_id`.
- **The order of a pass**: `kn_worker_forget()` → `kn_worker_purge_bases()` → `kn_worker_recover()` → `kn_worker_sources()` → (slice 4)
  `kn_worker_builds()` → (later slices' steps) → `cron.run` with each step's count.
- **Forget** (`kn_worker_forget(PDO $pdo, float $deadline, int $batch = 200): int`): the sections with `status = 'forgotten' AND
  maludb_document_id IS NOT NULL`, oldest first, up to 200; each `kn_engine_forget_document((int) $doc)`; `ok` or `not_found` → `UPDATE
  sections SET maludb_document_id = NULL WHERE id = :id AND maludb_document_id = :doc` (idempotent); `engine_unavailable`, `engine_auth`,
  `engine_forbidden` → the step stops (the next pass resumes); any other failure → that section waits for the next pass. When a source's
  last such section is cleared: `source.forget` (`after`: source_id, sections). **This is the "within the minute"**: removing or replacing a
  source, or deleting a base, is forgotten by the next pass. (Slice 4 owns the forget-then-ingest of a stale section; this step forgets only
  what was removed.)
- **Purge** (`kn_worker_purge_bases(PDO $pdo): int`): each `forget` build `queued` → `running`; when no section of its base holds a
  `maludb_document_id`, the base's attachment files are deleted and the base row is deleted (the cascade takes the build), logged
  `base.delete` (`after.phase` `done`, source cron). Slice 3 adds the areas' projects (`kn_engine_project_delete()` each first; a failure
  waits).
- **Recover** (`kn_worker_recover(PDO $pdo): int`): `extracting_text` with `updated_at` older than 15 minutes → `failed` /
  `worker_interrupted`, logged `source.fail`.
- **Read and cut** (`kn_worker_sources(PDO $pdo, float $deadline, int $max = 10): int`): up to 10 sources a pass, one at a time:
  1. **Claim** (autocommit): `UPDATE sources SET status = 'extracting_text' WHERE id = (SELECT s.id FROM sources s JOIN knowledge_bases b
     ON b.id = s.base_id AND b.archived_at IS NULL WHERE s.status = 'uploaded' AND s.kind IN ('file','text','url','note') ORDER BY
     s.added_at, s.id LIMIT 1 FOR UPDATE OF s SKIP LOCKED) RETURNING *` — no row = nothing to do. Exactly one pass ever holds a source.
  2. **Extract** (outside any transaction): a **file** → its attachment (read from `attachments` directly; missing on disk → `file_missing`)
     → `kn_extract_text($path, $filename, $mime)`; **text** and **note** → `source_text.text` → `kn_extract_pasted_text($raw, $title)`; an
     **address** → `kn_fetch_url()` (below) → an HTML page through `kn_extract_url_text($body, $finalUrl)`, any other admitted type written to
     a temporary file and through `kn_extract_text()`; the fetched bytes are kept as the source's attachment (`store_attachment()` with the
     temporary file; the name from the final URL's path, else `page.html`), `url_etag` and `url_checked_at` set. A **feed** source is slice 8's.
  3. **Refused** (`ok = false`) → `failed`, `error` = the code, `source.fail` (`after`: source_id, error); **no text** (`no_text`, or no
     section comes out) → `no_text`, `source.no_text`; both notify the person who added it (`kn_notify(added_by, 'source_failed', 'Could
     not read "{title}"', <the words>, 'source', id, 'source:{id}:{status}')` — once per source and status; an agent is never notified).
  4. **Ready** — ONE transaction: upsert `source_text` (`text`, `extractor` = the kind read); replace `source_pages` (`[page_no, char_from,
     char_to]` from the extraction — characters, as the library gives them); UPDATE the source: `status` `sectioning`, `title` = the
     extraction's title **when the title is still the provisional one** (equal to `origin` — a file name or a URL; a typed title is kept),
     `language` when valid and unset; `source.text_extracted` (`after`: source_id, characters, pages, extractor); then
     `kn_section_document($extraction, ['title' => <title>, 'target_chars' => section_target_chars, 'max_chars' => section_max_chars])`
     and INSERT each section in order — `position`, `level`, `kind`, `heading`, `heading_path`, `text`, `char_from`, `char_to`, `page_no`,
     `parent_id` = the id inserted for `parent_index` — status `queued` (`body_from` is not stored: the heading is); UPDATE `status` `ready`;
     `source.sectioned` (`after`: source_id, sections, by kind); COMMIT. Any PHP failure inside → rollback, `failed` / `extract_error`
     (logged; the error to `error_log`, never the text).
  5. With the base's `auto_extract` on, the new sections are `queued` and slice 4's build picks them up; with it off they wait for a person.
- **Idempotence**: the claim's `WHERE status = 'uploaded'`, the forget's conditional clear, the sections' `UNIQUE (source_id, position)`
  inside one transaction — running a pass twice, or two passes at once, never reads a source twice or doubles a section.

## Fetching an address (`app/knowledge/fetch.php`)
`kn_fetch_url(string $url, array $opts = []): array` → `['ok', 'code', 'status', 'content_type', 'body', 'final_url', 'etag', 'bytes',
'redirects']`. Rules (`sectioning.md` "the worker owes"): `KN_URL_FETCH=off` → `fetch_disabled`; `kn_url_is_public()` on the URL **and on
every redirect target** (a relative `Location` resolved against the current URL); the host resolved **once** per hop by the same resolver and
the connection **pinned** to a public address it approved (`CURLOPT_RESOLVE` `host:port:ip` — no second lookup); `CURLOPT_FOLLOWLOCATION`
off, at most 5 redirects (`fetch_redirects`); `CURLOPT_PROTOCOLS` and `REDIR_PROTOCOLS` http and https only; no proxy (`CURLOPT_PROXY ''`,
`CURLOPT_NOPROXY '*'`), no cookies; `CURLOPT_ENCODING ''` with the **decoded** bytes counted against `url_fetch_max_bytes` (10 MB) by the
write callback, which aborts past it (`fetch_too_large`); **20 s in all**, across the redirects (`fetch_timeout`); a 2xx only (`fetch_status`
with the status); admitted types `text/html`, `application/xhtml+xml`, `application/pdf`, `text/plain`, `text/markdown`, `text/csv`,
`application/json` and the three OOXML types (`fetch_unsupported` otherwise); DNS, connect and TLS failures `fetch_failed`; User-Agent
`Knowledge/<version> (+<APP_URL>)`. In the worker a `fetch_failed`, `fetch_timeout` or a 5xx is retried twice, after 2 s and 5 s, when the
deadline allows. **The proofs' seam**: `$opts['resolve']` (a resolver) and `$opts['connect_to']` (`CURLOPT_CONNECT_TO`) are honoured **only
when `APP_ENV=dev`**; the worker builds them from `KN_URL_FETCH_TEST_MAP` (`docs.example.test=93.184.215.14@127.0.0.1:8507;…`) so a
public-looking name resolves to a public address while the bytes come from the proof's local server. In production both are ignored.

## Replacing, removing, retrying
- **Replace** (`source_replace`): `sources.manage`; the old source live and not a feed's ("A fed source is replaced by its feed"); one
  transaction: INSERT the new source (same base and kind as posted; title = the posted one, else the old one), its file or text as in
  `source_add`, copy its `source_focus_areas` rows, `UPDATE` the old `superseded_by = new` (the trigger forgets it and its sections), wipe the
  old content (divergence 4); after the commit delete the old file. `source.replace` (`after`: old_source_id, new_source_id, kind, sections
  forgotten); location `/sources/{new}`. Free for agents (nothing is lost: the new version replaces it).
- **Remove** (`source_remove`): `sources.manage`; confirm; one transaction: `status = 'forgotten'` (the trigger forgets the sections and
  recomputes the base), wipe the content; delete the file after the commit; `source.remove` (`after`: source_id, title, sections).
  **deletion** for agents. The next worker pass forgets it in the engine.
- **Retry** (`source_retry`): `sources.add`; a `failed` source → `uploaded`, `error` NULL; `source.retry`. (`no_text` is not retried: it is
  deterministic.)
- **Edit** (`source_update`): `sources.manage`; title 1–300, language (the CHECK's pattern); `source.update` (the diff).

## Files (exactly these)
- `html/bases/sources.php` (`sources`) · `html/bases/sources-status.php` (the polling partial)
- `html/sources/form.php` (`source-add`, `source-edit`) · `view.php` (`source-view`) · `replace.php` (`source-replace`, GET the form,
  POST the action) · `add.php` · `update.php` · `remove.php` · `retry.php`
- `html/sections/view.php` (`section-view`)
- `app/features/sources/{queries,present,write,handler}.php` (`handler.php`: `source_from_request()` — the source through `mcp_sources`
  and its base through `base_from_request()` (slice 1; the admin-view log follows) —, `source_log()` with `base_id`)
- `app/knowledge/fetch.php` · `app/knowledge/worker/sources.php` · `bin/worker.php` (the stub's steps filled in, in the order above)
- `html/assets/js/kn-upload.js` (the drop zone; one POST per file; progressive)
- `app/views/sources/{index,form,view,replace,partials/source-card,partials/outline,partials/pages,partials/upload-result,partials/status-chip,partials/sources-status}.php`,
  `app/views/sections/view.php`
- `tests/phase3/engine.sh` (start | stop the scratch memory database, the MaluDB API 0.4.0 on :8503 and the fake provider on :8504 — the
  setup of `tests/phase0/engine.sh`, factored for every slice that needs the engine), `tests/phase3/slice2/{run.sh,lib.php,web.php,upload.php,
  worker.php,paste.php,url.php,views.php,remove.php,purge.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `find_sources(PDO, int $baseId, array $filters, int $limit = 200): array` (`mcp_sources`; `kind`, `status` — `removed` = forgotten, else
  live only —, `area`, `q`; with area names) · `find_source(PDO, int $id): ?array` (`mcp_sources`) · `pending_sources(PDO, int $baseId): array`
  (status uploaded or extracting_text — the polling partial)
- `source_outline(PDO, int $sourceId): array` (`kn_source_outline()` nested by `parent_id`) · `source_pages(PDO, int $sourceId): array`
  (`mcp_source_pages` with the first section on each page) · `source_timeline(PDO, int $sourceId, int $limit = 30): array`
- `find_section(PDO, int $id): ?array` (`mcp_sections`) · `section_neighbours(PDO, int $sectionId): array` (`['prev' => ?id, 'next' => ?id]`
  by position among the source's live sections) · `section_ancestors(PDO, int $sectionId): array` · `mark_quote(string $text, string $quote): array`
  (`['html', 'found' => exact|collapsed|none]`, escaped)
- `base_has_attachment_sha(PDO, int $baseId, string $sha256): ?array` (the live source holding it) · `base_has_url(PDO, int $baseId, string $url): ?array`

## Writes (`app/features/sources/write.php`)
- `add_file_source(PDO, int $baseId, array $file, int $by): array` (`['source_id', 'title']` or throws the file's sentence) ·
  `add_text_source(PDO, int $baseId, string $title, string $text, int $by): int` · `add_url_source(PDO, int $baseId, string $url, ?string $title, int $by): int`
- `replace_source(PDO, int $oldId, array $input, int $by): array` (`['new_id', 'sections_forgotten', 'old_file']`) · `remove_source(PDO, int $id, int $by): array`
  (`['sections', 'file']`) · `wipe_source_content(PDO, int $id): ?string` (the file path to delete after commit) · `retry_source(PDO, int $id, int $by): void`
  · `update_source(PDO, int $id, array $fields, int $by): array`

## Handlers (every one: `kn_handler_begin()`; `require_base_right()`; `kn_guard()`; `source_log()`; `emit_action_status()` + `kn_done()`; `HX-Trigger: sourcesChanged`)
- `sources/add.php` (`source_add`): `sources.add`; per kind as above; `source.add` per source.
- `sources/replace.php` POST (`source_replace`): `sources.manage`; `source.replace`.
- `sources/remove.php` (`source_remove`, **deletion**): `sources.manage`; `source.remove`.
- `sources/retry.php` (`source_retry`): `sources.add`; `source.retry`. `sources/update.php` (`source_update`): `sources.manage`; `source.update`.

## Action manifest entries
Screens: `sources`, `source-add`, `source-view`, `source-edit`, `source-replace`, `section-view`. Actions (5): `source_add`,
`source_replace`, `source_remove`, `source_retry`, `source_update`. Agent approvals: `source_remove` (`deletion`) — reconciled with
`maludb-os.json`; the rest free (the expert holds `source_add`). `PARTIAL_UPDATE_TARGETS` gains `/sources/update.php => ['sources',
'source', 'mcp_sources', 'source_id']`.

## Activity log events (every row: `base_id`; entity `source` unless named)
`source.add|replace|remove|retry|update` (web, agent), `source.text_extracted|sectioned|no_text|fail|forget` (cron), `base.delete` phase
done (cron, entity base), `screen.view` (`source-view` with `after.source_id`, `section-view` with `after.section_id`). **No row carries a
source's text, a section's text, a page's words or a quote** — ids, titles, counts, a kind, a status, an error code. A URL is an origin and
may be logged; a query string is cut from it in the log (`origin` up to `?`).

## Notifications this slice queues
| Step | Who is told | Kind |
|---|---|---|
| a source fails to read, or has no text | the person who added it (not an agent) | `source_failed` (dedupe `source:{id}:{status}`) |

## Status vocabulary
Source: uploaded `secondary` "waiting", extracting_text `info` "reading", sectioning `info` "cutting", ready `success`, no_text `warning`
"no text", failed `danger`, forgotten `dark` "removed". Section (curators): queued `secondary` "not built yet", extracted `success` "in the
graph", stale `warning` "guidelines changed", failed `danger`, forgotten `dark`. Ids: `sources-list`, `source-card-{id}`,
`source-add-tabs`, `source-drop-zone`, `upload-result-{n}`, `source-form-field-{name}`, `source-outline`, `outline-node-{section id}`,
`source-pages`, `source-actions`, `section-text`, `section-prev`, `section-next`, `quote`, `sources-status`.

## Deploy note (owed with this slice)
PHP's defaults (`upload_max_filesize` 2M, `post_max_size` 8M) refuse a 50 MB file before the handler runs. `deploy/apache-knowledge.conf`
gains, in the internal vhost: `php_value upload_max_filesize 52M`, `php_value post_max_size 100M`, `php_value max_file_uploads 20` (the
vhost's `LimitRequestBody 104857600` already allows 100 MB); the dev router's proofs pass the same as `-d` options to `php -S`. A POST over
`post_max_size` arrives empty (its CSRF token too): `add.php`, **before** `kn_handler_begin()` (it only refuses), detects `$_SERVER['CONTENT_LENGTH'] > 0` with an empty `$_POST`/`$_FILES` and answers "The upload
was larger than the server accepts (100 MB a request)".

## Out of scope for this slice
Assigning areas (3 — the chips show, the form arrives there); building and forget-then-ingest of stale sections (4); citations and their
chips (5 — `section-view` already honours `?quote=`); "what it taught the graph" (6); feeds and URL change checks (8); notes on sources and
sections; OCR (Extended); a site crawl (Extended); re-cutting a source when the section sizes change (later).

## Proof (`tests/phase3/slice2/run.sh`: the scratch database `kn_dev2`, the app on :8501, the fake kernel on :8502, **the real MaluDB API
0.4.0 on a scratch memory database on :8503 with the fake provider on :8504** (`tests/phase3/engine.sh`), the fake MaluDB + MaluMail on :8506,
the proof's web server on :8507 (`web.php`); the fixtures generated by `tests/sectioning/fixtures.php`, every one carrying the planted phrase
`ZEBRA-PLINTH-7741`; headless Chromium at 375 × 740 and 1280 × 800; the registry and approvals `--check`)
The world: slice 1's (Priya owns "SMOKE Field Manuals", Marco curates it, Reid reads it, Ann a guest on it).
- [ ] **Upload**: Priya posts a DOCX, a PDF with text, a PPTX, an XLSX, a Markdown file, a CSV and an image-only PDF in one request → seven
  sources `uploaded` with attachments under `storage/attachments/source-{id}/`; an executable named `.pdf` and an SVG refused per file while
  the others are added (`did` "7 added, 2 refused", 200); a file over a 2 MB `max_source_bytes` → its sentence; the same DOCX again →
  "already here as …"; a request whose every file is refused → 422 `fields.files`; `source.add` per source with kind, title and bytes and never text;
  Reid → 403, Bea → 404, an archived base → the trigger's words.
- [ ] **Worker**: one pass reads and cuts them: each `ready` with `source_text`, page anchors (PDF 5, PPTX per slide, XLSX per sheet, none for
  DOCX), sections tiling the text, parent ids from `parent_index`, the DOCX path `… › Leave › Carry-over`, titles replaced from the provisional
  file names ("Staff Handbook"), the language from the DOCX; the image-only PDF `no_text` with Priya notified once (`source_failed`); a broken
  PDF `failed` / `pdf_unreadable` in words on its page; the base `empty` until a build (sections queued, none extracted); `source.text_extracted`
  and `source.sectioned` logged with counts; a second pass changes nothing; two passes started together → the second exits at the lock and
  each source is sectioned once; a source left in `extracting_text` 16 minutes ago → `failed` / `worker_interrupted`; Retry → read again;
  the planted phrase in no `activity_log` row.
- [ ] **Paste**: a titled Markdown paste → sections by its headings; a plain paste → parts of about 4,000; an empty text → the field; 2 MB + 1 → refused.
- [ ] **Address**: `docs.example.test/page.html` (through the dev map) → ready with the page title and its headings, the bytes kept as an
  attachment, `url_etag` and `url_checked_at` set; a PDF address → read as a PDF; `http://127.0.0.1/` → the field's sentence at add time;
  a redirect to `127.0.0.1` → `fetch_refused`; six redirects → `fetch_redirects`; 11 MB → `fetch_too_large`; a page answering 404 →
  `fetch_status` "The page answered 404"; a 500 then 200 → ready after one in-pass retry; a page that sleeps 25 s → `fetch_timeout`; an image
  → `fetch_unsupported`; `url_fetch_allowed` false → the tab hidden and a forged POST refused; `KN_URL_FETCH=off` → the same; with
  `APP_ENV=prod` the dev map is ignored (the name does not resolve → `fetch_failed`).
- [ ] **Views**: `sources` cards with chips and badges and the filters; the polling partial answers 286 when nothing is pending; `source-view`
  outline nested, page links to their first sections, the error in words; `section-view` prev/next across the source, the breadcrumb, the page
  chip, `?quote=` marked exactly, collapsed-whitespace marked, an absent quote said; Reid reads both; Ann (a guest named on the base) reads
  them; Bea → 404 on a source and a section; Avery's look at the private base logs `base.admin_view`; `/files/{id}` serves Priya and Reid, 404s
  Bea; a curator sees section status chips, a Reader none.
- [ ] **Remove and replace, against the engine**: the test ingests two of a source's sections with `kn_engine_ingest_section()` (the fake
  provider) and writes their `maludb_document_id`s; `source_remove` → the source `forgotten`, its sections `forgotten`, `source_text` and
  `source_pages` gone, the sections' text `''`, the file gone, `/files/{id}` 404; the next pass → `GET /v1/documents/{id}` 404 for both, the
  ids cleared, `source.forget` logged; `source_replace` of another source by a new DOCX → the old `superseded_by` the new, forgotten, its area
  row copied (written by the test — slice 3 has no form yet), the new one read and cut on the next pass; a fed source → refused; the engine
  down (API stopped) → the forget step stops and resumes after restart; a document already gone (`not_found`) → cleared.
- [ ] **Purge**: slice 1's deletion of a base whose section holds a real engine document → the next pass forgets it, deletes the attachment
  files and the base, logs `base.delete` phase `done` (source cron); `mcp_bases` no longer lists it.
- [ ] **JSON mode and agents**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}` (`records` for
  many); `_partial=1` on `source_update` keeps the untouched field; 422 `{error: {code: invalid, fields}}`; Seamus (curate share, run token +
  relay) adds a text source and an address (`source agent`) and its `source_remove` is listed `deletion` in the registry.
- [ ] **375 × 740 and 1280 × 800**: the drop zone takes three files and shows three result lines (one POST each); without JavaScript the
  multi-file form posts; the source cards stack; `section-view` at 375 reads with no horizontal scroll, the mark visible, prev/next ≥ 44 px;
  the outline indents within the viewport at depth 5; the polling stops when everything is ready; no console errors; the registry reads 6
  screens and 5 actions of this slice built.

## Built and proven
Not built.

## Open questions
