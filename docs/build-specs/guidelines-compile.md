# Build spec: how guidelines compile into the engine's input (Phase 1 — `app/knowledge/compile.php`)

What exists at the end: one PHP module that turns a base's words — its five kinds of guideline, its vocabulary, its template, a focus
area and a source's areas — into exactly what MaluDB is handed: the namespace's **`prompt_template`** (the extraction prompt the
documents route fills with each chunk), the **hints** (subject tags) and **projects** of each ingested section, the **terms** seeded
as subjects before a build, and the **`system`** string M1 receives with every question. Deterministic: the same rows give the same
bytes and the same SHA-256, so a build and the worker can tell what the graph was extracted under and whether the engine still holds
it. No model is ever called here; nothing here writes a table. Slice 3 (guidelines, vocabulary, areas) calls it on every save and for
Preview; slice 4 (the build) calls it before every build's first item; slice 5 (`kn_ask()`) calls `kn_compile_answer_system()`.

Schema it reads (db/005–008, never modified): `base_templates` (`key`, `version`, `guidelines`, `subject_types`), `knowledge_bases`
(`id`, `namespace`, `template`, `template_version`, `guideline_version`, `language`), `guidelines` (`kind`, `title`, `body`, `position`,
`is_active`, `id`), `vocabulary_terms` (`canonical_name`, `aliases`, `subject_type`, `description`, `maludb_subject_id`), `focus_areas`
(`name`, `description`, `seed_terms`, `status`, `maludb_project_id`), `source_focus_areas`; `kn_guideline_kinds()`,
`kn_graph_shaping_kinds()` (db/005). The engine calls go through `app/knowledge/engine.php` only (`kn_engine_get_namespace_config`,
`kn_engine_config_namespace`, `kn_engine_project_name`); the text helpers are the sectioner's (`kn_nfc()` in `app/knowledge/extract/text.php`).

## Divergences from the design
1. **Where the engine's connection lives.** Design §6.2 says the base's namespace config is "the admin's model alias, the compiled
   `prompt_template`, the embedding model", and §6.3/§8 put the model alias and embedding model in `kn_settings`. A namespace config
   also needs the provider, `model_identifier`, `base_url` (the K24 ledger proxy) and the stored secret — none of which `kn_settings`
   holds, and the K24 credential must never be in our database. **Resolution:** the business's engine connection is the namespace
   config of the **reference namespace `kb:0`** (no base can ever have id 0 — identities start at 1 — and `kn_engine_valid_namespace()`
   accepts it). It is written once, with the credential, by the owner (`deploy/ROOT_STEPS.sh` step 5 — **which must name
   `"namespace": "kb:0"`; today it names none, i.e. `default`**) and later by the admin's model screen (`model_config_save`, slice 9).
   Every base's namespace config copies the connection from `kb:0` and references the stored secret by name (`secret_name` with no
   `token`) — the credential is posted once, ever, and never by this module. `kn_settings.model_alias` / `embedding_model` are slice 9's
   record of the admin's choice; compile does not read them.
2. **Preview compiles locally.** `kn_engine_preview()` fills the prompt *the engine already stores*; a guideline form previews an unsaved
   draft, so Preview calls `kn_compile_prompt()` with the draft and fills `{{chunk}}` itself. The engine's generic preview prompt is
   shown only as a collapsed comparison. A dry run of the model (M5) is not available.
3. **`{{text}}` is a second placeholder.** The documents route replaces both `{{chunk}}` and `{{text}}` (`app/helpers/llm.py`
   `mem_extract`). A person's words could otherwise inject either: compile neutralises every `{{` and `}}` in guideline and vocabulary
   text (below).

## The functions (signatures fixed; `app/knowledge/compile.php`, `declare(strict_types=1)`, no classes)
Each loader reads **base tables directly** through the `knowledge_rw` connection — it works for the worker, which has no member — so
**authorization is the caller's** (a handler has already passed `require_base_right()`; the worker acts for the system). Each pure core
takes arrays and touches nothing; the proof drives the cores with fixtures.

