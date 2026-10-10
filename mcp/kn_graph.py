"""The graph (K1–K7) and what the sections taught it (S3, S4): section_graph, source_graph, sections_for_subject, graph_stats, communities, community_members, get_subject, subject_neighbors,
subject_statements, graph_path, god_nodes, surprises, graph_query, duplicate_subjects. docs/knowledge-mcp-tool-surface.md "The graph". Every one goes through the engine bridge: the MaluDB token is
read in app/knowledge/engine.php alone, and the PHP side confines each answer to the base (a document becomes its section, a subject stays only if one of the base's documents touches it, no
tenant-wide count). The base is resolved HERE through mcp_bases (a base the caller cannot read is not_found before anything is asked); the PHP side applies the same gate again."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from kn_common import RO, Ref, _Base, answers, base_ref, bridge, describe_models, dumps, section_ref, source_ref


class SectionGraphIn(_Base):
    section: int = Field(..., ge=1, description="The section (id)")


class SourceGraphIn(_Base):
    source: Ref = Field(..., description="The source (id or title)")
    limit: int = Field(100, ge=1, le=100, description="How many subjects at most")


class SubjectIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    subject: Ref = Field(..., description="The subject (id or name)")


class SectionsForSubjectIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    subject: Ref = Field(..., description="The subject (id or name)")
    limit: int = Field(20, ge=1, le=100, description="How many sections at most")


class GraphStatsIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")


class CommunitiesIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    limit: int = Field(50, ge=1, le=200, description="How many communities at most")


class CommunityMembersIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    community: int = Field(..., ge=1, description="The community (id, from communities)")
    limit: int = Field(200, ge=1, le=200, description="How many members at most")


class SubjectNeighborsIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    subject: Ref = Field(..., description="The subject (id or name)")
    kind: Literal["subject", "document", "statement"] | None = Field(None, description="Only this kind of neighbour (a document is shown as its section)")
    limit: int = Field(50, ge=1, le=200, description="How many neighbours at most")


class SubjectStatementsIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    subject: Ref = Field(..., description="The subject (id or name)")
    verb: str | None = Field(None, max_length=100, description="Only statements with this verb")
    limit: int = Field(50, ge=1, le=200, description="How many statements at most")


class GraphPathIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    from_: Ref = Field(..., alias="from", description="The first subject (id or name)")
    to: Ref = Field(..., description="The second subject (id or name)")


class GodNodesIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    limit: int = Field(10, ge=1, le=50, description="How many subjects at most")


class GraphQueryIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    text: str = Field(..., min_length=2, max_length=400, description="The phrase to look around")
    depth: int = Field(2, ge=1, le=3, description="How many steps out from the phrase's subjects")
    max_nodes: int = Field(50, ge=1, le=100, description="How many nodes at most")


class DuplicatesIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    limit: int = Field(50, ge=1, le=50, description="How many groups at most")


describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    async def via(op: str, base, **args) -> str:
        b = await base_ref(q, base, curate=(op == "duplicate_subjects"))
        return dumps(await bridge(op, {"base": int(b["base_id"]), **{k: v for k, v in args.items() if v is not None}}))

    @mcp.tool(name="section_graph", annotations={"title": "What a section taught the graph", **RO})
    @answers
    async def section_graph(params: SectionGraphIn) -> str:
        """What one section taught the graph: the subjects and the statements MaluDB extracted from its words. A section never extracted answers extracted false and nothing else."""
        s = await section_ref(q, params.section)
        return dumps(await bridge("section_graph", {"section": int(s["section_id"])}))

    @mcp.tool(name="source_graph", annotations={"title": "What a source taught the graph", **RO})
    @answers
    async def source_graph(params: SourceGraphIn) -> str:
        """What a whole source taught the graph: its subjects, by how many of the source's sections mention them (it reads at most 50 extracted sections)."""
        s = await source_ref(q, params.source)
        return dumps(await bridge("source_graph", {"source": int(s["source_id"]), "limit": params.limit}))

    @mcp.tool(name="sections_for_subject", annotations={"title": "Sections that mention a subject", **RO})
    @answers
    async def sections_for_subject(params: SectionsForSubjectIn) -> str:
        """Which sections of base X mention subject Y: the heading path, the source and the page of each — where to read about it."""
        return await via("sections_for_subject", params.base, subject=params.subject, limit=params.limit)

    @mcp.tool(name="graph_stats", annotations={"title": "The size and shape of a base's graph", **RO})
    @answers
    async def graph_stats(params: GraphStatsIn) -> str:
        """The size and shape of base X's graph: documents, subjects, statements, communities, the most connected subjects and the commonest relationships. Counts are the base's own, never the whole
        engine's. If the engine is down you get the cached counts with engine 'unavailable'."""
        return await via("graph_stats", params.base)

    @mcp.tool(name="communities", annotations={"title": "Communities of a base's graph", **RO})
    @answers
    async def communities(params: CommunitiesIn) -> str:
        """The communities (clusters of related subjects) of base X. Usually empty until MaluDB detects communities; the note then says so and points to god_nodes."""
        return await via("communities", params.base, limit=params.limit)

    @mcp.tool(name="community_members", annotations={"title": "What is in a community", **RO})
    @answers
    async def community_members(params: CommunityMembersIn) -> str:
        """What is in community C of base X: its subjects and the sections behind them, confined to the base."""
        return await via("community_members", params.base, community=params.community, limit=params.limit)

    @mcp.tool(name="get_subject", annotations={"title": "What a subject is", **RO})
    @answers
    async def get_subject(params: SubjectIn) -> str:
        """What subject Y is in base X: its type and aliases, how many statements and neighbours it has, and the sections behind it (at most 20). A subject none of the base's documents touch is not_in_base."""
        return await via("subject", params.base, subject=params.subject)

    @mcp.tool(name="subject_neighbors", annotations={"title": "A subject's neighbours", **RO})
    @answers
    async def subject_neighbors(params: SubjectNeighborsIn) -> str:
        """The neighbours of subject Y in base X: other subjects, the sections that mention it (a document is shown as its section) and its statements."""
        return await via("subject_neighbors", params.base, subject=params.subject, kind=params.kind, limit=params.limit)

    @mcp.tool(name="subject_statements", annotations={"title": "What the base says about a subject", **RO})
    @answers
    async def subject_statements(params: SubjectStatementsIn) -> str:
        """What base X says about subject Y, grouped by verb: each statement's words and the section it came from."""
        return await via("subject_statements", params.base, subject=params.subject, verb=params.verb, limit=params.limit)

    @mcp.tool(name="graph_path", annotations={"title": "How two subjects are related", **RO})
    @answers
    async def graph_path(params: GraphPathIn) -> str:
        """How subjects Y and Z are related inside base X: the chain of subjects, verbs and sections between them. If the shortest chain runs through another base's documents the answer is no_path_in_base.
        The search depth is fixed at 6 steps."""
        return await via("path", params.base, **{"from": params.from_, "to": params.to})

    @mcp.tool(name="god_nodes", annotations={"title": "The most connected subjects", **RO})
    @answers
    async def god_nodes(params: GodNodesIn) -> str:
        """The most connected subjects of base X — what it is mostly about; a good place to start when proposing focus areas."""
        return await via("god_nodes", params.base, limit=params.limit)

    @mcp.tool(name="surprises", annotations={"title": "Surprising links in the graph", **RO})
    @answers
    async def surprises(params: GodNodesIn) -> str:
        """Surprising links between distant parts of base X's graph — subjects joined by a relationship the rest of the graph does not predict."""
        return await via("surprises", params.base, limit=params.limit)

    @mcp.tool(name="graph_query", annotations={"title": "The subgraph around a phrase", **RO})
    @answers
    async def graph_query(params: GraphQueryIn) -> str:
        """The subgraph around a phrase in base X: the subjects it names, their neighbours up to `depth` steps out, and the edges between, with documents shown as their sections."""
        return await via("graph_query", params.base, text=params.text, depth=params.depth, max_nodes=params.max_nodes)

    @mcp.tool(name="duplicate_subjects", annotations={"title": "Subjects that look like duplicates", **RO})
    @answers
    async def duplicate_subjects(params: DuplicatesIn) -> str:
        """Subjects of base X that look like duplicates the vocabulary should merge (grouped by a normalised name and by the vocabulary's aliases). It reads the subjects of up to 200 documents and says
        how many it read. For the base's curators."""
        return await via("duplicate_subjects", params.base, limit=params.limit)
