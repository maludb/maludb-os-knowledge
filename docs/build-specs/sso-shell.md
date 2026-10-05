# Build spec: Phase 2 — sign-on, the mirror, the shell (before any slice)

What exists at the end: a person clicks Knowledge on the launcher — or types `knowledge.<domain>` on a phone — and lands, signed in, on a home
that is finished though it holds no base yet: one button for a new person, a plain list of bases for everyone else, the bell, the command
bar, the bottom tab bar; the mirror refreshes every minute; every request is logged; the command bar answers through the kernel. **The kit is
in the repo already (Phase 0, second half) and proven without a kernel (`tests/phase0/run.sh`, 92 checks)**; this phase wires the remaining
screens' shells around it and proves it under Apache at 375 and 1280. It is copied from Spaces (closest), Consultant Tracking and Help Desk
(the kit's lineage); every file keeps its sibling's contract so the sign-on, the directory sync and the MCP servers interoperate.

Schema it runs on (db/001–014, never modified): `members` (with the generated `is_agent`; no status line, no presence), `departments`,
`department_members`, `directory_sync_state`, `sso_nonces`, `member_sessions` (db/001); `activity_log` (+ `base_id`, `token_id`) and
`activity_ingest_state` (db/002); `mcp_access_tokens` (scope `mcp` or `api`; an `api` row is a KEY bound to one base) +
`mcp_resolve_token()` (db/003); `kn_rights`, `kn_roles`, `kn_role_rights`, `kn_has_right()`, `kn_is_admin()`, `kn_is_guest()`,
`kn_is_member_here()`, `mcp_app_roles` (db/004); `kn_settings` (db/005); `knowledge_bases`, `base_members`, `kn_visible_base_ids()`,
`kn_curated_base_ids()`, `kn_can_read_base()`, `kn_can_curate_base()`, `kn_base_level()` (db/006); `attachments` (db/007);
`key_usage`, `kn_key_reaches_base()`, `kn_resolve_key()`, `kn_rate_ok()`, `kn_key_rotate()` (db/011); `notifications`,
`notification_prefs`, `notification_outbox` (db/012); `mcp_bases`, `mcp_settings`, `mcp_notifications`, `mcp_attachments`,
`mcp_activity_log` and the rest (db/014).

## Files
**In the repo (proven by `tests/phase0/run.sh`):**
- `app/bootstrap.php` (env from `config/.env` — an exported variable wins, so the proofs never need the file; session cookie `KNSID`;
  `json_mode_begin()`; the acting member on the PDO connection; the action-token / approval-replay path; the per-request mirror and
  session-list re-check), `app/db.php`, `app/http.php`, `app/directory.php` (the mirror appliers, `mirror_apply_roles()` keeping only
  `kn_roles` keys, `mirror_apply_access()` for the feed's `access[]`), `app/mail.php` (MaluMail send; the key is optional),
  `app/partial_update.php` (`/bases/save.php` is the one registered target).
- `app/auth.php`: the verifiers (hand-off token, claims, sign-out notice, action and run tokens), sessions, `has_right()`, `require_right()`,
  `is_kn_admin()`, `is_guest()`, `require_admin()`, `require_super()`, `require_human()`, `can_read_base(int)`, `can_curate_base(int)`,
  `require_base_read(int)` (a base the person may not read is a 404, the missing base's own sentence), `require_base_curate(int)` (read but
  not curate: 403 in words), `require_visible()` for the other views.
- `app/handler.php`: `kn_handler_begin()` (POST, login, CSRF — in that order, so an anonymous POST goes to the launcher and a JSON caller
  gets 401), `req_val()`, `request_list()`, `kn_yes()`, `kn_int()`, `kn_ref()`, `kn_guard()` (our DomainException or the database's RAISE is
  a 422; a duplicate a 422), `kn_refuse_fields()`, `kn_land()`, `kn_done()`, `kn_diff()`, `members_for_pick()`, `find_live_departments()`.
- `app/activity.php`: `log_activity(PDO, $action, $entityType, $entityId, $opts)` — opts `actor_member_id`, `source`, `screen`, `route`,
  `before`, `after`, `request_id`, `agent_run_id`, `department_id`, `kernel_request_id`, **`base_id`** (defaults to the entity when it is a
  base) and **`token_id`** (defaults to the key of an API call). It never throws. `log_screen_view()` (a JSON caller only with
  `X-Screen-View: 1`).
- `app/api/bootstrap.php`: the token API's gate. **A key is not its minter**: `api_authenticate()` resolves an `api` bearer through
  `kn_resolve_key()`, records `$GLOBALS['__api_key']` and `__api_token_id`, and `api_require_base($id)` answers `not_found` for any base but the
  key's (even one its minter owns); `api_rate_limit()` counts the call in `kn_rate_ok()` and answers 429 with `Retry-After`; a person's
  `mcp` token is a 401 here. `api_require_method()`, `api_cors()`, `api_json()`, `api_error()`.
- `app/features/shell/{nav,queries}.php`, `app/features/home/queries.php`, `app/features/settings/{queries,present}.php`,
  `app/features/activity/queries.php`, `app/features/assistant/queries.php`, `app/features/files/queries.php` (`store_attachment()`,
  `attachment_path()`, `delete_attachment()`).
- Views: `layout.php`, `shared/{header,flash,notice,message,tab-bar,attachments,timeline,assistant-bar,assistant-reply}.php`,
  `settings/{index,tokens}.php` + `partials/prefs.php`, `notifications/{page.php,partials/bell.php}`, `activity/{page.php,partials/rows.php}`,
  `home/dashboard.php`, `sso/refused.php`.
- Entry points: `html/{index,login,logout,sso,activity,notifications,files}.php`, `html/sso/logout.php`, `html/api/v1/health.php`,
  `html/settings/{index,prefs}.php`, `html/settings/tokens/{index,mint,revoke}.php`, `html/settings/notifications/read.php`,
  `html/assistant/ask.php`, `html/robots.txt`, `html/manifest.webmanifest`, `html/assets/` (the design system's, verbatim; brand images
  from `bin/make_brand_images.php`).
- `bin/{directory_sync,dev_handoff,mint_mcp_token,make_brand_images,worker}.php`, `bin/dev_directory.json`, `mcp/{activity_ingest.py,db.py,
  requirements.txt}`, `tests/{dev_router,fake_kernel,fake_maludb,fake_malumail}.php`, `tests/phase0/{run.sh,probe.php}`.
**Phase 2 adds:** the placeholders for the menu items whose screens are slices' (`/ask`, `/bases`, `/pinned`, `/admin/` answer 404 until their
slice — `render_nav_stub()` in `nav.php` is available and `NAV_SLICES` names who builds each), the registry builder
(`bin/build_action_registry.php`, copied then), the vhost allow-list proof under Apache, the browser proof at 375 and 1280.

## Knowledge is not Spaces
- **No presence, no status line, no right pane, no sidebar tree.** `members` has no `last_seen_at` or `status_*`; the sidebar is Home · Ask ·
  Bases · Pinned, then the person's most recently changed bases (`sidebar_bases()`, ≤ 12, one query, `mcp_bases`), then Me, then Admin for a
  holder of `settings.manage`. The tab bar is Home · Ask · Bases · Me.
- **Reach is a base's, decided by the database.** PHP asks `kn_can_read_base()` / `kn_can_curate_base()`; `mcp_*` views compute
  `kn_visible_base_ids()` once per statement. A private base is its members' alone; a guest (external, Reader only) sees only what is named
  to them; an admin sees every base and PHP logs `base.admin_view` when one opens a private base (slice 1).
- **Roles**: Reader, Member (`user`), Curator, Admin; the badge shows the highest held. The role keyed `user` is the standing departments' grant.
- **The first-run path**: a person with no bases sees one button, "Create a knowledge base" (→ `/bases/new`, slice 1); a Reader with none is told
  nothing has been shared with them and shown no button.
- **A person's own tokens are `mcp` only** (read-only, for the MCP servers); a key (`api`) is minted on a base's Keys screen (slice 7). The Tokens
  screen lists and revokes only `mcp` rows.
- **Notification kinds are the schema's**: build_done, build_failed, source_failed, limit_near, proposal, feedback, review_due, monday_note,
  shared. No digest, no away-minutes (the table has neither).
- **Attachments are sources' files**: `attachments.record_type = 'source'`. The type is judged from the BYTES (finfo) against the extension's
  allow-list (PDF, Word, Excel, PowerPoint, OpenDocument, RTF, text, Markdown, CSV, JSON, HTML); executables, scripts and SVG (also text that
  begins `<svg`, `<?php`, `#!/`) are refused with a sentence; size ≤ `ATTACHMENT_MAX_BYTES` (50 MB). Files live in `storage/attachments/`
  (gitignored, outside the web root) and are served only by `html/files.php`: 401 anonymous, 404 for a base the caller may not read (read through
  `mcp_attachments`, so there is no separate gate function), only a PDF inline, everything else a download, with `nosniff` and a sandboxing CSP.
- **The engine is not touched here.** The home needs no `MALUDB_KB_*`; only `app/knowledge/engine.php` ever reads them. The MaluDB variables the
  kit reads (`MALUDB_API_URL`, `MALUDB_API_TOKEN`) are the TENANT's activity memory, for the ingest bridge and the health check.

## The receiver (`/sso`) — in order
1. `verify_sso_token($token, APP_KEY)` and `verify_sso_claims($claims)` — constant-time; both must pass; the member ids must match.
2. Expiry (60 s), audience (`knowledge`), then the nonce: `INSERT INTO sso_nonces … ON CONFLICT DO NOTHING`; zero rows = replay → refuse.
3. One transaction: `mirror_apply_member()` (with `capability`), `mirror_apply_roles()` from `claims.roles`.
4. Open the session: `session_regenerate_id(true)`, `$_SESSION['member_id']`, fresh CSRF token; a `member_sessions` row.
5. `log_activity('member.sign_on', 'member', $id, ['after' => ['capability', 'roles']])`; 302 to `/`.
6. Any failure: one page, `sso/refused.php` ("This sign-on link has expired…"), logged `member.sign_on.refused` with `after.reason` (token, claims,
   status, capability, replay, mirror) — never shown.

## Every request
- Cookie `KNSID`, `SameSite=Lax`, `Secure` over TLS, `HttpOnly`, strict mode. A signed-in request re-checks the mirror row and the session list;
  a member the kernel revoked has the session ended (`ended_by = 'directory'`) and is sent to the launcher with `?app=knowledge` (401 for JSON).
- Action token (`X-Action-Token` + `X-Action-Relay`, or `X-Approval-Replay`): acts as that member for one request; `Set-Cookie` removed; an id with
  no mirror row is refused and logged `member.refused`, never created; a known, active agent with no grant is admitted at first contact only when the
  kernel's run-facts call vouches for it.
- `/sso/logout` (POST from the kernel): `verify_sso_logout_notice` → every session of the member ended (`ended_by = 'kernel'`); 204 always.
- A write handler: `kn_handler_begin()` (or `require_post(); require_login(); verify_csrf();`), the right (`require_right()`), the base's reach
  (`require_base_read()` / `require_base_curate()`), `kn_guard()`, `log_activity()`, `emit_action_status()`, `kn_done()` (a create's `location` ends
  in the record id). **A source's text, a section's text and a question's words are never in a log payload** — ids, titles, counts, a kind, a status,
  120 characters of a question at most; a key is never logged (its label is).

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId): array` → `['bases' => rows of mcp_bases, 'may' => ['create', 'admin']]`
- `sidebar_bases(PDO, int $limit = 12): array` · `bell_count(PDO, int $memberId): int` · `business_name(PDO): string`
- `find_prefs / my_prefs(PDO, int $memberId): array` · `save_prefs(PDO, int $memberId, array $fields): array` (before/after of what changed)
- `find_my_notifications(PDO, int $memberId, bool $unreadOnly, int $limit, int $page): array` · `mark_notifications_read(PDO, int $memberId, ?int $id): int`
- `find_my_tokens(PDO, int $memberId): array` · `mint_token(PDO, int $memberId, string $label, string $scope): array` · `revoke_token(PDO, int $memberId, int $id): bool`
- `find_my_activity(PDO, int $memberId, int $page, array $filters): array` (filters: own, action, since, base, entity_type + entity_id) ·
  `find_record_activity(PDO, string $type, int $id, int $limit = 30): array` · `activity_sentence(array $row): string`
- `ask_assistant(PDO, int $memberId, string $utterance, array $context): array` (the kernel call; `{reply, actions, navigate, run_id, status}`)
- `store_attachment(PDO, 'source', int $sourceId, array $file, ?int $byMember): int` (throws `DomainException` with the sentence) ·
  `attachment_path(PDO, int $id): ?array` · `delete_attachment(PDO, int $id): array`

## Handlers (built)
`settings/prefs.php` (`prefs_save`: own; `prefs.save`, `after` = the fields changed), `settings/notifications/read.php` (`notification_read`; `notification.read`),
`settings/tokens/mint.php` (`token_mint`: own, people only, scope `mcp` only; `token.mint` — label and scope, never the value, shown once),
`settings/tokens/revoke.php` (`token_revoke`; `token.revoke`), `logout.php` (POST + CSRF; `member.sign_out`; 302 to the launcher),
`assistant/ask.php` (`assistant.ask`: the length and the screen, never the words).

## Action manifest entries (for Phase 1's registry)
Screens: `home`, `notifications`, `activity`, `settings`, `tokens`. Actions (4): `prefs_save`, `notification_read`, `token_mint`, `token_revoke`.
No agent approvals. (`status_set` and `presence` of the Spaces kit do not exist here.)

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.refused`, `directory.sync`, `directory.sync.failed`, `cron.run`, `prefs.save`,
`notification.read`, `token.mint`, `token.revoke`, `assistant.ask`, `screen.view`.

