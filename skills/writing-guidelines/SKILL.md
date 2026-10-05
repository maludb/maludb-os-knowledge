---
name: writing-guidelines
description: How to write the five kinds of guideline for a base — extraction, vocabulary, exclusion, answering and audience — with concrete examples for manuals, policies, contracts and research; what each changes in the engine, which need a rebuild, and how to preview before spending. Use when drafting or reviewing a base's guidelines.
---

# Writing guidelines

A guideline is one instruction, in plain words, of one kind. A curator writes it; you may draft and save it
(`guideline_save`, `vocabulary_save`). **Preview before you save**: `extraction_preview` puts one real section into the compiled
prompt — with your draft in place (`draft`) — and shows exactly what the model will be told, without spending a build. It is a dry run of
the prompt, not of the model (MaluDB M5), so judge whether the instructions say what you mean.

## The five kinds
| Kind | It tells the engine | Takes effect |
|---|---|---|
| `extraction` | What counts as a subject, a statement, a relationship; what to pull out | Graph-shaping: sections become **stale**, a build re-reads them |
| `vocabulary` | The base's own terms, their aliases and types (kept as terms with `vocabulary_save`) | Graph-shaping: terms are seeded before the build |
| `exclusion` | What to ignore or never extract | Graph-shaping, and an answer-time filter |
| `answering` | The shape of an answer: length, style, how to treat disagreement or silence | At once, no rebuild |
| `audience` | Who is asking and how they read | At once, no rebuild |

A graph-shaping save raises the base's guideline version. Check `guideline_versions` and `build_estimate` before `build_start`.

## Write them like this
- **One instruction each, concrete, testable.** "Extract every product, its version, its owner and the date it changed" beats
  "extract important things".
- **Name the type of thing** ("a deadline is a statement; a department is a subject"), not only the word.
- **Say what to leave out** in an exclusion, not inside extraction.
- **Never ask for what the sources do not hold.** A guideline cannot make the base know more.
- Keep each under a short paragraph; split rather than join.

## Examples by kind of corpus
**Manuals** — extraction: "Pull out every part, procedure, tool, warning and torque or time value; a procedure step belongs to its
procedure." vocabulary: "PSU = power supply unit = power supply, a *part*." exclusion: "Ignore page headers, footers and revision
tables." answering: "Answer as numbered steps when the question is how to; quote any warning in full." audience: "Field
technicians reading on a phone."

**Policies** — extraction: "Pull out every rule, who it applies to, the exception, the owner and the effective date." vocabulary:
"PTO = paid time off = vacation, a *benefit*." exclusion: "Never extract personal data about named employees." answering: "State the
rule, then the exception; say when two policies disagree and name both." audience: "Employees who have not read the handbook."

**Contracts** — extraction: "Pull out parties, term, renewal, payment amounts and dates, termination rights, liability limits,
and defined terms with their definitions." vocabulary: "Customer = Client = Buyer, a *party*." exclusion: "Ignore signature
blocks and boilerplate footers." answering: "Quote the clause; give the clause number; never summarise a limit of liability, quote
it." audience: "A manager, not a lawyer; plain English after the quote."

**Research** — extraction: "Pull out each study, its method, sample size, finding, limitation and the claim it supports or
contradicts." vocabulary: "LLM = large language model, a *technology*." exclusion: "Ignore reference lists and acknowledgements."
answering: "Say how many sources support a claim; flag claims from one source only." audience: "An analyst deciding what to read."

## Working method
1. Read two or three real sections (`get_section`) first.
2. Draft one guideline of each kind that the corpus needs; the template's shipped set is the starting point (`find_guidelines`).
3. `extraction_preview` with your draft on a typical section and an awkward one; adjust until the prompt asks for what a person would
   pull out.
4. Save; then `build_estimate`; then `build_start` for the stale sections only.

See also the runbook `draft-guidelines-from-a-sample`.