| Function | Returns |
|---|---|
| `kn_compile_inputs(PDO $pdo, int $baseId): array` | `['base_id', 'namespace', 'language', 'template' => ['key', 'version', 'subject_types' => []], 'guidelines' => [['id','kind','title','body','position','is_active'], …], 'terms' => [['id','canonical_name','aliases','subject_type','description'], …]]` — every guideline row of the base (active or not), every term |
| `kn_compile_prompt_from(array $in): array` | `['prompt_template' => string, 'sha256' => hex, 'chars' => int, 'lines' => ['extract','subject_type','vocabulary_note','term','alias','exclude' => counts], 'truncated' => [block => dropped lines], 'conflicts' => [['alias','kept_for','dropped_for']], 'aliases' => [alias => canonical]]` |
| `kn_compile_prompt(PDO $pdo, int $baseId, array $draft = []): array` | the inputs, with `$draft` (`['guideline_id' => ?int, 'kind', 'title', 'body', 'is_active' => bool, 'position' => ?int]`) replacing that guideline or added as a new one, through the core |
| `kn_compile_vocabulary_from(array $terms): array` | `['terms' => [['canonical_name','aliases','subject_type','description'], …] (kn_engine_seed_subjects' shape), 'aliases' => [alias => canonical], 'conflicts' => [], 'sha256']` |
| `kn_compile_vocabulary(PDO $pdo, int $baseId): array` | the same for the base |
| `kn_compile_answer_system_from(array $in, ?array $area = null): array` | `['system' => string ≤ 8,000, 'sha256', 'chars', 'truncated' => []]` — `$area` = `['name','description']` |
| `kn_compile_answer_system(PDO $pdo, int $baseId, ?int $focusAreaId = null, array $draft = []): array` | the same; an area of another base or not active is ignored (no FOCUS AREA line); `$draft` as for the prompt (Preview of an answering or audience guideline) |
| `kn_compile_section_hints(array $vocabulary, array $seedTerms, string $text, int $max = 20): array` | the canonical names to tag a section with (below) |
| `kn_compile_projects(PDO $pdo, int $sourceId): array` | the engine project names (`kn_engine_project_name($namespace, $area['name'])`) of the source's areas that are `active` **and** have `maludb_project_id` set, sorted by area position, then id |
| `kn_compile_connection(): array` | `['ok', 'code', 'connection' => ['provider_name','provider_kind','model_alias','model_identifier','base_url','embedding_model','secret_name','context_length' => ?int]]` from `kn_engine_get_namespace_config('kb:0')`; any of model_identifier, base_url, embedding_model, secret_ref missing → `ok = false`, `code = 'model_not_configured'`; provider name and kind are the reference's when the engine returns them, else `knowledge-ledger` and `cloud_api`; the alias name the reference's, else `knowledge-extract` |
| `kn_compile_engine_sha(string $namespace): ?string` | SHA-256 of the `prompt_template` the engine stores for the namespace; null when none or unreachable |
| `kn_compile_send(PDO $pdo, int $baseId): array` | compile → connection → `kn_engine_config_namespace($namespace, $connection + ['prompt_template' => …])` (secret by name, never a token) → `['ok', 'code', 'sha256', 'error']` |
| `kn_compile_ensure(PDO $pdo, int $baseId): array` | `kn_compile_send()` only when `kn_compile_engine_sha()` differs from the compiled hash; `['ok', 'code', 'sha256', 'sent' => bool]` |

`kn_compile_text(string $s): string` and `kn_compile_lines(string $body): array` are public helpers (the proof drives them).

## Normalising a person's words (`kn_compile_text`)
In this order: CRLF and CR → LF; C0 controls except `\t` and `\n`, DEL, C1 controls and U+FEFF removed; `\t` → one space; trailing
spaces of every line trimmed; three or more newlines → two; NFC (`kn_nfc()`); the whole trimmed; then **every `{{` → `{ {` and every
`}}` → `} }`**, so neither `{{chunk}}` nor `{{text}}` can appear except where compile itself writes `{{chunk}}`. Nothing else is
rewritten — no case change, no locale, no clock.