## Status vocabulary
Role badge: Knowledge admin / Super-admin `danger`, Curator `primary`, Reader `warning`, Member `secondary`. Base status: empty `secondary`,
building `info`, ready `success`, needs rebuild `warning`. Ids: `home-create-base-btn`, `home-bases-list`, `home-base-{id}`, `nav-{id}`,
`nav-base-{id}`, `app-tabbar`, `tab-{id}`, `assistant-bar`, `header-bell`.

## Out of scope for this phase
Every base, source, guideline, area, build, graph, question, key and admin screen (Phase 3's slices); the records and activity MCP servers and the
token API's endpoints (Phase 4 and slice 7 — the gate in `app/api/bootstrap.php` is ready for them).

## Proof (`tests/phase0/run.sh`: scratch database `knowledge_kit_scratch`, php -S on 8501, a fake kernel on 8502, a fake MaluDB + MaluMail on 8506)
Re-run: `bash /srv/apps/knowledge/tests/phase0/run.sh` — 92 PASS/FAIL checks, exit non-zero on any failure, scratch database and created roles dropped.
- [x] **mirror**: the fixture's feed through the fake kernel's change-feed API; nine members, eight admitted; `access[]` roles into `members.roles`.
- [x] **sign-on**: a signed token opens a session; replay, expired, tampered, other-audience and unknown-member are refused, logged with their reasons,
  and an unknown member is never created; the session is listed.
- [x] **rights**: admin, second admin, curator, member, reader, guest, an admitted and an unadmitted agent × `kn_has_right()`, `kn_is_admin()`, `kn_is_guest()`.
- [x] **shell and home**: the one button for a member with no bases; the list for an owner (internal and private), a member (internal only), a reader (no
  create button); an external Reader lands on the shell with the tab bar and is told nothing is shared; the Admin item for admins only; four person-own
  screens render and answer JSON.
- [x] **CSRF**: missing and bad tokens refused (403); the session's token accepted; a key-scope mint refused; an anonymous POST to the launcher (302) or 401 for JSON.
- [x] **funnel**: `base_id` and `token_id` written; `log_activity()` never throws on a bad actor, an unencodable payload or a bad key; screen views log for a page
  and for JSON only with `X-Screen-View: 1`; a question's words are not in any payload (only its length), and reach the kernel as the acting person.
- [x] **attachments**: an executable, an executable named `.pdf`, a shell script, an SVG and an SVG named `.txt` refused; a PDF and a Markdown file accepted; the
  gate serves a reader (PDF inline, `nosniff`), 404s a private base's file, 401s an anonymous caller, and `storage/` is not on the web root.
- [x] **keys**: a key reaches its base and not its owner's other base (`kn_key_reaches_base`, and `not_found` through the API gate); an `mcp` token is not
  a key; `kn_rate_ok()` refuses the 31st call in a minute.
- [x] **worker, ingest, mail**: the worker stub logs `cron.run` and exits 0; the ingest bridge ships rows to the fake MaluDB with `"application": "knowledge"`
  first and `base_id`/`token_id` in the payload; MaluMail sends through the fake API and raises a catchable error without a key.
- [x] **the kernel's word**: the sign-out notice ends the session (204, `ended_by kernel`); a bad notice changes nothing; a revoked grant in the feed shuts the
  door within one sync (`ended_by directory`); a hand-off whose claims carry no grant is refused; Knowledge's own sign-out goes to the launcher.
- [ ] **owed to Phase 2** (needs a browser and Apache): the vhost allow-list (`/`, `/sso`, `/api/v1/health`, the MCP proxies 502 until Phase 4, `/files/1` 401),
  the shell at 375 × 740 and 1280 × 800 (tab bar, offcanvas, bell, command bar answering the fake kernel, every control ≥ 44 px, no horizontal scroll,
  no console errors), the registry `--check`.

## Open questions
