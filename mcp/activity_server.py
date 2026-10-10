"""knowledge_activity_mcp — Knowledge's activity-memory server (activity_log through mcp_activity_log, read-only).

Tool surface: docs/knowledge-mcp-tool-surface.md, "Activity server". Every query runs as the asking member; the view decides which rows they see: their own, the rows about a base they may see, and the admin sees every row. The
activity role reads mcp_activity_log plus mcp_members, mcp_departments, mcp_bases, mcp_sources and mcp_focus_areas for names beside a row. A source's, a section's, an answer's or a guideline's text, a question over 120 characters, a
key, a hash or a MaluDB token is never in a payload (the writers' rule), so nothing here can return one.
"""
from __future__ import annotations

from typing import Literal

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from kn_common import RO, Ref, Refusal, Where, _Base, ago, answers, base_ref, describe_models, dumps, flag, lim, like, member_ref, when

mcp = FastMCP("knowledge_activity_mcp")

# What the log calls a record, where it is not the word a person uses (the writers' entity_type strings).
ENTITY_TYPES = {"key": ["mcp_access_token", "key"], "proposal": ["archivist_proposal", "proposal"], "dispatch": ["agent_dispatch", "dispatch"], "vocabulary_term": ["vocabulary_term", "term", "vocabulary"],
                "guideline": ["guideline"], "pinned_answer": ["pinned_answer"], "focus_area": ["focus_area", "area"]}
COLS = "l.activity_id, l.occurred_at, l.actor_member_id, l.actor_name, l.actor_is_agent, l.source, l.action, l.entity_type, l.entity_id, l.base_id, l.before, l.after, l.request_id, l.agent_run_id"
HIST_COLS = "l.activity_id, l.occurred_at, l.actor_name, l.actor_is_agent, l.source, l.action, l.before, l.after, l.request_id, l.agent_run_id"


async def _pool():
    return await db.get_pool(db.ENV["MCP_ACTIVITY_DB_USER"], db.ENV["MCP_ACTIVITY_DB_PASSWORD"])