**Lines** (`kn_compile_lines`): a normalised body is split on `\n`; each line loses a leading bullet (`^\s*(?:[-*•]|\d{1,3}[.)])\s+`);
then it is split into sentences at `(?<=[.!?])\s+(?=[\p{Lu}\p{N}"“(])` (an end mark, space, then a capital, a digit, a quote or a
parenthesis); each sentence is trimmed; empty ones are dropped. `"Answer briefly. e.g. two lines"` stays one sentence (no capital after).

## The extraction prompt (`kn_compile_prompt_from`) — blocks in this order, a blank line between blocks, an empty block omitted whole
1. **Header** (fixed words; `{n}` the base id, `{t}`/`{v}` the template key and version):
   `KNOWLEDGE BASE kb:{n} — extraction instructions (compiled by Knowledge from the base's guidelines; template {t} v{v})`
2. **Preamble** (fixed, two lines):
   `You read ONE section of a document from this knowledge base and extract a knowledge graph from it: the subjects the section is
   about, and the statements it makes about them. Follow every line below. Lines starting EXTRACT say what to keep, SUBJECT TYPE the
   types a subject may have, TERM and ALIAS the base's vocabulary, VOCABULARY NOTE how to read it, EXCLUDE what never to extract.` (one
   line) and `Language: {language} — write every name as the text writes it.`
3. **EXTRACT** — the active `extraction` guidelines in (`position`, `id`) order; each body through `kn_compile_lines()`; each sentence
   one line `EXTRACT: <sentence>`. Titles are not sent.
4. **SUBJECT TYPE** — the template's `subject_types` in their stored order, then every distinct `subject_type` of a term not already
   listed, ascending; one line each `SUBJECT TYPE: <type>`; at most 40 lines.
5. **Vocabulary** — the active `vocabulary` guidelines' sentences as `VOCABULARY NOTE: <sentence>`; then one `TERM:` line per term and
   its `ALIAS:` lines, terms in (`lower(canonical_name)`, `id`) order:
   `TERM: <canonical> (<subject_type>) — <description, first 160 characters cut at a word boundary with …>` (the type and the
   description parts left out when empty), then `ALIAS: <alias> => <canonical>` per alias in stored order. An alias equal to its own
   canonical name (case-insensitive) is skipped; an alias already claimed by an earlier term (case-insensitive) is **not** written for
   the later term and is reported in `conflicts` (the vocabulary screen shows it); aliases are de-duplicated case-insensitively within
   a term.
