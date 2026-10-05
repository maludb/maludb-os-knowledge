# maludb-os-knowledge — Knowledge

MaluDB Business OS application: **Knowledge** — the business's knowledge bases, **built first for laws, codes and
regulations**. A person **uploads documents** (PDF, Office and Word exports, HTML, Markdown, text, pasted text, a web page, a
sibling application's pages or articles as a feed), the application **parses a code into its provisions** (chapters,
sections, exceptions, tables, definitions, cross-references) and layers the jurisdiction's **amendments** over the model
edition with precedence and effective dates (the parser proposes, a curator confirms), the person writes **guidelines** (a
shipped template for codes: what to extract, what the words mean, what never to say, how to answer, for whom) and names
**focus areas** (a code's chapters), and the application builds a **knowledge graph in MaluDB** — one document per effective
provision, subjects, statements, communities — that is asked three ways:

- an **ask-me-anything window** — as of a date, with the facts a code question needs; every answer **quotes the provision
  verbatim** with its number, edition and jurisdiction, lists the exceptions, flags the amendments, keeps thresholds exact,
  ends with "confirm with the authority having jurisdiction", or declines and logs a gap; a citation opens the provision with
  its amendment chain; thumbs; curators pin verified answers,
- a **token API** (`POST /api/v1/ask`, `/search`, `GET /provisions/{number}`) with keys bound to one base, rate-limited,
- the kernel's **agents** (the command bar, a mention in Spaces) and the **siblings** (Help Desk's expert answering a ticket
  from the manuals) through K7 shares — only for bases shared with them.

Two shipped agents: the **expert** (answers with quotations, asks for missing facts, proposes focus areas from the chapters,
drafts guidelines from a sample) and the **Archivist** (a Monday report per base: unreviewed amendments, unresolved
references to standards not held, failed and stale sources, the questions nobody could answer, pins due for review).

The proof corpus: the fire codes of the **City of Chicago** (the 2018 International Fire Code as Title 14F with Chicago's
amendments, and Title 15) and of **Cook County** (the 2021 IFC with the Chapter 102 amendments), with the NFPA standards they
reference as bases of their own. A general template (NotebookLM-shaped: sections, communities, citations by heading and page)
ships beside the regulatory one.

An optional application installed beside [maludb-os-core](https://github.com/maludb/maludb-os-core) on request. Built with
`htmx-php-builder`, fitted to `maludb-os-integration` 0.7.0, served as `knowledge.<domain>`; catalog key `knowledge`. The
engine is MaluDB (`maludb_core` 0.106.0, the MaluDB API) in a memory database of its own, one namespace per base. No licensed
code text is ever committed to this repository.

- **The plan:** `docs/knowledge-design.md` (Phase 0, 2026-10-05, revised the same day for the regulatory corpus — awaiting the
  owner's twenty decisions, §13; three answers recorded, §15).
- **The build:** `CLAUDE.md` — the order, the rules, the handoff to the worker model.
- Status: plan written; nothing built yet.