async def q(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


class HistoryIn(_Base):
    entity_type: Literal["base", "source", "section", "guideline", "vocabulary_term", "focus_area", "build", "question", "pinned_answer", "key", "feed", "proposal", "dispatch"] = Field(..., description="What kind of record")
    entity_id: int = Field(..., ge=1, description="Its id")
    limit: int = Field(100, ge=1, le=200, description="How many rows at most")


class TimelineIn(_Base):
    member: Ref | None = Field(None, description="A person or an agent (id or name)")
    key: int | None = Field(None, ge=1, description="An API key's id (find_keys)")
    since: str | None = Field(None, description="A day or ISO time; the last 7 days by default")
    base: Ref | None = Field(None, description="Only this base (key, name or id)")
    limit: int = Field(100, ge=1, le=200, description="How many rows at most")


class RecentIn(_Base):
    since: str = Field(..., description="A day or ISO time")
    base: Ref | None = Field(None, description="Only this base (key, name or id)")
    exclude_mine: bool = Field(True, description="Leave out what I did myself")
    limit: int = Field(100, ge=1, le=200, description="How many rows at most")


class ShareReadsIn(_Base):
    since: str | None = Field(None, description="A day or ISO time; the last 30 days by default")
    application: str | None = Field(None, max_length=60, description="Only this requesting application (its catalog key)")
    group: bool = Field(True, description="Group by application and tool (false: every row)")


class ActivitySearchIn(_Base):
    q: str = Field(..., min_length=2, max_length=200, description="Words to find in an action, a name, a label or a title")
    since: str | None = Field(None, description="A day or ISO time — only what is newer")
    base: Ref | None = Field(None, description="Only this base (key, name or id)")
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class ActivityQueryIn(_Base):
    sql: str = Field(..., description="One SELECT over mcp_activity_log and the name views")


describe_models(globals())


@mcp.tool(name="record_history", annotations={"title": "A record's history", **RO})
@answers
async def record_history(params: HistoryIn) -> str:
    """Who did what to one record, in order (oldest first): a base, a source, a section, a guideline, a vocabulary term, a focus area, a build, a question, a pinned answer, a key, a feed, a proposal or a dispatch — each row worded
    with the actor (a person, an agent, the worker), the action and the payload (ids, names and counts, never the text). You see the rows about bases you may see, and your own."""
    p = params
    types = ENTITY_TYPES.get(p.entity_type, [p.entity_type])
    w = Where().raw("l.action <> 'screen.view'").add("l.entity_type = ANY ({}::text[])", types).add("l.entity_id = {}", p.entity_id)
    rows = await q(f"SELECT {HIST_COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(p.limit, 100, 200)}", *w.args)
    rows.reverse()
    return dumps(rows)


@mcp.tool(name="actor_timeline", annotations={"title": "What a person, key or agent did", **RO})
@answers
async def actor_timeline(params: TimelineIn) -> str:
    """What a person, an agent or an API key did here since a time (7 days by default), newest first. Anyone reads their own trail; another's, or a key's, shows only what happened on the bases you curate (the admin: all)."""
    p = params
    given = [x for x in (p.member, p.key) if x not in (None, "")]
    if len(given) != 1:
        raise Refusal("invalid_argument", "Give exactly one of member or key.", fields=["member", "key"])
    w = Where().raw("l.action <> 'screen.view'").add("l.occurred_at >= {}", when(p.since, "since") if p.since else ago(7))
    mine = int((await q("SELECT app_current_member_id() AS m"))[0]["m"])
    target = None
    if p.member not in (None, ""):
        target = await member_ref(q, p.member)
        w.add("l.actor_member_id = {}", target)
    else:
        w.add("l.token_id = {}", p.key)
    if p.base not in (None, ""):
        w.add("l.base_id = {}", int((await base_ref(q, p.base))["base_id"]))
    if target != mine and not await flag(q, "kn_is_admin()"):
        w.raw("l.base_id IN (SELECT kn_curated_base_ids())")
    rows = await q(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(p.limit, 100, 200)}", *w.args)
    return dumps(rows)


@mcp.tool(name="recent_activity", annotations={"title": "What changed", **RO})
@answers
async def recent_activity(params: RecentIn) -> str:
    """What changed since a time, across everything I may see (or in one base) — newest first. Leaves out my own doing unless asked. For catching up: what was added, built, shared, asked."""
    p = params
    w = Where().raw("l.action <> 'screen.view'").add("l.occurred_at >= {}", when(p.since, "since"))
    if p.base not in (None, ""):
        w.add("l.base_id = {}", int((await base_ref(q, p.base))["base_id"]))
    if p.exclude_mine:
        w.raw("l.actor_member_id IS DISTINCT FROM app_current_member_id()")
    rows = await q(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(p.limit, 100, 200)}", *w.args)
    return dumps(rows)


@mcp.tool(name="share_reads", annotations={"title": "What siblings read of ours", **RO})
@answers
async def share_reads(params: ShareReadsIn | None = None) -> str:
    """Which sibling applications read Knowledge through the kernel (the shares ask, search and bases_index), how often and with what outcome — grouped by application and tool: calls, refusals, the last time. The admin's."""
    p = params or ShareReadsIn()
    if not await flag(q, "kn_is_admin()"):
        raise Refusal("curators_only", "What siblings read of ours is the admin's to see.")
    w = Where().raw("l.action = 'share.read'").add("l.occurred_at >= {}", when(p.since, "since") if p.since else ago(30))
    if p.application:
        w.add("l.after->>'application' = {}", p.application)
    if p.group:
        rows = await q(f"""SELECT l.after->>'application' AS application, l.after->>'tool' AS tool, count(*) AS calls,
                                  count(*) FILTER (WHERE l.after->>'outcome' IN ('not_shared', 'consumer_unknown', 'invalid')) AS refused, max(l.occurred_at) AS last_at
                             FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1, 2 ORDER BY last_at DESC LIMIT 200""", *w.args)
    else:
        rows = await q(f"SELECT {COLS}, l.after->>'application' AS application, l.after->>'tool' AS tool, l.after->>'outcome' AS outcome FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC LIMIT 200", *w.args)
    return dumps(rows)


@mcp.tool(name="activity_search", annotations={"title": "Search the trail", **RO})
@answers
async def activity_search(params: ActivitySearchIn) -> str:
    """The trail by a phrase: each word is looked for in the action (source.add), the record type, the names, titles, labels and question excerpts the payloads carry, and the actor's name. Newest first; only rows you may see."""
    p = params
    w = Where().raw("l.action <> 'screen.view'")
    for word in [x for x in p.q.split() if x][:6]:
        n = w.p(like(word))
        w.raw(f"(l.action ILIKE {n} OR l.entity_type ILIKE {n} OR COALESCE(l.after->>'title', '') ILIKE {n} OR COALESCE(l.after->>'name', '') ILIKE {n} OR COALESCE(l.after->>'label', '') ILIKE {n} "
              f"OR COALESCE(l.after->>'key_label', '') ILIKE {n} OR COALESCE(l.after->>'question_excerpt', '') ILIKE {n} OR COALESCE(l.actor_name, '') ILIKE {n})")
    if p.since:
        w.add("l.occurred_at >= {}", when(p.since, "since"))
    if p.base not in (None, ""):
        w.add("l.base_id = {}", int((await base_ref(q, p.base))["base_id"]))
    rows = await q(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(p.limit, 50, 200)}", *w.args)
    return dumps(rows)


@mcp.tool(name="activity_query", annotations={"title": "Guarded read of the trail", **RO})
async def activity_query(params: ActivityQueryIn) -> str:
    """The long tail: one read-only SELECT over mcp_activity_log and the name views (mcp_members, mcp_departments, mcp_bases, mcp_sources, mcp_focus_areas), run as the asking member — one statement, no ';', 5 seconds, at most
    200 rows."""
    return await db.run_search(await _pool(), params.sql)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", port=int(db.ENV["MCP_ACTIVITY_PORT"]), endpoint_name="Activity MCP")
