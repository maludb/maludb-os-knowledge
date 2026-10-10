"""Guidelines and areas (G1–G5): find_guidelines, guideline_versions, extraction_preview, find_vocabulary, unused_terms, find_focus_areas, get_focus_area, unassigned_sources, empty_areas.
docs/knowledge-mcp-tool-surface.md "Guidelines and areas". Guidelines, versions and vocabulary are the CURATOR's (the views show nobody else any row; a tool that names a base says curators_only
instead of an empty answer); focus areas are a Reader's. extraction_preview is a dry run of the prompt through the engine bridge (the saved set, or the saved set with drafts overlaid)."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from kn_common import RO, Ref, Refusal, Where, _Base, answers, area_ref, base_ref, bridge, describe_models, dumps, guideline_ref, lim, like, off, plain, q_alone, qmatch

GUIDELINE_KINDS = Literal["extraction", "vocabulary", "exclusion", "answering", "audience"]


class FindGuidelinesIn(_Base):
    base: Ref | None = Field(None, description="The base (key, name or id); required unless q is given")
    kind: GUIDELINE_KINDS | None = Field(None, description="Only this kind of guideline")
    include_inactive: bool = Field(False, description="Include guidelines switched off")
    q: str | None = Field(None, max_length=200, description="Words of a guideline's title; with nothing else it resolves one")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class GuidelineVersionsIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    guideline: Ref | None = Field(None, description="Only this guideline's history (title or id)")
    limit: int = Field(50, ge=1, le=100, description="How many versions at most")


class DraftIn(_Base):
    kind: GUIDELINE_KINDS = Field(..., description="The kind of guideline")
    title: str = Field("", max_length=200, description="Its title; a draft whose kind and title match a saved guideline replaces it, any other is added")
    body: str = Field(..., min_length=1, max_length=4000, description="Its words")


class PreviewIn(_Base):
    section: int = Field(..., ge=1, description="The section the prompt is tried on (id)")
    draft: list[DraftIn] | None = Field(None, max_length=10, description="Up to ten draft guidelines laid over the saved ones; nothing is saved")


class FindVocabularyIn(_Base):
    base: Ref | None = Field(None, description="The base; required unless q is given")
    q: str | None = Field(None, max_length=200, description="A term's name or alias; with nothing else it resolves a term")
    subject_type: str | None = Field(None, max_length=60, description="Only terms of this subject type")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class BaseLimitIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class BaseOnlyIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")


class FindFocusAreasIn(_Base):
    base: Ref | None = Field(None, description="The base; required unless q is given")
    q: str | None = Field(None, max_length=100, description="An area's slug or words of its name; with nothing else it resolves an area across my bases")
    status: Literal["proposed", "active", "archived"] | None = Field(None, description="Only areas in this state (default: active and proposed)")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class GetFocusAreaIn(_Base):
    area: Ref = Field(..., description="The focus area (id, or slug with base)")
    base: Ref | None = Field(None, description="The base, when the area is named by its slug")


describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="find_guidelines", annotations={"title": "Find guidelines", **RO})
    @answers
    async def find_guidelines(params: FindGuidelinesIn) -> str:
        """Base X's guidelines by kind, with the version the graph is built under and whether each one shapes the graph (extraction, vocabulary, exclusion do; answering and audience apply to the next
        question). `q` alone resolves a guideline by its title (a plain list of {guideline_id, title, kind}). For the base's curators."""
        p = params
        if p.base in (None, "") and not p.q:
            raise Refusal("invalid_argument", "Name the base, or give q to find a guideline by its title.", fields=["base"])
        w = Where()
        version = None
        if p.base not in (None, ""):
            b = await base_ref(q, p.base, curate=True)
            version = b["guideline_version"]
            w.add("base_id = {}", int(b["base_id"]))
        if p.kind:
            w.add("kind = {}", p.kind)
        if not p.include_inactive:
            w.raw("is_active")
        qmatch(w, p.q, "guideline_id", "title")
        rows = await q(f"SELECT guideline_id, base_id, kind, shapes_graph, title, body, position, is_active, origin, version_no, updated_at FROM mcp_guidelines WHERE {w.sql()} "
                       f"ORDER BY base_id, kind, position, guideline_id LIMIT {lim(p.limit, 50)}", *w.args)
        if q_alone(p):
            return plain(rows, "guideline_id", "title", "kind", "base_id")
        return dumps({"base_guideline_version": version, "guidelines": rows})

    @mcp.tool(name="guideline_versions", annotations={"title": "A guideline's history", **RO})
    @answers
    async def guideline_versions(params: GuidelineVersionsIn) -> str:
        """The history of a base's guidelines, and which version the graph is built under: how many sections were extracted under each version, how many are stale, and every saved version
        (kind, title, body, who and when). For the base's curators."""
        p = params
        b = await base_ref(q, p.base, curate=True)
        bid = int(b["base_id"])
        w = Where().add("base_id = {}", bid)
        if p.guideline not in (None, ""):
            w.add("guideline_id = {}", int((await guideline_ref(q, p.guideline, bid))["guideline_id"]))
        built = await q("SELECT extracted_version, count(*) AS sections FROM mcp_sections WHERE base_id = $1 AND status <> 'forgotten' AND extracted_version IS NOT NULL GROUP BY extracted_version ORDER BY extracted_version", bid)
        stale = (await q("SELECT count(*) AS n FROM mcp_sections WHERE base_id = $1 AND status = 'stale'", bid))[0]["n"]
        versions = await q(f"""SELECT v.guideline_version_id, v.guideline_id, v.version_no, v.kind, v.title, v.body, v.is_active, v.base_guideline_version, m.display_name AS saved_by, v.saved_at
                                 FROM mcp_guideline_versions v LEFT JOIN mcp_members m ON m.member_id = v.saved_by WHERE {w.sql()} ORDER BY v.saved_at DESC, v.guideline_version_id DESC LIMIT {lim(p.limit, 50)}""", *w.args)
        return dumps({"base_guideline_version": b["guideline_version"], "built_under": built, "stale_sections": stale, "versions": versions})

    @mcp.tool(name="extraction_preview", annotations={"title": "Try the extraction prompt on a section", **RO})
    @answers
    async def extraction_preview(params: PreviewIn) -> str:
        """WHAT THESE GUIDELINES WOULD TELL THE MODEL about this section: the base's compiled extraction prompt with the section's words in it, the engine's own generic prompt for comparison, and the hints (terms
        found in the section). Give `draft` guidelines to try a change before saving it; a draft whose kind and title match a saved guideline replaces it. A dry run of the PROMPT, not of the model; nothing
        is saved. For the base's curators."""
        a: dict = {"section": params.section}
        if params.draft:
            a["draft"] = [d.model_dump() for d in params.draft]
        return dumps(await bridge("preview", a))

    @mcp.tool(name="find_vocabulary", annotations={"title": "Find vocabulary terms", **RO})
    @answers
    async def find_vocabulary(params: FindVocabularyIn) -> str:
        """The vocabulary of a base: each term's canonical name, aliases and subject type, and whether the engine already knows it (seeded). `q` alone resolves a term by name or alias (a plain list of
        {term_id, canonical_name}). For the base's curators."""
        p = params
        if p.base in (None, "") and not p.q:
            raise Refusal("invalid_argument", "Name the base, or give q to find a term.", fields=["base"])
        w = Where()
        if p.base not in (None, ""):
            w.add("base_id = {}", int((await base_ref(q, p.base, curate=True))["base_id"]))
        if p.subject_type:
            w.add("subject_type = {}", p.subject_type)
        if p.q:
            n = w.p(like(p.q))
            idm = f"term_id = {w.p(int(p.q))} OR " if p.q.strip().isdigit() else ""
            w.raw(f"({idm}canonical_name ILIKE {n} OR EXISTS (SELECT 1 FROM unnest(aliases) a WHERE a ILIKE {n}))")
        rows = await q(f"SELECT term_id, base_id, canonical_name, aliases, subject_type, description, maludb_subject_id IS NOT NULL AS seeded, updated_at FROM mcp_vocabulary_terms WHERE {w.sql()} "
                       f"ORDER BY canonical_name LIMIT {lim(p.limit, 50)} OFFSET {off(p.offset)}", *w.args)
        if q_alone(p):
            return plain(rows, "term_id", "canonical_name", "base_id")
        return dumps(rows)

    @mcp.tool(name="unused_terms", annotations={"title": "Vocabulary the sources never use", **RO})
    @answers
    async def unused_terms(params: BaseLimitIn) -> str:
        """Terms of the vocabulary that no live section's words contain (neither the name nor an alias): candidates to drop or to rename. For the base's curators."""
        b = await base_ref(q, params.base, curate=True)
        rows = await q(f"SELECT term_id, canonical_name, aliases, subject_type, seeded FROM kn_unused_terms($1) LIMIT {lim(params.limit, 50)}", int(b["base_id"]))
        return dumps(rows)

    @mcp.tool(name="find_focus_areas", annotations={"title": "Find focus areas", **RO})
    @answers
    async def find_focus_areas(params: FindFocusAreasIn) -> str:
        """The focus areas of a base — a narrower region of it that a question can be asked in — with their seed terms, example questions and size; which are proposed and by whom. `q` alone resolves an
        area by its slug or name across my bases (a plain list of {focus_area_id, name}). Active and proposed areas by default."""
        p = params
        if p.base in (None, "") and not p.q:
            raise Refusal("invalid_argument", "Name the base, or give q to find a focus area.", fields=["base"])
        w = Where()
        if p.base not in (None, ""):
            w.add("a.base_id = {}", int((await base_ref(q, p.base))["base_id"]))
        if p.status:
            w.add("a.status = {}", p.status)
        else:
            w.raw("a.status IN ('active', 'proposed')")
        if p.q:
            n = w.p(like(p.q))
            idm = f"a.focus_area_id = {w.p(int(p.q))} OR " if p.q.strip().isdigit() else ""
            w.raw(f"({idm}lower(a.slug) = lower({w.p(p.q)}) OR a.name ILIKE {n})")
        rows = await q(f"""SELECT a.focus_area_id, a.base_id, b.name AS base_name, a.name, a.slug, left(a.description, 500) AS description, a.seed_terms, a.example_questions, a.status, a.proposed_by_name,
                                  a.proposal_note, a.source_count, a.position FROM mcp_focus_areas a JOIN mcp_bases b ON b.base_id = a.base_id WHERE {w.sql()}
                           ORDER BY (a.status = 'proposed'), a.position, a.name LIMIT {lim(p.limit)}""", *w.args)
        if q_alone(p):
            return plain(rows, "focus_area_id", "name", "base_name")
        return dumps(rows)

    @mcp.tool(name="get_focus_area", annotations={"title": "One focus area", **RO})
    @answers
    async def get_focus_area(params: GetFocusAreaIn) -> str:
        """One focus area: its description, seed terms, example questions and its sources (at most 50). Name it by id, or by slug with its base."""
        bid = int((await base_ref(q, params.base))["base_id"]) if params.base not in (None, "") else None
        a = await area_ref(q, params.area, bid)
        sources = await q("""SELECT s.source_id, s.title, s.status, s.sections_count FROM mcp_source_focus_areas f JOIN mcp_sources s ON s.source_id = f.source_id
                              WHERE f.focus_area_id = $1 AND s.status <> 'forgotten' ORDER BY s.title LIMIT 50""", int(a["focus_area_id"]))
        sections = (await q("""SELECT count(*) AS n FROM mcp_sections x WHERE x.status <> 'forgotten' AND x.source_id IN (SELECT source_id FROM mcp_source_focus_areas WHERE focus_area_id = $1)""", int(a["focus_area_id"])))[0]["n"]
        cur = await q("SELECT i_curate FROM mcp_bases WHERE base_id = $1", int(a["base_id"]))
        area = {k: a[k] for k in ("focus_area_id", "base_id", "name", "slug", "description", "seed_terms", "example_questions", "status", "proposed_by_name", "proposal_note", "source_count", "position", "created_at")}
        if cur and cur[0]["i_curate"]:
            area["maludb_project_id"] = a["maludb_project_id"]
        return dumps({"area": area, "sources": sources, "section_count": sections})

    @mcp.tool(name="unassigned_sources", annotations={"title": "Sources in no focus area", **RO})
    @answers
    async def unassigned_sources(params: BaseLimitIn) -> str:
        """Sources of a base that sit in no focus area: material the areas do not cover yet. Newest first. For the base's curators."""
        b = await base_ref(q, params.base, curate=True)
        return dumps(await q(f"SELECT source_id, title, kind, status, sections_count, added_at FROM kn_unassigned_sources($1) LIMIT {lim(params.limit, 50)}", int(b["base_id"])))

    @mcp.tool(name="empty_areas", annotations={"title": "Focus areas with no source", **RO})
    @answers
    async def empty_areas(params: BaseOnlyIn) -> str:
        """Focus areas of a base that hold no source: areas to fill or to archive. For the base's curators."""
        b = await base_ref(q, params.base, curate=True)
        return dumps(await q("SELECT focus_area_id, name, slug, status, created_at FROM kn_empty_areas($1)", int(b["base_id"])))

