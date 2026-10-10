"""Sources and sections (S1, S2, S5): find_sources, get_source, source_outline, get_section, source_page, source_excerpt, find_feeds. docs/knowledge-mcp-tool-surface.md "Sources and sections".
A section's text IS content a Reader may read (that is how an answer is trusted): mcp_sections serves it, blank when forgotten; kn_section_markdown() is called only for a section that is not forgotten.
A source's full text is in no view. The graph tools of S3/S4 (section_graph, source_graph, sections_for_subject) are in kn_graph — they go through the bridge."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from kn_common import RO, Ref, Refusal, Where, _Base, answers, area_ref, base_ref, cut, describe_models, dumps, lim, like, off, plain, q_alone, qmatch, section_ref, source_ref

SOURCE_KINDS = Literal["file", "text", "url", "feed", "note"]
SOURCE_STATUSES = Literal["uploaded", "extracting_text", "sectioning", "ready", "no_text", "failed", "forgotten"]


class FindSourcesIn(_Base):
    base: Ref | None = Field(None, description="The base (key, name or id); required unless q is given")
    q: str | None = Field(None, max_length=200, description="Words of a source's title; with nothing else it resolves a source across my bases")
    kind: SOURCE_KINDS | None = Field(None, description="Only this kind of source")
    status: SOURCE_STATUSES | None = Field(None, description="Only sources in this state")
    area: Ref | None = Field(None, description="Only sources assigned to this focus area (slug or id)")
    stale: bool | None = Field(None, description="True: only sources with a stale section; False: only those with none")
    include_forgotten: bool = Field(False, description="Include forgotten sources (a curator's)")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class GetSourceIn(_Base):
    source: Ref = Field(..., description="The source (id or title)")


class OutlineIn(_Base):
    source: Ref = Field(..., description="The source (id or title)")
    depth: int = Field(9, ge=1, le=9, description="How deep a heading level to list")


class GetSectionIn(_Base):
    section: int = Field(..., ge=1, description="The section id (from source_outline)")
    with_neighbours: bool = Field(True, description="Also name the previous and next section of the source")


class SourcePageIn(_Base):
    source: Ref = Field(..., description="The source (id or title)")
    page: int = Field(..., ge=1, description="The page number (a PDF page, a slide, a sheet)")


class ExcerptIn(_Base):
    section: int | None = Field(None, ge=1, description="The section id; or give question and n")
    question: int | None = Field(None, ge=1, description="A question id whose citation n is excerpted")
    n: int | None = Field(None, ge=1, le=50, description="Which citation of that question (1 …)")
    quote: str | None = Field(None, max_length=500, description="The words to find (default: the citation's quote)")
    window: int = Field(600, ge=100, le=1500, description="How many characters to show on each side")


class FindFeedsIn(_Base):
    base: Ref | None = Field(None, description="The base; required unless q is given")
    q: str | None = Field(None, max_length=100, description="The feed's provider.tool (resolves one)")
    enabled: bool | None = Field(None, description="Only enabled or only stopped feeds")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


describe_models(globals())

SOURCE_COLS = """s.source_id, s.base_id, b.name AS base_name, s.kind, s.title, s.status, s.sections_count, s.char_count, s.page_count, s.is_stale, s.built_under, s.focus_area_ids,
                 left(s.error, 300) AS error, s.added_by_name, s.added_at, s.url_checked_at, s.superseded_by, CASE WHEN s.kind = 'url' THEN s.origin END AS origin"""


def _norm(text: str) -> tuple[str, list[int]]:
    """The text with whitespace runs collapsed to one space and lower-cased, and for each character of the result the index of the original character it came from."""
    out: list[str] = []
    idx: list[int] = []
    prev_space = True
    for i, ch in enumerate(text):
        if ch.isspace():
            if not prev_space:
                out.append(" ")
                idx.append(i)
            prev_space = True
        else:
            out.append(ch.lower())
            idx.append(i)
            prev_space = False
    return "".join(out), idx


def find_quote(text: str, quote: str) -> tuple[int, int] | None:
    """[start, end) of the quote in the text, ignoring case and whitespace runs; None when it is not there."""
    nt, idx = _norm(text)
    nq, _ = _norm(quote.strip())
    nq = nq.strip()
    if not nq:
        return None
    at = nt.find(nq)
    if at < 0:
        return None
    return idx[at], idx[at + len(nq) - 1] + 1


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="find_sources", annotations={"title": "Find sources", **RO})
    @answers
    async def find_sources(params: FindSourcesIn) -> str:
        """The sources of a base by kind, status and focus area; which are stale, failed or without text. `q` alone resolves a source by its title across my bases (a plain list of {source_id, title}).
        Forgotten sources are shown to a base's curators only. A file source's origin is its name, never a storage path."""
        p = params
        if p.base in (None, "") and not p.q:
            raise Refusal("invalid_argument", "Name the base, or give q to find a source by its title.", fields=["base"])
        w = Where()
        base_id = int((await base_ref(q, p.base))["base_id"]) if p.base not in (None, "") else None
        if base_id is not None:
            w.add("s.base_id = {}", base_id)
        qmatch(w, p.q, "s.source_id", "s.title")
        if p.kind:
            w.add("s.kind = {}", p.kind)
        if p.status:
            w.add("s.status = {}", p.status)
        if p.area not in (None, ""):
            area = await area_ref(q, p.area, base_id)
            w.add("{} = ANY (s.focus_area_ids)", int(area["focus_area_id"]))
        if p.stale is not None:
            w.add("s.is_stale = {}", p.stale)
        if p.include_forgotten:
            w.raw("(s.status <> 'forgotten' OR s.base_id IN (SELECT kn_curated_base_ids()))")
        elif p.status != "forgotten":
            w.raw("s.status <> 'forgotten'")
        rows = await q(f"SELECT {SOURCE_COLS} FROM mcp_sources s JOIN mcp_bases b ON b.base_id = s.base_id WHERE {w.sql()} ORDER BY s.added_at DESC, s.source_id DESC "
                       f"LIMIT {lim(p.limit)} OFFSET {off(p.offset)}", *w.args)
        if q_alone(p):
            return plain(rows, "source_id", "title", "base_name")
        return dumps(rows)

    @mcp.tool(name="get_source", annotations={"title": "One source", **RO})
    @answers
    async def get_source(params: GetSourceIn) -> str:
        """One source: where it came from, its status and error, its pages, its focus areas, its file (name, type, size), and its top-level outline (headings only — read a section with get_section)."""
        s = await source_ref(q, params.source)
        sid = int(s["source_id"])
        pages = (await q("SELECT count(*) AS n, min(page_no) AS first, max(page_no) AS last FROM mcp_source_pages WHERE source_id = $1", sid))[0]
        file = None
        if s["attachment_id"] is not None:
            f = await q("SELECT filename, mime_type, byte_size FROM mcp_attachments WHERE attachment_id = $1", int(s["attachment_id"]))
            file = f[0] if f else None
        areas = await q("SELECT focus_area_id, name, slug, status FROM mcp_focus_areas WHERE focus_area_id = ANY ($1::bigint[]) ORDER BY position, name", list(s["focus_area_ids"] or []))
        outline = await q("SELECT section_id, level, pos, heading, heading_path, page_no, char_count, status FROM kn_source_outline($1) WHERE level = (SELECT min(level) FROM kn_source_outline($1)) ORDER BY pos LIMIT 50", sid)
        src = {k: s[k] for k in ("source_id", "base_id", "kind", "title", "status", "sections_count", "char_count", "page_count", "language", "is_stale", "built_under", "focus_area_ids",
                                 "added_by_name", "added_at", "url_checked_at", "url_changed_at", "superseded_by", "updated_at")}
        src["error"] = cut(s["error"], 300)
        src["origin"] = s["origin"] if s["kind"] == "url" else (s["title"] if s["kind"] == "file" else None)
        return dumps({"source": src, "pages": {"count": pages["n"], "first": pages["first"], "last": pages["last"]}, "file": file, "areas": areas, "outline": outline})

    @mcp.tool(name="source_outline", annotations={"title": "A source's outline", **RO})
    @answers
    async def source_outline(params: OutlineIn) -> str:
        """What a source contains, as a tree of headings in reading order, without the text: one line per section — `- Heading (p. 4) [#123]` — plus the rows (section_id, parent_id, level, kind, page,
        size, status). At most 500 sections, then `truncated`. Read a section's words with get_section."""
        s = await source_ref(q, params.source)
        rows = await q("SELECT section_id, parent_id, level, pos, heading, heading_path, kind, page_no, char_count, status, extracted_version FROM kn_source_outline($1) WHERE level <= $2 ORDER BY pos",
                       int(s["source_id"]), params.depth)
        truncated = len(rows) > 500
        rows = rows[:500]
        top = min((r["level"] for r in rows), default=1)
        lines = ["  " * max(0, int(r["level"]) - int(top)) + f"- {r['heading']}" + (f" (p. {r['page_no']})" if r["page_no"] is not None else "") + f" [#{r['section_id']}]" for r in rows]
        return dumps({"source": {"id": s["source_id"], "title": s["title"]}, "markdown": "\n".join(lines), "sections": rows, "truncated": truncated})

    @mcp.tool(name="get_section", annotations={"title": "Read a section", **RO})
    @answers
    async def get_section(params: GetSectionIn) -> str:
        """WHAT A SECTION SAYS: its heading path, its words as Markdown, the source and the page it came from, and the neighbouring sections. The tool an agent reads with. A forgotten section answers its
        heading and '(forgotten)' and no words."""
        s = await section_ref(q, params.section)
        sid = int(s["section_id"])
        src = (await q("SELECT source_id, title, kind FROM mcp_sources WHERE source_id = $1", int(s["source_id"])))[0]
        if s["status"] == "forgotten":
            markdown = f"# {s['heading_path']}\n\n(forgotten)"
        else:
            md = (await q("SELECT kn_section_markdown($1) AS m", sid))[0]["m"]
            markdown = md if md is not None else f"# {s['heading_path']}\n\n(forgotten)"
        doc = {"section_id": sid, "base_id": s["base_id"], "source": {"id": src["source_id"], "title": src["title"], "kind": src["kind"]}, "heading_path": s["heading_path"], "kind": s["kind"],
               "level": s["level"], "page_no": s["page_no"], "status": s["status"], "extracted_version": s["extracted_version"], "char_count": s["char_count"], "markdown": markdown}
        if params.with_neighbours:
            nb = await q("""SELECT (SELECT x.section_id FROM mcp_sections x WHERE x.source_id = $1 AND x.position < $2 AND x.status <> 'forgotten' ORDER BY x.position DESC LIMIT 1) AS prev_section_id,
                                   (SELECT x.section_id FROM mcp_sections x WHERE x.source_id = $1 AND x.position > $2 AND x.status <> 'forgotten' ORDER BY x.position LIMIT 1) AS next_section_id""",
                         int(s["source_id"]), int(s["position"]))
            doc.update(nb[0])
        cur = await q("SELECT i_curate FROM mcp_bases WHERE base_id = $1", int(s["base_id"]))
        if cur and cur[0]["i_curate"]:
            doc["maludb_document_id"] = s["maludb_document_id"]
        return dumps(doc)

    @mcp.tool(name="source_page", annotations={"title": "The sections on a page", **RO})
    @answers
    async def source_page(params: SourcePageIn) -> str:
        """One page of a paged source (a PDF page, a slide, a sheet): the sections that sit on it, with their words as Markdown. At most 12 sections and 24 kB, then `truncated`. A section is shown whole,
        so a page's sections can run a little past its edge."""
        s = await source_ref(q, params.source)
        pg = await q("SELECT char_from, char_to FROM mcp_source_pages WHERE source_id = $1 AND page_no = $2", int(s["source_id"]), params.page)
        if not pg:
            raise Refusal("not_found", f"This source has no page {params.page}.")
        total = (await q("SELECT count(*) AS n FROM mcp_source_pages WHERE source_id = $1", int(s["source_id"])))[0]["n"]
        rows = await q("""SELECT section_id, heading_path, text FROM mcp_sections WHERE source_id = $1 AND status <> 'forgotten' AND char_from < $3 AND char_to > $2 ORDER BY position LIMIT 13""",
                       int(s["source_id"]), int(pg[0]["char_from"]), int(pg[0]["char_to"]))
        out, size, truncated = [], 0, len(rows) > 12
        for r in rows[:12]:
            md = f"# {r['heading_path']}\n\n{r['text']}"
            if size + len(md.encode()) > 24000:
                truncated = True
                break
            size += len(md.encode())
            out.append({"section_id": r["section_id"], "heading_path": r["heading_path"], "markdown": md})
        return dumps({"source": {"id": s["source_id"], "title": s["title"]}, "page": params.page, "page_count": total, "sections": out, "truncated": truncated})

    @mcp.tool(name="source_excerpt", annotations={"title": "The text around a quote", **RO})
    @answers
    async def source_excerpt(params: ExcerptIn) -> str:
        """THE TEXT AROUND A CITATION: the words of a quote with a little before and after, inside its section — never across the section's edge. Give `section` and a `quote`, or a `question` id and the
        citation number `n` (the quote is then the citation's). Case and whitespace runs are ignored. When the quote is not in the section you get found false and the section's opening in `after`."""
        p = params
        quote = p.quote
        if p.section is None:
            if p.question is None or p.n is None:
                raise Refusal("invalid_argument", "Give a section, or a question and the citation number n.", fields=["section", "question", "n"])
            c = await q("SELECT section_id, quote FROM mcp_citations WHERE question_id = $1 AND rank = $2", p.question, p.n)
            if not c or c[0]["section_id"] is None:
                raise Refusal("not_found", "No such citation among the questions you can read.")
            section_id, quote = int(c[0]["section_id"]), quote or c[0]["quote"]
        else:
            section_id = p.section
        s = await section_ref(q, section_id)
        if s["status"] == "forgotten":
            raise Refusal("not_found", "That section has been forgotten; its words are not served.")
        src = (await q("SELECT source_id, title, kind FROM mcp_sources WHERE source_id = $1", int(s["source_id"])))[0]
        text, w = s["text"], p.window
        head = {"section_id": s["section_id"], "heading_path": s["heading_path"], "source": {"id": src["source_id"], "title": src["title"], "kind": src["kind"]}, "page_no": s["page_no"]}
        if not quote:
            raise Refusal("invalid_argument", "Give the quote to find.", fields=["quote"])
        at = find_quote(text, quote)
        if at is None:
            return dumps(head | {"found": False, "before": "", "match": "", "after": text[: 2 * w]})
        a, b = at
        return dumps(head | {"found": True, "before": text[max(0, a - w):a], "match": text[a:b], "after": text[b:b + w]})

    @mcp.tool(name="find_feeds", annotations={"title": "Find feeds", **RO})
    @answers
    async def find_feeds(params: FindFeedsIn) -> str:
        """The feeds a base follows (a sibling application's records read as sources) and when each last synced and with what outcome. `q` alone resolves one by its provider.tool. For the base's curators."""
        p = params
        if p.base in (None, "") and not p.q:
            raise Refusal("invalid_argument", "Name the base, or give q to find a feed.", fields=["base"])
        w = Where()
        if p.base not in (None, ""):
            w.add("base_id = {}", int((await base_ref(q, p.base, curate=True))["base_id"]))
        if p.q:
            w.add("(provider || '.' || tool) ILIKE {}", like(p.q))
        if p.enabled is not None:
            w.add("enabled = {}", p.enabled)
        rows = await q(f"""SELECT feed_id, base_id, provider, tool, provider || '.' || tool AS label, arguments, focus_area_id, every_minutes, enabled, last_sync_at, next_sync_at, last_status,
                                  left(last_error, 300) AS last_error, items FROM mcp_feeds WHERE {w.sql()} ORDER BY base_id, provider, tool LIMIT {lim(p.limit)}""", *w.args)
        if q_alone(p):
            return dumps([{"feed_id": r["feed_id"], "tool": r["label"], "base_id": r["base_id"]} for r in rows[:6]])
        return dumps(rows)
