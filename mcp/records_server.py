"""knowledge_records_mcp — Knowledge's record-memory server (PostgreSQL, the mcp_* views and the kn_* read functions, read-only; the engine through the application's own door).

Tool surface: docs/knowledge-mcp-tool-surface.md. Every tool reads as the asking member, so a person or an agent sees what the screens show them: the bases they own, were named on, or that are shared with everyone
(a guest only what names them), the questions that are theirs or on a base they curate — the views and the functions decide; the server only trims and chooses the error. An agent sees only the tools the kernel granted
it (server_common). The kernel's own token reaches app_roles and the three K7 shares (ask, search, bases_index) as the requesting application. The engine (MaluDB) and kn_ask() are reached only through the application's
own loopback door (the bridge): the MaluDB token is read in PHP alone and a question is stored by PHP, never from the read role. Writes are never here: they are the kernel's Actions MCP, built from
mcp/action_registry.json.
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from kn_common import RO, _Base, bridge, dumps

mcp = FastMCP("knowledge_records_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])


async def q(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


@mcp.tool(name="app_roles", annotations={"title": "Knowledge's roles and rights", **RO})
async def app_roles() -> str:
    """The roles Knowledge offers and the rights each gives — what the Business OS kernel reads to let a super-admin grant them (schema os.app-roles/1): Reader, Member (user), Curator and Knowledge admin, with their
    rights. The catalogue is not about anyone, so the kernel's own token may read it; so may any person or agent."""
    pool = await _pool()
    async with pool.acquire() as con:
        rights = await con.fetch("SELECT right_key, description FROM kn_rights ORDER BY sort_order, right_key")
        roles = await con.fetch("SELECT role_key, name, description, capability, is_admin, sort_order, rights FROM mcp_app_roles ORDER BY sort_order")
    return dumps({
        "schema": "os.app-roles/1",
        "application": "knowledge",
        "rights": [{"key": r["right_key"], "description": r["description"]} for r in rights],
        "roles": [{"key": r["role_key"], "name": r["name"], "description": r["description"], "capability": r["capability"], "is_admin": r["is_admin"], "rights": list(r["rights"])} for r in roles],
    })


class SearchSqlIn(_Base):
    sql: str = Field(..., description="One SELECT (or WITH … SELECT) over the mcp_* views")


@mcp.tool(name="records_search", annotations={"title": "Guarded read of the views", **RO})
async def records_search(params: SearchSqlIn) -> str:
    """The long tail: one read-only SELECT (or WITH … SELECT) over the mcp_* views, run as the asking member — one statement, no ';', 5 seconds, at most 200 rows. The readable views: mcp_members, mcp_departments,
    mcp_settings, mcp_templates, mcp_bases, mcp_base_members, mcp_sources, mcp_source_pages, mcp_sections, mcp_attachments, mcp_notes, mcp_guidelines, mcp_guideline_versions, mcp_vocabulary_terms, mcp_focus_areas,
    mcp_source_focus_areas, mcp_builds, mcp_build_items, mcp_questions, mcp_citations, mcp_question_feedback, mcp_pinned_answers, mcp_keys, mcp_feeds, mcp_ai_usage, mcp_agent_dispatches, mcp_archivist_proposals,
    mcp_notifications, mcp_notification_prefs, mcp_access_tokens_mine, mcp_app_roles — and the read functions kn_base_overview, kn_source_outline, kn_questions, kn_question_gaps, kn_build_progress,
    kn_build_estimate, kn_usage, kn_unassigned_sources, kn_empty_areas, kn_unused_terms. It is NOT the evidence search: that is `search`; the graph is `graph_query`. Anything that is not a plain read is refused."""
    return await db.run_search(await _pool(), params.sql)


import kn_admin  # noqa: E402
import kn_asking  # noqa: E402
import kn_bases  # noqa: E402
import kn_builds  # noqa: E402
import kn_graph  # noqa: E402
import kn_guidelines  # noqa: E402
import kn_sources  # noqa: E402

for module in (kn_bases, kn_sources, kn_guidelines, kn_builds, kn_graph, kn_asking, kn_admin):
    module.register(mcp, q, bridge)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD", port=int(db.ENV["MCP_RECORDS_PORT"]), endpoint_name="Records MCP")
