# maludb-os-knowledge — Knowledge

MaluDB Business OS application: **Knowledge** — the business's knowledge bases. **Upload documents** (PDF, Word and Office, HTML,
Markdown, text, pasted text, a web page, a sibling application's pages or articles as a feed), optionally write **guidelines**
(what to extract, what the words mean, what to ignore, how to answer, for whom) and name **focus areas**, and the application
cuts the documents into sections and builds a **knowledge graph in MaluDB** — subjects, statements, communities — that is asked
three ways:

- an **ask-me-anything window**: every answer **cites the exact words it relies on**, verified against the sources by the
  engine; a citation opens the section and page; weak evidence is a plain "nothing on this" and a logged gap; thumbs; curators
  pin verified answers,
- a **token API** (`POST /api/v1/ask`, `/search`) with keys bound to one base, rate-limited,
- the kernel's **agents** (the command bar, a mention in Spaces) and the **siblings** (Help Desk's expert answering a ticket
  from the manuals) through K7 shares — only for bases shared with them.

The easy path is three steps: create a base, drop documents in, ask. Guidelines and areas improve the graph and are never
required; after a first build the expert **proposes focus areas** from the graph's communities and **drafts guidelines** from a
sample. Two shipped agents: the **expert** and the **Archivist** (a Monday report per base: failed and stale sources, changed
URLs, the questions nobody could answer, unverified answers, pins due for review).

The content does not matter: the first plan was written around laws and fire codes, and that design (a code parsed into
provisions with amendments, editions and effective dates) is kept as the Extended **structured-documents** module.

An optional application installed beside [maludb-os-core](https://github.com/maludb/maludb-os-core) on request. Built with
`htmx-php-builder`, fitted to `maludb-os-integration` 0.7.0, served as `knowledge.<domain>`; catalog key `knowledge`. The
engine is MaluDB (`maludb_core` 0.106.0, the MaluDB API with its answer route) in a memory database of its own, one namespace
per base; the engine's model calls are metered through the kernel's ledger proxy.

- **The plan:** `docs/knowledge-design.md` (Phase 0, 2026-10-05, general product, decisions D1–D16 and rulings R1–R3 in §15) and `docs/structured-documents-extended.md` (the Extended module).
- **The build:** `CLAUDE.md` — the order, the rules, the handoff to the worker model.
- Status: plan approved; Phase 0 complete; Phase 1 approved by the owner 2026-10-06; Phase 2 (sign-on, the mirror, the shell on Apache) built and proven 2026-10-06 (`tests/phase2/run.sh`); slice 1 (bases, templates, sharing, settings) built and proven 2026-10-09 — 231 checks under php -S and under a real Apache (`tests/phase3/slice1/run.sh`); slice 2 (sources and sectioning, the first exemplar) built and proven 2026-10-09 — 192 checks against the real engine (`tests/phase3/slice2/run.sh`); slice 3 (guidelines, vocabulary, focus areas, the compile module) built and proven 2026-10-09 — 208 checks (`tests/phase3/slice3/run.sh`); K24, K25 built; M1 merged and live (API 0.4.0); M12 (API 0.4.1, PR #19) merged and live 2026-10-10; slice 4 (the build — sections into the graph) built and proven 2026-10-10 — 173 checks (`tests/phase3/slice4/run.sh`); slice 5 (ask, the second exemplar) built and proven 2026-10-10 — 231 checks (`tests/phase3/slice5/run.sh`); slices 6–9 built 2026-10-10 — PHASE 3 COMPLETE (61/61 screens, 62/62 actions); next Phase 4, the MCP servers.
