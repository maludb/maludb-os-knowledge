"""Builds (L1–L3): build_progress, find_builds, build_estimate, base_usage. docs/knowledge-mcp-tool-surface.md "Builds". Builds, their items and the usage are the CURATOR's: the views show nobody else a row and
a tool that names a base says curators_only. Usage is tokens and characters, never dollars (the kernel's AI statement prices them, K24)."""
from __future__ import annotations

import datetime
import re
from typing import Literal

from pydantic import Field

from kn_common import RO, Ref, Refusal, Where, _Base, answers, base_ref, cut, describe_models, dumps, flag, lim, off, when


class BuildProgressIn(_Base):
    build: int | None = Field(None, ge=1, description="The build (id); or give base for its running build, else its last")
    base: Ref | None = Field(None, description="The base (key, name or id)")
    items: Literal["queued", "running", "done", "failed", "skipped"] | None = Field(None, description="Also list the build's items in this state")
    limit: int = Field(50, ge=1, le=100, description="How many items at most")


class FindBuildsIn(_Base):
    base: Ref | None = Field(None, description="The base; an admin may leave it out for every base")
    status: Literal["queued", "running", "done", "failed", "cancelled"] | None = Field(None, description="Only builds in this state")
    kind: Literal["extract", "rebuild_all", "reindex", "embed", "forget"] | None = Field(None, description="Only this kind of build")
    since: str | None = Field(None, description="A day or ISO time — only builds requested since")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class EstimateIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    kind: Literal["extract", "rebuild_all"] = Field("extract", description="extract: the new, stale and failed sections; rebuild_all: every live section")


class UsageIn(_Base):
    base: Ref | None = Field(None, description="The base; leave out for every base (needs the costs right)")
    month: str | None = Field(None, description="The month, YYYY-MM (default: this month)")


describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="build_progress", annotations={"title": "How a build is going", **RO})
    @answers
    async def build_progress(params: BuildProgressIn) -> str:
        """Is a build running on base X, how far along, how long it has left, and what failed. Name the build, or the base (its running build, else its last). Give `items` to list the build's items in one state.
        For the base's curators."""
        p = params
        if p.build is None and p.base in (None, ""):
            raise Refusal("invalid_argument", "Name the build, or the base.", fields=["build", "base"])
        if p.build is not None:
            row = await q("SELECT build_id, base_id FROM mcp_builds WHERE build_id = $1", p.build)
            if not row:
                raise Refusal("not_found", "No such build among the bases you curate.")
        else:
            b = await base_ref(q, p.base, curate=True)
            row = await q("SELECT build_id, base_id FROM mcp_builds WHERE base_id = $1 ORDER BY (status IN ('queued', 'running')) DESC, requested_at DESC, build_id DESC LIMIT 1", int(b["base_id"]))
            if not row:
                raise Refusal("not_found", "This base has not been built yet.")
        bid = int(row[0]["build_id"])
        doc = (await q("SELECT kn_build_progress($1) AS d", bid))[0]["d"]
        if doc is None:
            raise Refusal("not_found", "No such build among the bases you curate.")
        failed = doc.get("failed_items") or []
        doc["failed_items_truncated"] = len(failed) > 50
        doc["failed_items"] = [dict(f, error=cut(f.get("error"), 300)) for f in failed[:50]]
        if p.items:
            doc["items"] = await q("""SELECT build_item_id, section_id, heading_path, status, chars, started_at, finished_at, left(error, 300) AS error FROM mcp_build_items
                                       WHERE build_id = $1 AND status = $2 ORDER BY build_item_id LIMIT """ + str(lim(p.limit, 50)), bid, p.items)
        return dumps(doc)

    @mcp.tool(name="find_builds", annotations={"title": "Find builds", **RO})
    @answers
    async def find_builds(params: FindBuildsIn) -> str:
        """The builds of a base (a build is one extraction run), newest first: kind, state, who started it, how many sections it did and failed, the characters and the graph it added. The model's usage
        is shown only with the costs right. For the base's curators; an admin may leave the base out."""
        p = params
        w = Where()
        if p.base not in (None, ""):
            w.add("b.base_id = {}", int((await base_ref(q, p.base, curate=True))["base_id"]))
        elif not await flag(q, "kn_is_admin()"):
            raise Refusal("invalid_argument", "Name the base.", fields=["base"])
        if p.status:
            w.add("b.status = {}", p.status)
        if p.kind:
            w.add("b.kind = {}", p.kind)
        if p.since:
            w.add("b.requested_at >= {}", when(p.since, "since"))
        costs = await flag(q, "kn_has_right('costs.read')")
        rows = await q(f"""SELECT b.build_id, b.base_id, b.kind, b.status, b.guideline_version, b.started_by_name, b.requested_at, b.started_at, b.finished_at, b.items_total, b.items_done, b.items_failed,
                                  b.chars_processed, b.subjects_added, b.statements_added, left(b.error, 300) AS error {', b.usage' if costs else ''}
                             FROM mcp_builds b WHERE {w.sql()} ORDER BY b.requested_at DESC, b.build_id DESC LIMIT {lim(p.limit)} OFFSET {off(p.offset)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="build_estimate", annotations={"title": "What a build would do now", **RO})
    @answers
    async def build_estimate(params: EstimateIn) -> str:
        """What a build would do right now: how many sections and characters, this month's use against the monthly limit, and whether it fits. `extract` counts the new, stale and failed sections;
        `rebuild_all` every live section. For the base's curators."""
        b = await base_ref(q, params.base, curate=True)
        doc = (await q("SELECT kn_build_estimate($1, $2) AS d", int(b["base_id"]), params.kind))[0]["d"]
        if doc is None:
            raise Refusal("not_found", "No knowledge base you can read matches that.")
        return dumps(doc)

    @mcp.tool(name="base_usage", annotations={"title": "Engine usage of a base", **RO})
    @answers
    async def base_usage(params: UsageIn | None = None) -> str:
        """How much text and how many engine calls a base used in a month, by kind (extract, answer, embed), against its monthly limit. With no base: every base, which needs the costs right. Never dollars —
        the kernel's AI statement prices the tokens."""
        p = params or UsageIn()
        first = None
        if p.month:
            if not re.fullmatch(r"\d{4}-(0[1-9]|1[0-2])", p.month):
                raise Refusal("invalid_argument", "The month is written YYYY-MM.", fields=["month"])
            first = datetime.date.fromisoformat(p.month + "-01")
        if p.base not in (None, ""):
            b = await base_ref(q, p.base, curate=True)
            rows = await q("SELECT kind, calls, input_tokens, output_tokens, chars, updated_at FROM kn_usage($1, COALESCE($2::date, date_trunc('month', now())::date))", int(b["base_id"]), first)
            est = (await q("SELECT kn_build_estimate($1, 'extract') AS d", int(b["base_id"])))[0]["d"] or {}
            used = sum(int(r["chars"]) for r in rows if r["kind"] == "extract")
            return dumps({"base_id": b["base_id"], "month": p.month or est.get("month"), "rows": rows, "month_limit_chars": est.get("month_limit_chars"), "month_used_chars": used})
        if not await flag(q, "kn_has_right('costs.read')"):
            raise Refusal("curators_only", "Usage across every base needs the costs right; name a base you curate.")
        rows = await q("""SELECT u.base_id, b.name AS base_name, u.kind, u.calls, u.input_tokens, u.output_tokens, u.chars, u.updated_at FROM mcp_ai_usage u JOIN mcp_bases b ON b.base_id = u.base_id
                           WHERE u.month = COALESCE($1::date, date_trunc('month', now())::date) ORDER BY b.name, u.kind""", first)
        return dumps(rows)
