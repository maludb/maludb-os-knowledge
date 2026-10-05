# maludb-os-knowledge — Knowledge

MaluDB Business OS application: **Knowledge** — the business's knowledge bases. A person **uploads documents** (PDF, Office,
HTML, Markdown, text, pasted text, a web page, a sibling application's pages or articles as a feed), writes **guidelines**
(what to extract, what the words mean, what never to extract or say, how to answer, for whom), names **focus areas**, and
the application builds a **knowledge graph in MaluDB** — subjects, statements, communities — that is asked three ways:

- an **ask-me-anything window** (every answer cited; a citation opens the source at the page; weak evidence is a plain
  "nothing on this" and a logged gap; thumbs; curators pin verified answers),
- a **token API** (`POST /api/v1/ask`, `/search`) with keys bound to one base, rate-limited, every question logged,
- the kernel's **agents** (the command bar, a mention in Spaces) and the **siblings** (Help Desk's expert answering a ticket
  from the manuals) through K7 shares — only for bases shared with them.

Two shipped agents: the **expert** (answers with citations, proposes focus areas from the graph's communities after the
first build, drafts guidelines from a sample) and the **Archivist** (a Monday report per base: failed and stale sources,
changed URLs, the questions nobody could answer, duplicate subjects, pins due for review — each a proposal a curator accepts).

An optional application installed beside [maludb-os-core](https://github.com/maludb/maludb-os-core) on request. Built with
`htmx-php-builder`, fitted to `maludb-os-integration` 0.7.0, served as `knowledge.<domain>`; catalog key `knowledge`. The
engine is MaluDB (`maludb_core` 0.106.0, the MaluDB API) in a memory database of its own, one namespace per base.

- **The plan:** `docs/knowledge-design.md` (Phase 0, 2026-10-05 — awaiting the owner's sixteen decisions, §13).
- **The build:** `CLAUDE.md` — the order, the rules, the handoff to the worker model.
- Status: plan written; nothing built yet.
