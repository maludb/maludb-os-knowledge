# Phase 1 — consistency of the documents

2026-10-05. This file records what was checked across the Phase 1 documents. They are the design (read only here), the tool surface,
the action manifest, the registry and its wrapper, `maludb-os.json`, `os/*.md`, the skills and the ten slice specs (plus `sso-shell.md`,
read only). It lists what disagreed, what was changed, and what is left for the lead or the owner.

## Final counts

| | Count |
|---|---|
| Screens in the manifest (registry entries) | **61** (5 built: the kit's `home`, `activity`, `notifications`, `settings`, `tokens`) |
| Actions in the manifest (registry entries) | **62** (4 built: the kit's `prefs_save`, `notification_read`, `token_mint`, `token_revoke`) |
| Registry entries in all | **123** (61 screens + 62 actions); 0 parse warnings; 0 unresolved endpoints |
| Paused for an agent (`maludb-os.json` `approvals[]`, equal to the manifest) | **20**: 5 `external_send`, 8 `deletion`, 7 `other` |
| People only (the handler refuses an agent's token) | 7: `token_mint`, `answer_unpin`, `question_expert`, `archivist_proposal_accept`, `archivist_proposal_dismiss`, `areas_propose_request`, `guidelines_draft_request` |
| Records MCP tools | **61** (3 shares; `bases_index` is the kernel's alone) |
| Activity MCP tools | **6** |
| Resolve entities (`deploy/kernel-registry-knowledge.json`) | 13 |
| The expert's grants | 56 records + 4 activity + 12 actions = 72 (`plan`: "72 tools") |
| The Archivist's grants | 26 records + 2 activity + 2 actions = 30 (`plan`: "30 tools") |

The paused set: `base_share_guest`, `base_share_application`, `base_visibility_set`, `key_mint`, `key_rotate` (`external_send`);
`base_delete`, `source_remove`, `guideline_delete`, `vocabulary_delete`, `focus_area_delete`, `feed_delete`, `key_revoke`,
`question_purge` (`deletion`); `base_member_remove`, `settings_save`, `limits_save`, `retention_set`, `model_config_save`,
`build_rebuild_all`, `agents_settings_save` (`other`).

The checks that hold: every action's endpoint is a handler named in its slice spec's Files list. Every screen's canonical URL maps to a
controller named there. Every action and screen in the manifest is named in a spec, and every action key a spec's "Action manifest entries"
names is in the manifest. Every tool name in `os/*.md` and the skills is a tool-surface tool or a manifest action. The tool lists in
`os/expert.md` and `os/archivist.md` equal `maludb-os.json`'s grants. `php bin/build_action_registry.php --check` passes (registry,
approvals, 0 warnings).

## Inconsistencies found and fixed

1. **An `evidence_only` answer's words** (ruling a). `ask.md` stored the quoted passages as `answer_md` and made the answer pinnable. The
   tool surface said `null`. Now `ask.md` says `answer_md` is NULL and the answer is not pinnable (`kn_pin_answer()` refuses it in its own
   words). The card shows no Pin, and `kn_ask_evidence_markdown()` is gone from the Files list. The referee sentence, divergence 7, step 3
   (an evidence-only turn counts as no answer in the history), step 8, the card table, the Pin control and two proof lines all changed.
2. **The fallback when the model fails** (ruling b). The tool surface made `model_failed` a `failed` answer. It now falls back to
   `evidence_only` with the code and a message, the same as `ask.md`. `failed` is kept for an engine that cannot be reached or errs. The tool
   surface's ask steps, status table and `error_code` list changed, and its log line now names `question.answer` for `failed` as `ask.md` does.
3. **`refused`** (ruling c). The tool surface used `refused` for "an exclusion guideline matched" (a pre-match that has no rule). It now
   means the base or the area is not built yet, or the engine would not take the question. The skill reference `question-statuses.md` had a
   third meaning ("may not read the base, or a key over its rate"), which is now aligned.
4. **K26** (ruling d). The tool surface and `api-keys-k7.md` said "K26 (proposed)". Both now say K26 is owed to the kernel and not built,
   as design §12 does. The tool surface's shares section now opens with "None of the three shares answers a sibling today …
   `consumer_unknown`". Nothing in the Phase 1 documents claims that a share works today. The design is the exception (see the open items).
5. **Communities** (ruling e). `os/expert.md` and the skills `propose-focus-areas`, `asking-well` and `reading-the-graph` now say the
   expert proposes areas from the graph's most connected subjects and clusters, and from MaluDB's communities when it computes them. The
   expert's dispatch text in `agents-feeds.md` says the same.
6. **Paused actions that shared a log event with free ones.** The kernel's approval policy matches the registry's `log_event`, so a
   shared event would pause the free action too. In `bases-core.md`, `base_share_guest` and `base_share_application` logged `base.share`
   (the free `base_share`'s event). They now log `base.share_guest` and `base.share_application`. In `agents-feeds.md`, `feed_delete`
   logged `feed.save` (the free `feed_save`'s event). It now logs `feed.delete`. For the same convention and to avoid duplicate policy rows,
   `limits_save` → `limits.save`, `retention_set` → `retention.set` (`bases-core.md`) and `agents_settings_save` → `agents_settings.save`
   (`home-admin.md`). The tool surface's activity vocabulary lists them. The builder now warns on any such sharing.
7. **`ask.md` "Actions (7)"** listed six. It now says (6).
8. **One screen id with two URLs.** `key-form` (`api-keys-k7.md`) and `feed-form` (`agents-feeds.md`) each covered `/x/new` and
   `/x/{id}/edit`, which a registry row cannot hold. They are now `key-add` / `key-edit` and `feed-add` / `feed-edit`, as slice 3's
   `guideline-add` / `-edit` already were. The proofs' screen counts were corrected to match.
9. **The `key` param collision.** A base's `key` (its slug, on `base_create` / `base_update`) and an API key (the tool surface resolved
   `key` through `find_keys`) had the same name, so the kernel would have resolved a base slug as an API key. Every key action now takes
   `api_key`. `api-keys-k7.md`'s partial-update target and the tool surface's resolution table changed.
10. **The tool surface's divergence 10** named `base_members` and `agent_dispatches` as action names. No such actions exist, and only
    `question_feedback` is both a tool and an action.
11. **The dispatch text against the runbook.** For `propose_areas`, `agents-feeds.md` asked for at most six areas, 3–8 seed terms and
    two example questions. The runbook says 3–8 areas, 5–12 terms and three questions, and `os/expert.md` says three. The dispatch text now
    follows the runbook (at most eight, 5–12 terms, three questions).
12. **Drafted guidelines.** The runbook `draft-guidelines-from-a-sample` saved active guidelines and vocabulary terms, which marks every
    section stale. `agents-feeds.md` divergence 3 requires drafts to be inactive. The runbook now saves drafts with `is_active` no and lists
    the terms it would add instead of saving them (a term shapes the graph the moment it is saved). The dispatch text says the same. *This is
    a choice: see open item 5.*
13. **What a preview shows.** `writing-guidelines` and `draft-guidelines-from-a-sample` said `extraction_preview` "shows what would be
    extracted". It shows the compiled prompt with the section in place, a dry run of the prompt and not of the model (MaluDB M5; the tool
    surface and `guidelines-areas.md`). The runbook now uses the tool's `draft` parameter instead of "save the first guideline and re-preview".
14. **A broken reference.** `writing-guidelines` pointed to `references/vocabulary-terms.md`, which does not exist. The line is removed.
15. **Merge proposals.** `merge-duplicate-subjects` made one `archivist_propose` per group. `agents-feeds.md` allows one open `merge`
    proposal per base, with every group in its payload. The skill now makes one per base.
16. **The Archivist's proposal kinds.** `os/archivist.md` listed generic findings. It now lists the eleven kinds of `agents-feeds.md`'s
    table, with "a failed build is a line of the note, not a proposal".
17. **The expert and `bases_index`.** `os/expert.md` told the expert to use `bases_index`, which is `KERNEL_ONLY` (tool surface). It
    is removed.
18. **The agents' grants.** The expert lacked `find_members` and `find_departments` (the resolvers its `base_share` needs), `find_notes`
    and `records_search`. It held the generic `base_share` for a guest or an application, which are now `base_share_guest` and
    `base_share_application` (design §5: "pauses on `base_share` to a guest or an application"). Both pause. The Archivist gained
    `get_question` (the thumbs-down answers "with their evidence", design §5). `os/*.md` name exactly the grants.
19. **`home-admin.md` divergence 2** said `agents_settings_save` was "to be added" to `approvals`. It is added.
20. **`knowledge-basics`' pause list** lacked revoking a key, removing someone from a base, purging questions, rebuilding everything and
    naming the agents. These are added.

## Names in one document and not another (left as they are, on purpose)

- `guideline_save`'s activation field is `is_active` in the manifest and in `agents-feeds.md`. `guidelines-areas.md`'s form calls the
  checkbox "active", which is a label, not a param. The handler reads `is_active`.
- `agents_settings_save` takes `expert` and `archivist` (resolved by name through `find_members`) and writes `expert_member_id` and
  `archivist_member_id` (`home-admin.md` says so now).
- `model_config_save`'s confirmation box is `rebuild_confirmed` (the `save_model_config(…, bool $rebuildConfirmed, …)` signature).
- `question_ask` keeps the window's own field names `area` and `c` (`ask.md`). The records MCP `ask` tool uses `focus_area` and
  `conversation_id`. Both resolve `area` / `focus_area` through `find_focus_areas`.
- `sso-shell.md` mentions that `status_set` "does not exist here", which is correct. It is not in the manifest.
- The specs' proofs mention "the registry and approvals `--check`". Here one command does both: `php bin/build_action_registry.php
  --check` (Spaces has a separate `bin/sync_approvals.php`; this brief allowed only the builder).

## Could not reconcile here (for the lead or the owner)

1. **The design is not edited in this pass.** Design §5 still names `base_share` (to a guest or an application) as one key, says the expert
   "proposes focus areas from the communities", and lists `archivist_proposal_*` as free for agents. §10's Phase 5 gate says "the expert
   proposes areas from the communities" and "Help Desk's expert reads `ask` over an approved connection", which cannot pass before K26.
   Those passages need the same rulings (e) and (d) applied, and the split share keys recorded.
2. **`archivist_proposal_accept` / `_dismiss`.** Design §5 says an agent does these alone. `agents-feeds.md` (the effect is "a person's
   act"; its proof refuses the Archivist's token with 403) makes them people only. The manifest follows the slice spec (`people`, no
   category). **The lead decides**: people only (as now), or agent-callable with a category.
3. **Approval policies are installation-wide, not per application.** The installer writes `approval_policies` by log-event pattern, and
   `plan` shows that 6 of Knowledge's 20 events (`base.delete`, `feed.delete`, `focus_area.delete`, `guideline.delete`,
   `vocabulary.delete`, `settings.save`) are already covered by existing kernel policies. The other way round, Knowledge's new policies
   (`source.remove`, `key.mint`, `key.rotate`, `key.revoke`, `base.unshare`, `retention.set`, `limits.save`, …) would pause any other
   application's agent action that logs the same event. This is a kernel matter (an application scope on the policy, or the app key in the
   pattern) for the owner to order.
4. **`key_revoke` "(not its own)"** (design §5) cannot be a category. Every agent revoke pauses (`api-keys-k7.md` divergence 3, unchanged).
5. **Drafting vocabulary.** The decision in item 12 (the expert lists vocabulary terms in its reply rather than saving them) keeps a draft
   from making sections stale. The alternative is an inactive flag on `vocabulary_terms`, which would be a schema change. The lead confirms
   or rules otherwise.
6. **Agents reach curator tools only through a `curate` share.** The expert and the Archivist hold the role `user`. The Archivist's report
   tools (`question_gaps`, `duplicate_subjects`, `find_feeds`, `questions`, …) answer `curators_only` on a base that no curator shared with
   it at `curate`. The owner or curators must share each base with the Archivist at `curate`, or its Monday report is thin. `os/archivist.md`
   now says so.
7. **The `question` resolver** is `my_questions` (the caller's own, as in the tool surface), so an agent or a command bar naming *another
   person's* question by its words cannot resolve it. An id still works. A curator-wide resolver would need a new tool or `questions` with
   `q`. Left as the tool surface designed it.

## Not checked or not changed

- `db/` and `app/`, `html/`, `tests/` and `mcp/` (except `mcp/action_registry.json`) were not touched. Another agent is adding `db/015`,
  and `plan` now counts 15 migrations.
- `plan`'s other lines (ports not yet pinned in `config/.env`, `MCP_*_PORT` unknown, no DNS) concern `deploy/ROOT_STEPS.sh` step 0, not
  these documents.