6. **EXCLUDE** — the active `exclusion` guidelines' sentences as `EXCLUDE: <sentence>`.
7. **Output contract** (fixed words — the documents route's `candidate_edges` contract, which the route refuses to parse otherwise):
```
OUTPUT
Return ONLY JSON of the form {"candidate_edges":[{"subject_text":"","subject_type":"","verb_text":"","predicate":[{"attr_name":"","value_text":""}],"source_span":"","confidence":0.0}]}.
- subject_text: the subject's name as the text gives it; when the text uses an ALIAS, the TERM's canonical name instead.
- subject_type: one of the SUBJECT TYPE values; other when none fits.
- verb_text: a small canonical verb ("covers", not "is_covered_by").
- predicate: the statement's details as attributes — dates, durations, amounts, limits, versions, owners, status — one attr_name and value_text each.
- source_span: the sentence the statement comes from, copied VERBATIM from the text: the same characters, no paraphrase, no ellipsis, no added words. It is the passage an answer will quote, and a quote that is not in the text is thrown away.
- confidence: from 0 to 1.
- Never an edge from text an EXCLUDE line rules out, and never an edge whose source_span is not in the text.
- When the section states nothing worth keeping, return {"candidate_edges":[]}.
```
8. **The chunk** — the last two lines are always `Text:` and `{{chunk}}` (the documents route sends the template as ONE user message,
   no system prompt, and the fake provider of the proofs reads the text after the last `Text:` line).

**Caps** (characters, `mb_strlen`, counted on the finished lines): EXTRACT ≤ 6,000; VOCABULARY NOTE ≤ 2,000; TERM + ALIAS together ≤
8,000 (and ≤ 500 TERM lines); EXCLUDE ≤ 4,000; the whole template ≤ 24,000 before the chunk (the engine accepts 60,000 bytes; the rest
is the chunk's room in the model's context). Over a cap, **whole lines are dropped from the end of that block** — never cut mid-line —
and counted in `truncated[block]`; the guidelines screen and Preview show "3 EXTRACT lines did not fit — shorten the extraction
guidelines". A TERM dropped takes its ALIAS lines with it.

**Hash**: `sha256` = SHA-256 (hex) of the UTF-8 bytes of the finished `prompt_template`, `{{chunk}}` included. The base's guideline
*version* is not in the text: a version bump that changes no words changes no hash and re-sends nothing. "Built under N" is the
database's (`sections.extracted_version`, `guideline_versions.base_guideline_version`); the hash is the cross-check that the engine
holds the words of N.

**Determinism**: rows are sorted by the keys named above, never by the order the database returns them; the same inputs in any order
give the same bytes (the proof shuffles them). No clock, no locale, no randomness, no environment.

## Hints and projects of one section (slice 4 sends them with `kn_engine_ingest_section()`)
- **Hints** (`kn_compile_section_hints`): a term is a hint when its canonical name or one of its written (non-conflicting) aliases occurs
  in the section text — case-insensitive, whole words (`(?<![\p{L}\p{N}])…(?![\p{L}\p{N}])`, the same test as
  `kn_engine_expand_aliases()`); the **canonical** name is the hint. Then each seed term of the section's source's active areas that
  occurs the same way, as written. Ordered by the position of the first occurrence, then by name; duplicates removed
  (case-insensitive); at most `$max` (20). The documents route takes no hints: they become the document's **subject tags**
  (`subjects[]`), which the core attaches without the API's `MAX+1` subject route.
- **Projects** (`kn_compile_projects`): the section's source's active, engine-linked areas, as engine names `kb:<id>/<area name>`. A
  source in no area sends none.

## The answer system string (`kn_compile_answer_system_from`) — M1's `system` field and the expert's turn
Blocks in this order, a blank line between, an empty block omitted:
1. `KNOWLEDGE BASE kb:{n} — how to answer (compiled by Knowledge from the base's guidelines)`
2. `HOW TO ANSWER` + the active `answering` guidelines' bodies (normalised, whole, as paragraphs) in (`position`, `id`) order, a blank
   line between guidelines — ≤ 4,000.
3. `WHO IS ASKING` + the active `audience` guidelines' bodies, the same way — ≤ 1,500.
4. `LEAVE OUT OF ANSWERS` + the active `exclusion` guidelines' sentences, each `- <sentence>` — ≤ 1,200 (the design's "answer-time
   filter": the same exclusions the extractor obeys, told to the composer).
5. `FOCUS AREA: <name> — <description, first 600 characters cut at a word boundary with …>` — only when the question is scoped to an
   active area of the base.
6. `TERMS: ` + every term `Canonical (also: alias, alias)` (written aliases only) joined by `; `, ending `.` — ≤ 600, terms dropped from
   the end.

Over a block's cap, whole guidelines (or sentences, terms) are dropped from the end; a single guideline longer than its block is cut at a
word boundary with `…`. The total is ≤ 8,000 (the engine refuses more — `kn_engine_answer()` checks). **Answering and audience guidelines
apply at once**: they are compiled on every question, never sent to the namespace, never bump the version (the database's rule: they are
not in `kn_graph_shaping_kinds()`).

## Terms for the engine (`kn_compile_vocabulary_from`)
`terms` = every term in the prompt's order, `aliases` the written ones (conflicts removed), `subject_type` the term's or `other`,
`description` the term's — exactly what `kn_engine_seed_subjects()` takes; `aliases` = alias ⇒ canonical, what `kn_ask()` hands
`kn_engine_expand_aliases()`. The engine cannot store aliases (finding §0): `kn_engine_seed_subjects()` keeps them in the subject's
description, the prompt carries them as `ALIAS:` lines, and the question is expanded with the canonical term. Subjects are shared by
every base of the memory database (finding §0): a term's type and description are the last seeder's.

## When compile runs, and what is guaranteed
| Moment | Call | If the engine is down or `kb:0` is missing |
|---|---|---|
| a base is created (slice 1) | `kn_compile_send()` best-effort | nothing is refused; the base is created; the worker ensures before the first build |
| an extraction, vocabulary or exclusion guideline, or a term, is saved or deleted (slice 3) | `kn_compile_send()` best-effort; the handler's `did` says "the engine has the new guidelines" or "the engine will get them before the next build" | the save stands (the database already bumped the version and marked sections stale) |
| an answering or audience guideline is saved | nothing is sent | — |
| Preview on a section (slice 3) | `kn_compile_prompt($pdo, $base, $draft)` with `{{chunk}}` replaced by the section's text in PHP | Preview still works (it calls no engine); the engine's generic prompt is simply absent |
| **before a build's first item** (slice 4) | `kn_compile_ensure()` — **the guarantee**: the engine's stored prompt hashes to the compiled one, or it is re-sent; `model_not_configured` fails the build with its sentence | the build waits (`engine_unavailable`) or fails (`model_not_configured`) — it never extracts under words it cannot verify |
| a question (slice 5) | `kn_compile_answer_system()` | — (no engine call in compile) |
| the admin changes the model (slice 9) | `kn_compile_send()` for every live base | slice 9's |

The build writes the hash it ensured as the first line of `builds.log` (`prompt sha256=<hex> guideline_version=<n> terms=<k>
areas=<m>`) and in `build.start`'s `after.prompt_sha256` — the schema has no column for it (slice 4).

## Worked example 1 — the shipped `general` template, nothing changed (base 7, `language` en, no terms, no areas)
Inputs: the four template rows db/008 copied (`origin = template`, positions 1–4: extraction, exclusion, answering, audience — the
bodies of db/005), `subject_types` = product, system, organisation, person, place, policy, procedure, term, document, event.
**`prompt_template`** (byte for byte; the proof pins it as `tests/phase3/slice3/golden/general-7.txt`):
```
KNOWLEDGE BASE kb:7 — extraction instructions (compiled by Knowledge from the base's guidelines; template general v1)
You read ONE section of a document from this knowledge base and extract a knowledge graph from it: the subjects the section is about, and the statements it makes about them. Follow every line below. Lines starting EXTRACT say what to keep, SUBJECT TYPE the types a subject may have, TERM and ALIAS the base's vocabulary, VOCABULARY NOTE how to read it, EXCLUDE what never to extract.
Language: en — write every name as the text writes it.

EXTRACT: Extract the named things the documents are about — products, systems, people by role, organisations, places, policies, procedures, terms defined in the text — and the relationships stated between them.
EXTRACT: Keep every date, deadline, duration, amount, limit, version number and owner as a statement attached to the thing it describes.
EXTRACT: A heading names a subject; a definition is a statement about the defined term.
EXTRACT: Prefer the document's own words over paraphrase.

SUBJECT TYPE: product
SUBJECT TYPE: system
SUBJECT TYPE: organisation
SUBJECT TYPE: person
SUBJECT TYPE: place
SUBJECT TYPE: policy
SUBJECT TYPE: procedure
SUBJECT TYPE: term
SUBJECT TYPE: document
SUBJECT TYPE: event

EXCLUDE: Ignore page headers and footers, page numbers, running titles, tables of contents, copyright and confidentiality boilerplate, navigation text and anything that is clearly a template placeholder.
EXCLUDE: Do not extract personal contact details (phone numbers, personal email addresses, home addresses) about individuals.

OUTPUT
Return ONLY JSON of the form {"candidate_edges":[{"subject_text":"","subject_type":"","verb_text":"","predicate":[{"attr_name":"","value_text":""}],"source_span":"","confidence":0.0}]}.
- subject_text: the subject's name as the text gives it; when the text uses an ALIAS, the TERM's canonical name instead.
- subject_type: one of the SUBJECT TYPE values; other when none fits.
- verb_text: a small canonical verb ("covers", not "is_covered_by").
- predicate: the statement's details as attributes — dates, durations, amounts, limits, versions, owners, status — one attr_name and value_text each.
- source_span: the sentence the statement comes from, copied VERBATIM from the text: the same characters, no paraphrase, no ellipsis, no added words. It is the passage an answer will quote, and a quote that is not in the text is thrown away.
- confidence: from 0 to 1.
- Never an edge from text an EXCLUDE line rules out, and never an edge whose source_span is not in the text.
- When the section states nothing worth keeping, return {"candidate_edges":[]}.

Text:
{{chunk}}
```
(No vocabulary block: no vocabulary guideline and no term.) `lines` = extract 4, subject_type 10, exclude 2; `truncated` = []; `sha256`
= the hex of these bytes, pinned in the golden file's sidecar `general-7.sha256`.

**`system`** (`kn_compile_answer_system($pdo, 7)`):
```
KNOWLEDGE BASE kb:7 — how to answer (compiled by Knowledge from the base's guidelines)

HOW TO ANSWER
Answer only from the sources. Quote the exact words the answer relies on and cite the section they come from. When two sources disagree, say so and show both. When the sources are silent, say the knowledge base holds nothing on this — never guess. Keep to two paragraphs unless a list is clearer.

WHO IS ASKING
A colleague who has not read the documents and may be reading on a phone: plain language, the answer first, the detail after, no jargon that the sources do not themselves use.

LEAVE OUT OF ANSWERS
- Ignore page headers and footers, page numbers, running titles, tables of contents, copyright and confidentiality boilerplate, navigation text and anything that is clearly a template placeholder.
- Do not extract personal contact details (phone numbers, personal email addresses, home addresses) about individuals.
```

## Worked example 2 — every kind written (base 12 "Pump Service", template general, `language` en)
Inputs:
| id | kind | pos | active | origin | body |
|---|---|---|---|---|---|
| 101 | extraction | 1 | yes | person | `Pull out every pump model, part number, service interval and warranty term. A part number is a subject of type part; a service interval is a statement about the part it applies to.` |
| 102 | extraction | 2 | **no** | template | (the template's — inactive, so absent) |
| 103 | vocabulary | 1 | yes | person | `Model codes are written with a hyphen (ZP-9); always use the full product name as the subject.` |
| 104 | exclusion | 1 | yes | person | `Nothing about prices or discounts.` ⏎ `- Ignore the revision table at the end of each manual.` |
| 105 | answering | 1 | yes | person | `Answer in at most two short paragraphs.` ⏎ `Name the manual and section you are quoting.` |
| 106 | audience | 1 | yes | person | `Field technicians on a phone, gloves on: the step first.` |
| 107 | exclusion | 2 | yes | person | `Never copy {{text}} markers.` (a person's attempt at a placeholder) |

Terms: Zorblat Pump (equipment, aliases `ZP-9`, `the pump`, description `The circulation pump.`), Quillon Valve (equipment, `QV`),
Merrow Filter (part), Pump Housing (equipment, alias `the pump`). Area **Warranty** (active, linked, description `Coverage, exclusions and
claims for every pump model.`, seed terms `warranty`, `claim`).

**`prompt_template`** (`tests/phase3/slice3/golden/five-kinds-12.txt`; the OUTPUT block and the last two lines are example 1's, elided
here only):
```
KNOWLEDGE BASE kb:12 — extraction instructions (compiled by Knowledge from the base's guidelines; template general v1)
You read ONE section … EXCLUDE what never to extract.
Language: en — write every name as the text writes it.

EXTRACT: Pull out every pump model, part number, service interval and warranty term.
EXTRACT: A part number is a subject of type part; a service interval is a statement about the part it applies to.

SUBJECT TYPE: product
SUBJECT TYPE: system
SUBJECT TYPE: organisation
SUBJECT TYPE: person
SUBJECT TYPE: place
SUBJECT TYPE: policy
SUBJECT TYPE: procedure
SUBJECT TYPE: term
SUBJECT TYPE: document
SUBJECT TYPE: event
SUBJECT TYPE: equipment
SUBJECT TYPE: part

VOCABULARY NOTE: Model codes are written with a hyphen (ZP-9); always use the full product name as the subject.
TERM: Merrow Filter (part)
TERM: Pump Housing (equipment)
ALIAS: the pump => Pump Housing
TERM: Quillon Valve (equipment)
ALIAS: QV => Quillon Valve
TERM: Zorblat Pump (equipment) — The circulation pump.
ALIAS: ZP-9 => Zorblat Pump

EXCLUDE: Nothing about prices or discounts.
EXCLUDE: Ignore the revision table at the end of each manual.
EXCLUDE: Never copy { {text} } markers.

OUTPUT
…
Text:
{{chunk}}
```
`conflicts` = `[{alias: "the pump", kept_for: "Pump Housing", dropped_for: "Zorblat Pump"}]` (Pump Housing sorts first); `aliases` =
`{"the pump": "Pump Housing", "QV": "Quillon Valve", "ZP-9": "Zorblat Pump"}`. The `{{text}}` in 107 is neutralised.

**Hints** for a section of a source assigned to Warranty whose text is `Replace the ZP-9 seal every 2,000 hours. The Merrow Filter is
checked weekly. A claim needs the serial number.` → `["Zorblat Pump", "Merrow Filter", "claim"]` (first occurrences at characters 12, 45, 80).
**Projects** → `["kb:12/Warranty"]`.

**`system`** scoped to Warranty (`kn_compile_answer_system($pdo, 12, <warranty id>)`):
```
KNOWLEDGE BASE kb:12 — how to answer (compiled by Knowledge from the base's guidelines)

HOW TO ANSWER
Answer in at most two short paragraphs.
Name the manual and section you are quoting.

WHO IS ASKING
Field technicians on a phone, gloves on: the step first.

LEAVE OUT OF ANSWERS
- Nothing about prices or discounts.
- Ignore the revision table at the end of each manual.
- Never copy { {text} } markers.

FOCUS AREA: Warranty — Coverage, exclusions and claims for every pump model.

TERMS: Merrow Filter; Pump Housing (also: the pump); Quillon Valve (also: QV); Zorblat Pump (also: ZP-9).
```

## Files (exactly these)
- `app/knowledge/compile.php` (requires `app/knowledge/engine.php` and `app/knowledge/extract/text.php`)
- `tests/phase3/slice3/compile.php` and `tests/phase3/slice3/golden/{general-7.txt,general-7.sha256,five-kinds-12.txt,five-kinds-12.sha256,
  system-7.txt,system-12-warranty.txt}` — built and proven with slice 3 (the module ships in slice 3; slice 1 calls `kn_compile_send()` only
  if the file exists, so slice 1 may be built first)

## Out of scope
A model dry run (M5); compiling for structured documents (Extended); per-question cost; anything that writes a table; sending the
credential (the owner's, once, into `kb:0`).

## Proof (`tests/phase3/slice3/compile.php`, run by slice 3's `run.sh`; the cores need no database and no network)
- [ ] **Golden**: example 1 and example 2 reproduce their golden files byte for byte, and their SHA-256 sidecars.
- [ ] **Determinism**: each example compiled twice → identical; guidelines and terms shuffled → identical; a version bump with no word
  changed → the same hash; one character changed in an extraction body → a different hash; an answering change → the prompt hash unchanged.
- [ ] **Normalising**: CRLF, C0/C1 controls, a BOM, tabs, five blank lines, decomposed accents (NFC) normalised; `{{chunk}}` and `{{text}}`
  in a body and in a term's description neutralised — the finished template contains `{{chunk}}` exactly once, on its last line, and no
  `{{text}}`.
- [ ] **Lines**: bullets and numbering stripped; `e.g. two` not split; `Done. Next` split; empty lines dropped.
- [ ] **Blocks**: an inactive guideline absent; a base with no exclusion has no EXCLUDE block and no blank line for it; subject types in
  template order then new term types ascending; at most 40.
- [ ] **Vocabulary**: TERM lines in canonical order; the self-alias skipped; the duplicate alias reported in `conflicts`; descriptions cut at
  160 on a word boundary; `kn_compile_vocabulary_from()` gives `kn_engine_seed_subjects()`'s exact shape.
- [ ] **Caps**: 7,000 characters of extraction → EXTRACT ≤ 6,000 with whole lines dropped and `truncated.extract` counted; 600 terms → 500
  TERM lines and their aliases only; the whole template ≤ 24,000.
- [ ] **Answer system**: example outputs pinned; the FOCUS AREA line only with an active area of the same base; a 9,000-character answering
  guideline cut with `…` and the total ≤ 8,000; `kn_engine_answer()` accepts it.
- [ ] **Hints and projects**: example 2's hints and order; alias matched on word boundaries only (`QVX` is not `QV`); 30 matching terms →
  20; an unlinked or archived area sends no project.
- [ ] **Against the engine** (slice 3's real API on the scratch memory database): `kb:0` absent → `kn_compile_send()` `model_not_configured`
  and nothing sent; `kb:0` configured with the fake provider's connection and a secret → a base's namespace configured with the secret by
  name (the response never carries the credential); `kn_compile_engine_sha('kb:12')` equals the compiled hash; `kn_compile_ensure()` sends
  nothing the second time (`sent = false`) and sends after a guideline save; the fake provider, given a section, obeys the compiled SUBJECT
  TYPE, ALIAS and EXCLUDE lines (its `/last` request carries the compiled text and the section after `Text:`).

## Built and proven
**Built 2026-10-09 with slice 3; proven by `tests/phase3/slice3/compile.php` (46 checks, in `tests/phase3/slice3/run.sh`).** The golden files were
written from this spec's own worked examples (example 2's elided OUTPUT block and preamble filled from example 1's) and the module reproduces all
four byte for byte on the first run. Settled in the build:
- The caps are measured on a block's finished lines joined by newlines; a block over its cap loses whole lines from its end; the whole template
  over 24,000 loses TERM groups from the end (with the block caps as they are it never triggers). `truncated` keys: `extract`, `subject_type`,
  `vocabulary_note`, `term` (TERM groups, aliases with them), `exclude`. With an alias on every term, the 8,000-character TERM + ALIAS cap binds
  long before the 500-line one; with short alias-less terms the 500 lines bind (both proven).
- An answering or audience guideline over its block is cut at a word with `…`; every later one of the kind is dropped (counted).
- A draft (`kn_compile_with_draft()`) replaces its row by `guideline_id` keeping the row's position unless the draft names one; a new draft sorts
  last in its kind.
- `kn_compile_connection()` reads the reference config's `extraction_alias` (the engine's name for the alias) and `secret_ref`; a base's namespace
  is configured with `secret_name` and no token — proven: no response carries the credential.
- `deploy/ROOT_STEPS.sh` step 5 already names `kb:0` (divergence 1's warning is settled).
- **Found against the real engine — M12.** The core refuses a statement whose subject type is not in its catalogue, and the `general` template's
  types (product, system, organisation, place, policy, procedure, term, document) are not in it: every statement the model typed with one was
  refused (`422 unknown subject_type product`). Fixed upstream on `feat/documents-register-types-m12` (MaluDB API 0.4.1, PR #19 — the documents
  route registers an unseen type the way graph import does, else falls back and keeps the declared type as a predicate attribute); **merged and live
  2026-10-10**; the Phase 3 proofs run the API from the main checkout (`tests/phase3/engine.sh`, `KN_ENGINE_API_DIR`).

## Open questions
