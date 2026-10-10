"""Bases (B1–B3): find_bases, get_base, base_members, get_settings, find_templates. docs/knowledge-mcp-tool-surface.md "Bases". The mcp_bases view decides which bases the caller reads and
whether they curate (i_curate); kn_base_overview() answers for a curator what it answers for a reader plus the curator half. An admin reading a private base they hold no share in is logged
base.admin_view through the bridge (once per base per caller per hour — the PHP side decides)."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from kn_common import RO, Ref, Refusal, Where, _Base, answers, base_ref, bridge, describe_models, dumps, flag, lim, like, me, off, plain, q_alone


class FindBasesIn(_Base):
    q: str | None = Field(None, max_length=100, description="A base's key (exact) or words of its name; with nothing else it resolves one base")
    status: Literal["empty", "building", "ready", "needs_rebuild"] | None = Field(None, description="Only bases in this state")
    mine: bool = Field(False, description="Only bases I own or curate")
    include_archived: bool = Field(False, description="Include archived bases")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class GetBaseIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")


class BaseMembersIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    kind: Literal["member", "department", "agent", "application", "everyone"] | None = Field(None, description="Only this kind of principal")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class FindTemplatesIn(_Base):
    q: str | None = Field(None, max_length=100, description="A template's key or words of its name; with nothing else it resolves one")
    include_inactive: bool = Field(False, description="Include retired templates")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


describe_models(globals())

BASE_COLS = """base_id, key, name, left(description, 300) AS description, template, visibility, status, my_level, i_curate, source_count, section_count, stale_count, area_count, pinned_count,
               documents_count, subjects_count, last_built_at, running_build_id, owner_name, archived_at"""


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="find_bases", annotations={"title": "Find knowledge bases", **RO})
    @answers
    async def find_bases(params: FindBasesIn) -> str:
        """Which knowledge bases I may read: what each is about, how big, when it was last built, whether I curate it. Newest built first. `q` alone resolves a base by its key or name (answers a plain list of
        {base_id, name, key}). Not for one base in full — use get_base."""
        p = params
        w = Where()
        if not p.include_archived:
            w.raw("archived_at IS NULL")
        if p.q:
            n = w.p(p.q)
            w.raw(f"(lower(key) = lower({n}) OR name ILIKE {w.p(like(p.q))}" + (f" OR base_id = {w.p(int(p.q))}" if p.q.strip().isdigit() else "") + ")")
        if p.status:
            w.add("status = {}", p.status)
        if p.mine:
            w.add("(owner_member_id = {} OR i_curate)", await me(q))
        rows = await q(f"SELECT {BASE_COLS} FROM mcp_bases WHERE {w.sql()} ORDER BY last_built_at DESC NULLS LAST, name LIMIT {lim(p.limit)} OFFSET {off(p.offset)}", *w.args)
        if q_alone(p):
            return plain(rows, "base_id", "name", "key")
        return dumps(rows)

    @mcp.tool(name="get_base", annotations={"title": "One knowledge base at a glance", **RO})
    @answers
    async def get_base(params: GetBaseIn) -> str:
        """One base at a glance: its counts of sources and sections by status, its areas, the last build, its curators. A curator also gets the guideline counts, keys, feeds, open proposals, the
        gaps of the last 30 days and the build estimate. Not the text of anything — use get_section for that."""
        b = await base_ref(q, params.base)
        doc = (await q("SELECT kn_base_overview($1) AS o", int(b["base_id"])))[0]["o"]
        if doc is None:
            raise Refusal("not_found", "No knowledge base you can read matches that.")
        if b["visibility"] == "private" and await flag(q, "kn_is_admin()"):
            try:
                seen = await bridge("admin_view", {"base": int(b["base_id"]), "tool": "get_base"})
                if seen.get("admin_view"):
                    doc["admin_view"] = True
            except Refusal:
                pass                                              # the log line is the bridge's; a read is never refused for it
        return dumps(doc)

    @mcp.tool(name="base_members", annotations={"title": "Who may read or curate a base", **RO})
    @answers
    async def base_members(params: BaseMembersIn) -> str:
        """Who may read or curate base X and why: the owner first, then each member, department, agent, application or 'everyone' with the level they hold. For the base's curators."""
        b = await base_ref(q, params.base, curate=True)
        w = Where().add("base_id = {}", int(b["base_id"]))
        if params.kind:
            w.add("principal_kind = {}", params.kind)
        rows = await q(f"SELECT base_member_id, principal_kind, principal_id, principal_ref, principal_name, level, granted_by, created_at FROM mcp_base_members WHERE {w.sql()} "
                       f"ORDER BY principal_kind, principal_name LIMIT {lim(params.limit, 50)}", *w.args)
        if not params.kind:
            rows.insert(0, {"base_member_id": None, "principal_kind": "owner", "principal_id": b["owner_member_id"], "principal_ref": None, "principal_name": b["owner_name"], "level": "own",
                            "granted_by": b["created_by"], "created_at": b["created_at"]})
        return dumps(rows)

    @mcp.tool(name="get_settings", annotations={"title": "Knowledge's settings", **RO})
    @answers
    async def get_settings() -> str:
        """The application's settings: the limits (monthly text, file size, question length), the evidence threshold, how many turns a conversation keeps, key defaults, retention and review dates.
        The model and embedding model are shown to the admin only (null for everyone else)."""
        rows = await q("SELECT * FROM mcp_settings")
        if not rows:
            raise Refusal("not_found", "The settings are not readable by this caller.")
        return dumps(rows[0])

    @mcp.tool(name="find_templates", annotations={"title": "Find base templates", **RO})
    @answers
    async def find_templates(params: FindTemplatesIn | None = None) -> str:
        """The templates a base can start from and the guideline set each ships. `q` alone resolves one by its key or name (a plain list of {template_key, name}). A template's shipped set is product text,
        not a base's own guidelines."""
        p = params or FindTemplatesIn()
        w = Where()
        if not p.include_inactive:
            w.raw("is_active")
        if p.q:
            n = w.p(like(p.q))
            w.raw(f"(lower(template_key) = lower({w.p(p.q)}) OR name ILIKE {n})")
        rows = await q(f"SELECT template_key, name, description, version, subject_types, area_rule, default_visibility, is_active, guidelines FROM mcp_templates WHERE {w.sql()} "
                       f"ORDER BY sort_order, name LIMIT {lim(p.limit)}", *w.args)
        if q_alone(p):
            return plain(rows, "template_key", "name")
        return dumps(rows)
