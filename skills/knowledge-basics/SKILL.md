---
name: knowledge-basics
description: What Knowledge is and how an agent works in it — what a base, source, section, guideline of each kind, template, focus area, build, question and key are; which tool answers which question; always cite, never guess, say which base answered. The one page every agent that uses Knowledge reads first.
---

# Knowledge — the basics for an agent

Knowledge holds the business's **knowledge bases**: documents go in, are cut into sections, are built into a graph, and are
asked. Every answer **cites a section or declines**. You are a member here: you read what your member id may read, and a few
actions pause for a person.

## The words
- **Base** — one corpus (a handbook, a policy set, a contract library, a body of research) with a template, guidelines, focus
  areas and one graph. A new base is private; its curators decide who else reads it.
- **Source** — one document, pasted text, fetched page, note or fed record. Its status says whether it has text (`no_text` for
  a scan with no text layer) and whether it is `ready`.
- **Section** — one heading-delimited part of a source, found by its own structure (heading styles, slides, sheets, numbered
  headings; plain text by paragraphs). The section is the unit of citation and of a build. A citation is a base, a source, a
  section's heading path and a page.
- **Guideline** — one instruction to the engine, of five kinds: `extraction`, `vocabulary`, `exclusion`, `answering`,
  `audience` (see `writing-guidelines`). The first three shape the graph; a save marks sections **stale** until a build re-reads
  them. The last two apply at once.
- **Template** — a shipped set of guidelines. `general` is the first. A base may switch template only while it is empty.
- **Focus area** — a named slice of a base (terms, example questions). A question scoped to an area is answered from that area.
- **Build** — one extraction run: new and stale sections go through the engine; one build per base at a time.
- **Question** — one ask with its answer, evidence and citations. Its status: `answered`, `evidence_only`, `no_evidence`,
  `unverified`, `refused`, `failed`.
- **Key** — a token bound to one base for an outside system; minting one pauses for a person.

## Which tool answers what
| You need | Tool |
|---|---|
| Which bases may I read; one base's size and last build | `find_bases`, `get_base` |
| Sources, one source, its outline, a section, a page | `find_sources`, `get_source`, `source_outline`, `get_section`, `source_page`, `source_excerpt` |
| Answer this from the base, with citations | `ask` |
| The evidence on this, with no composed answer | `search` |
| What questions could this base answer | `suggested_questions` |
| What did I ask; one question and its citations | `my_questions`, `get_question` |
| Subjects, neighbours, paths, communities | `get_subject`, `subject_neighbors`, `subject_statements`, `graph_path`, `communities`, `community_members`, `god_nodes`, `surprises`, `graph_query`, `graph_stats` |
| Which sections stand behind a subject or source | `sections_for_subject`, `section_graph`, `source_graph` |
| Guidelines, vocabulary, areas (curators) | `find_guidelines`, `guideline_versions`, `extraction_preview`, `find_vocabulary`, `unused_terms`, `find_focus_areas`, `get_focus_area` |
| Builds and their cost (curators) | `find_builds`, `build_progress`, `build_estimate`, `base_usage` |
| What the base could not answer; ratings (curators) | `question_gaps`, `questions`, `question_feedback` |
| Who changed what | `record_history`, `recent_activity`, `actor_timeline` |

## The rules
1. **Always cite.** Name the base, the section (heading path) and the page for every claim. No citation, no claim.
2. **Never guess.** If `ask` returns `no_evidence`, the base holds nothing on it; say so. If it returns `unverified`, the quote
   could not be verified; say that, and read the section yourself with `get_section` before relying on it.
3. **Say which base answered**, and which focus area, when one was used.
4. **Ask a clarifying question** when several bases or readings fit, before you spend a call.
5. **Alone**: ask, read, write guidelines, propose areas, start a build (new and stale sections, under the limit), pin an
   answered question, add pasted text or a public address. **Pauses**: minting, rotating or revoking a key, removing a source,
   sharing to a guest or an application, private to internal, removing someone from a base, deleting anything, purging questions,
   rebuilding everything, changing the model, the settings, the limits, retention or which agents are named. Never ask for these
   unprompted.
6. **No secrets** in a source, an answer or a message. Source and question text are never put in a log.

More: `references/question-statuses.md`.
