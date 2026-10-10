"""Keys, agents, proposals (A1–A4) and the people, notes and mine the resolvers and the screens need: find_keys, base_readers, archivist_proposals, agent_dispatches, find_members, find_departments,
find_notes, my_notifications, my_tokens. docs/knowledge-mcp-tool-surface.md "Keys, agents, proposals" and "People, notes, mine". The views decide the rows (keys, readers and proposals are the
curator's; dispatches the admin's, else the ones the caller caused, received or curates; notices and tokens one's own); NO view selects a key's value or hash, so no tool can return one."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from kn_common import RO, Ref, Refusal, Where, _Base, answers, base_ref, describe_models, dumps, flag, is_int, lim, member_ref, plain, q_alone, qmatch


class FindKeysIn(_Base):
    base: Ref | None = Field(None, description="The base (key, name or id); without it only a holder of the keys.all right sees every base's keys")
    q: str | None = Field(None, max_length=100, description="Words of a key's label; with nothing else it resolves a key")
    live: bool = Field(True, description="Only keys that are live (not revoked, not expired); false: every key")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class BaseReadersIn(_Base):
    base: Ref | None = Field(None, description="Only this base; without it every base I curate (an admin: all)")
    kind: Literal["agent", "application"] | None = Field(None, description="Only agents or only applications")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class ProposalsIn(_Base):
    base: Ref | None = Field(None, description="Only this base")
    status: Literal["proposed", "accepted", "dismissed"] | None = Field(None, description="Only proposals in this state")
    kind: Literal["area", "merge", "gap", "stale_source", "failed_source", "review_pin", "unassigned", "unverified", "feed_stopped", "limit_near", "url_changed"] | None = Field(None, description="The kind of proposal")
    q: str | None = Field(None, max_length=200, description="Words of the title; with nothing else it resolves a proposal")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class DispatchesIn(_Base):
    status: Literal["sent", "answered", "refused", "failed", "awaiting_approval"] | None = Field(None, description="Only dispatches in this state")
    agent: Ref | None = Field(None, description="Only dispatches to this agent (id or name)")
    base: Ref | None = Field(None, description="Only this base")
    kind: Literal["ask", "propose_areas", "draft_guidelines", "report"] | None = Field(None, description="The kind of dispatch")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class MembersIn(_Base):
    q: str | None = Field(None, max_length=100, description="Words of a name; with nothing else it resolves a person or an agent")
    kind: Literal["human", "agent"] | None = Field(None, description="Only people or only agents")
    department: Ref | None = Field(None, description="Only members of this department (id or name)")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class FindDepartmentsIn(_Base):
    q: str | None = Field(None, max_length=100, description="Words of a department's name; with nothing else it resolves one")
    include_archived: bool = Field(False, description="Include archived departments")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class FindNotesIn(_Base):
    record_type: Literal["base", "source", "section"] = Field(..., description="What the note is on")
    record_id: int = Field(..., ge=1, description="The id of that base, source or section")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class MyNotificationsIn(_Base):
    unread_only: bool = Field(False, description="Only the notices I have not read")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class MyTokensIn(_Base):
    scope: Literal["mcp", "api"] | None = Field(None, description="mcp: my personal tokens; api: the keys I minted")


describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="find_keys", annotations={"title": "Find API keys", **RO})
    @answers
    async def find_keys(params: FindKeysIn) -> str:
        """Which API keys exist for base X: their label, the focus area they are bound to, their limits, last use and today's count, and whether they are live. `q` alone resolves a key by its label (a plain list
        of {key_id, label}). NEVER a key's value or hash — a key is shown once, when minted. For the base's curators; without a base, for a holder of keys.all."""
        p = params
        w = Where()
        if p.base not in (None, ""):
            w.add("k.base_id = {}", int((await base_ref(q, p.base, curate=True))["base_id"]))
        elif not p.q and not await flag(q, "kn_has_right('keys.all')"):
            raise Refusal("invalid_argument", "Name the base (listing every base's keys needs the keys.all right).", fields=["base"])
        if p.live:
            w.raw("k.is_live")
        qmatch(w, p.q, "k.key_id", "k.label")
        rows = await q(f"""SELECT k.key_id, k.base_id, b.name AS base_name, k.label, k.focus_area_id, k.rate_per_minute, k.rate_per_day, k.calls_today, k.last_used_at, k.expires_at, k.revoked_at,
                                  k.rotated_from, k.is_live, k.minted_by_name, k.created_at FROM mcp_keys k JOIN mcp_bases b ON b.base_id = k.base_id WHERE {w.sql()}
                           ORDER BY k.created_at DESC, k.key_id DESC LIMIT {lim(p.limit)}""", *w.args)
        if q_alone(p):
            return plain(rows, "key_id", "label", "base_name")
        return dumps(rows)

    @mcp.tool(name="base_readers", annotations={"title": "Which agents and applications read which bases", **RO})
    @answers
    async def base_readers(params: BaseReadersIn | None = None) -> str:
        """Which agents and which sibling applications may read (or curate) which bases, and at what level. For the bases I curate; an admin sees all."""
        p = params or BaseReadersIn()
        w = Where().raw("m.principal_kind IN ('agent', 'application')")
        if p.base not in (None, ""):
            w.add("m.base_id = {}", int((await base_ref(q, p.base, curate=True))["base_id"]))
        if p.kind:
            w.add("m.principal_kind = {}", p.kind)
        rows = await q(f"""SELECT m.base_id, b.name AS base_name, m.principal_kind, m.principal_id, m.principal_ref, m.principal_name, m.level, m.created_at FROM mcp_base_members m
                             JOIN mcp_bases b ON b.base_id = m.base_id WHERE {w.sql()} ORDER BY b.name, m.principal_kind, m.principal_name LIMIT {lim(p.limit, 50)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="archivist_proposals", annotations={"title": "What the Archivist proposed", **RO})
    @answers
    async def archivist_proposals(params: ProposalsIn | None = None) -> str:
        """What the Archivist proposed, and what was accepted or dismissed: focus areas to add, duplicates to merge, gaps to fill, stale or failed sources, pins to review. `q` alone resolves a proposal by its title
        (a plain list of {proposal_id, title}). For the base's curators."""
        p = params or ProposalsIn()
        w = Where()
        if p.base not in (None, ""):
            w.add("p.base_id = {}", int((await base_ref(q, p.base, curate=True))["base_id"]))
        if p.status:
            w.add("p.status = {}", p.status)
        if p.kind:
            w.add("p.kind = {}", p.kind)
        qmatch(w, p.q, "p.proposal_id", "p.title")
        rows = await q(f"""SELECT p.proposal_id, p.base_id, p.kind, p.subject_type, p.subject_id, p.title, p.reason, p.payload, p.status, p.proposed_by_name, p.decided_by, p.decided_at, p.created_at
                             FROM mcp_archivist_proposals p WHERE {w.sql()} ORDER BY (p.status = 'proposed') DESC, p.created_at DESC, p.proposal_id DESC LIMIT {lim(p.limit)}""", *w.args)
        if q_alone(p):
            return plain(rows, "proposal_id", "title", "base_id")
        return dumps(rows)

    @mcp.tool(name="agent_dispatches", annotations={"title": "What was handed to an agent", **RO})
    @answers
    async def agent_dispatches(params: DispatchesIn | None = None) -> str:
        """Which dispatches to an agent (a question to the expert, a request to propose areas or draft guidelines, a report) are pending or failed: the agent, the kind, how it was sent, the state and the first words
        of its reply. The admin sees all; otherwise the ones I caused, received or curate."""
        p = params or DispatchesIn()
        w = Where()
        if p.status:
            w.add("d.status = {}", p.status)
        if p.agent not in (None, ""):
            w.add("d.agent_member_id = {}", await member_ref(q, p.agent))
        if p.base not in (None, ""):
            w.add("d.base_id = {}", int((await base_ref(q, p.base))["base_id"]))
        if p.kind:
            w.add("d.kind = {}", p.kind)
        rows = await q(f"""SELECT d.dispatch_id, d.record_type, d.record_id, d.agent_name, d.kind, d.via, d.status, left(d.reply_excerpt, 300) AS reply_excerpt, left(d.detail, 300) AS detail, d.attempts, d.run_id,
                                  d.request_id, d.base_id, d.build_id, d.created_at, d.answered_at, d.next_attempt_at FROM mcp_agent_dispatches d WHERE {w.sql()}
                           ORDER BY d.created_at DESC, d.dispatch_id DESC LIMIT {lim(p.limit)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="find_members", annotations={"title": "Find people and agents", **RO})
    @answers
    async def find_members(params: MembersIn | None = None) -> str:
        """A person or an agent by name — for example to share a base with them. `q` alone resolves one (a plain list of {member_id, display_name}). Shows job title, roles and departments; an email only for yourself.
        Inside the business you see everyone admitted; a guest sees the people on the bases shared with them."""
        p = params or MembersIn()
        w = Where()
        if p.kind:
            w.add("m.member_kind = {}", p.kind)
        if p.department not in (None, ""):
            if is_int(p.department):
                dep = int(p.department)
            else:
                d = await q("SELECT department_id FROM mcp_departments WHERE lower(name) = lower($1) LIMIT 1", str(p.department).strip())
                if not d:
                    raise Refusal("not_found", "No such department.")
                dep = int(d[0]["department_id"])
            w.add("{} = ANY (m.department_ids)", dep)
        qmatch(w, p.q, "m.member_id", "m.display_name")
        rows = await q(f"""SELECT m.member_id, m.display_name, m.member_kind, m.is_agent, m.is_guest, m.job_title, m.roles, m.department_ids, m.email FROM mcp_members m WHERE {w.sql()}
                           ORDER BY m.display_name LIMIT {lim(p.limit)}""", *w.args)
        if q_alone(p):
            return plain(rows, "member_id", "display_name", "member_kind")
        return dumps(rows)

    @mcp.tool(name="find_departments", annotations={"title": "Find departments", **RO})
    @answers
    async def find_departments(params: FindDepartmentsIn | None = None) -> str:
        """A department by name — as a principal to share a base with. `q` alone resolves one (a plain list of {department_id, name}). Not shown to a guest."""
        p = params or FindDepartmentsIn()
        w = Where()
        if not p.include_archived:
            w.raw("archived_at IS NULL")
        qmatch(w, p.q, "department_id", "name")
        rows = await q(f"SELECT department_id, name, description, parent_id, archived_at FROM mcp_departments WHERE {w.sql()} ORDER BY name LIMIT {lim(p.limit)}", *w.args)
        if q_alone(p):
            return plain(rows, "department_id", "name")
        return dumps(rows)

    @mcp.tool(name="find_notes", annotations={"title": "Notes on a base, source or section", **RO})
    @answers
    async def find_notes(params: FindNotesIn) -> str:
        """The notes written on a base, a source or a section: who wrote each, when, and the words. Newest first."""
        p = params
        rows = await q(f"SELECT note_id, member_name, body, created_at FROM mcp_notes WHERE record_type = $1 AND record_id = $2 ORDER BY created_at DESC, note_id DESC LIMIT {lim(p.limit)}", p.record_type, p.record_id)
        return dumps(rows)

    @mcp.tool(name="my_notifications", annotations={"title": "My notices", **RO})
    @answers
    async def my_notifications(params: MyNotificationsIn | None = None) -> str:
        """My notices — a build done, a limit near, a proposal, a review due, a base shared with me — unread first, and my notification preferences."""
        p = params or MyNotificationsIn()
        w = Where()
        if p.unread_only:
            w.raw("read_at IS NULL")
        rows = await q(f"SELECT notification_id, kind, title, body, record_type, record_id, read_at, created_at FROM mcp_notifications WHERE {w.sql()} ORDER BY (read_at IS NULL) DESC, created_at DESC LIMIT {lim(p.limit)}", *w.args)
        prefs = await q("SELECT email_enabled, text_enabled, kinds, text_kinds, updated_at FROM mcp_notification_prefs")
        return dumps({"prefs": prefs[0] if prefs else None, "notifications": rows})

    @mcp.tool(name="my_tokens", annotations={"title": "My tokens and keys", **RO})
    @answers
    async def my_tokens(params: MyTokensIn | None = None) -> str:
        """My own MCP tokens and the API keys I minted: label, scope, the base and area a key is bound to, when last used, when it expires or was revoked. NEVER a value or a hash — those are shown once, when minted."""
        p = params or MyTokensIn()
        w = Where()
        if p.scope:
            w.add("scope = {}", p.scope)
        rows = await q(f"""SELECT token_id, label, scope, base_id, focus_area_id, rate_per_minute, rate_per_day, rotated_from, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens_mine
                            WHERE {w.sql()} ORDER BY created_at DESC, token_id DESC LIMIT 100""", *w.args)
        return dumps(rows)
