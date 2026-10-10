"""Shared pieces for Knowledge's tool modules: annotations, the input bases, the where-builder, the refusals (errors are answers, not exceptions — surface "Errors"), the id-or-name resolvers
(through the mcp_* views, so a thing the caller may not see does not exist for them), the plain-list answer the kernel's entity resolver reads, and THE BRIDGE: the signed loopback post to
html/internal/bridge.php that reaches kn_ask() and the engine (docs/knowledge-mcp-tool-surface.md "The engine bridge"). Every query runs as the asking member (db.fetch_scoped sets
app.member_id from the verified bearer): the views and the functions decide the rows; nothing here filters by who is asking. The MaluDB token is never read here."""
from __future__ import annotations

import datetime
import functools
import hashlib
import hmac
import json
import re
import time

import asyncpg
import httpx
from pydantic import BaseModel, ConfigDict

import db
import server_common

RO = {"readOnlyHint": True, "openWorldHint": False}
Ref = int | str          # an id or a name


class _Base(BaseModel):
    model_config = ConfigDict(str_strip_whitespace=True, extra="forbid", populate_by_name=True)


def dumps(obj) -> str:
    return db.to_json(obj)


class Refusal(Exception):
    """An answer, not a crash: the code, the sentence, whether trying again could help, and any extra keys (fields, candidates, detail)."""

    def __init__(self, code: str, message: str, retry: bool = False, **extra) -> None:
        super().__init__(message)
        self.code, self.message, self.retry, self.extra = code, message, retry, extra


def fail(code: str, message: str, retry: bool = False, **extra) -> str:
    return dumps({"ok": False, "error": {"code": code, "message": message, "retry": retry, **extra}})


def answers(fn):
    """Turn a Refusal (and the database's refusals) into the tool's error answer; the tool never raises."""
    @functools.wraps(fn)
    async def inner(*a, **k):
        try:
            return await fn(*a, **k)
        except Refusal as r:
            return fail(r.code, r.message, r.retry, **r.extra)
        except asyncpg.PostgresError as ex:
            return fail("invalid_argument" if isinstance(ex, (asyncpg.DataError, asyncpg.InvalidTextRepresentationError)) else "server_error",
                        "The question could not be answered: " + ex.__class__.__name__ + ".", retry=not isinstance(ex, asyncpg.DataError))
    return inner


def lim(n: int | None, default: int = 25, top: int = 100) -> int:
    return max(1, min(int(n or default), top))


def off(n: int | None) -> int:
    return max(0, min(int(n or 0), 10000))


class Where:
    """Builds `a = $1 AND b = ANY($2)` with positional parameters in order."""

    def __init__(self) -> None:
        self.parts: list[str] = []
        self.args: list = []

    def p(self, value) -> str:
        self.args.append(value)
        return f"${len(self.args)}"

    def add(self, sql: str, *values) -> "Where":
        self.parts.append(sql.format(*[self.p(v) for v in values]))
        return self

    def raw(self, sql: str) -> "Where":
        self.parts.append(sql)
        return self

    def sql(self) -> str:
        return " AND ".join(self.parts) if self.parts else "TRUE"


def like(s: str) -> str:
    return "%" + s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%"


def q_alone(p) -> bool:
    """True when only `q` (and perhaps `limit`) was given — the kernel's entity resolver's call, answered as a plain list."""
    return bool(getattr(p, "q", None)) and set(p.model_fields_set) <= {"q", "limit"}


def plain(rows: list[dict], id_field: str, label_field: str, *more: str) -> str:
    return dumps([{id_field: r[id_field], label_field: r[label_field], **{m: r.get(m) for m in more}} for r in rows[:6]])


def qmatch(w: "Where", q: str | None, id_col: str, *name_cols: str) -> None:
    """The resolvers' `q`: digits are an id (or a name made of digits), anything else is looked for in the names (ILIKE)."""
    if not q:
        return
    n = w.p(like(q))
    names = " OR ".join(f"{c} ILIKE {n}" for c in name_cols)
    if q.strip().isdigit():
        w.raw(f"({id_col} = {w.p(int(q))} OR {names})")
    else:
        w.raw(f"({names})")


def when(s, what: str = "The time", end_of_day: bool = False) -> datetime.datetime | None:
    """A YYYY-MM-DD day or an ISO 8601 time as an aware datetime (None stays None); anything else is a sentence."""
    if s is None or s == "":
        return None
    if isinstance(s, datetime.datetime):
        return s if s.tzinfo else s.replace(tzinfo=datetime.timezone.utc)
    t = str(s).strip()
    try:
        if re.fullmatch(r"\d{4}-\d{2}-\d{2}", t):
            d = datetime.date.fromisoformat(t)
            return datetime.datetime.combine(d + datetime.timedelta(days=1 if end_of_day else 0), datetime.time.min, tzinfo=datetime.timezone.utc)
        v = datetime.datetime.fromisoformat(t.replace("Z", "+00:00"))
    except ValueError as ex:
        raise Refusal("invalid_argument", f"{what} is a day (2026-03-02) or an ISO time (2026-03-02T09:30:00Z).", fields=[what]) from ex
    return v if v.tzinfo else v.replace(tzinfo=datetime.timezone.utc)


def ago(days: int) -> datetime.datetime:
    return datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=days)


def is_int(v) -> bool:
    return isinstance(v, int) or (isinstance(v, str) and v.strip().isdigit())


def cut(s: str | None, n: int) -> str | None:
    return None if s is None else (s if len(s) <= n else s[: n - 1].rstrip() + "…")


async def flag(q, sql: str, *args) -> bool:
    rows = await q("SELECT " + sql + " AS ok", *args)
    return bool(rows and rows[0]["ok"])


async def me(q) -> int | None:
    rows = await q("SELECT nullif(current_setting('app.member_id', true), '')::bigint AS m")
    return rows[0]["m"] if rows else None


def candidates(rows: list[dict], id_field: str, label_field: str) -> list[dict]:
    return [{"id": r[id_field], "label": r[label_field]} for r in rows[:6]]


def ambiguous(what: str, v, rows: list[dict], id_field: str, label_field: str) -> Refusal:
    return Refusal("ambiguous", f"More than one {what} matches '{v}'; ask which (or give its id).", candidates=candidates(rows, id_field, label_field))


def not_found(what: str = "record") -> Refusal:
    return Refusal("not_found", f"No {what} you can read matches that.")


def curators_only(what: str = "This") -> Refusal:
    return Refusal("curators_only", f"{what} is for the people who curate the base.")


# ---- id-or-name resolvers: through the views, so what the caller may not see is simply not there ---------------------------------------

async def base_ref(q, v, *, curate: bool = False, include_archived: bool = True) -> dict:
    """The mcp_bases row (base_id, key, name, visibility, my_level, i_curate, archived_at …) of a base named by id, key or name. A base the caller cannot read is not_found,
    in the same words as one that does not exist; $curate: a curator's tool says curators_only on a base the caller reads but does not curate."""
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the base (its key, its name, or its id).", fields=["base"])
    if is_int(v):
        rows = await q("SELECT * FROM mcp_bases WHERE base_id = $1", int(v))
    else:
        s = str(v).strip()
        rows = await q("SELECT * FROM mcp_bases WHERE lower(key) = lower($1) ORDER BY archived_at NULLS FIRST LIMIT 7", s)
        if not rows:
            rows = await q("SELECT * FROM mcp_bases WHERE lower(name) = lower($1) ORDER BY archived_at NULLS FIRST LIMIT 7", s)
        live = [r for r in rows if r["archived_at"] is None]
        rows = live or rows
        if not rows:
            rows = await q("SELECT * FROM mcp_bases WHERE name ILIKE $1 AND archived_at IS NULL ORDER BY name LIMIT 7", like(s))
        if len(rows) > 1:
            raise ambiguous("base", s, rows, "base_id", "name")
    if not rows or (rows[0]["archived_at"] is not None and not include_archived):
        raise not_found("knowledge base")
    row = rows[0]
    if curate and not row["i_curate"]:
        raise curators_only()
    return row


async def area_ref(q, v, base_id: int | None = None) -> dict:
    """The mcp_focus_areas row of an area named by id, slug or name (inside $base_id when given)."""
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the focus area (its slug, its name, or its id).", fields=["focus_area"])
    w = Where()
    if base_id is not None:
        w.add("base_id = {}", base_id)
    if is_int(v):
        w.add("focus_area_id = {}", int(v))
        rows = await q(f"SELECT * FROM mcp_focus_areas WHERE {w.sql()}", *w.args)
    else:
        s = str(v).strip()
        w.add("(lower(slug) = lower({0}) OR lower(name) = lower({0}))", s)
        rows = await q(f"SELECT * FROM mcp_focus_areas WHERE {w.sql()} ORDER BY (status = 'archived'), focus_area_id LIMIT 7", *w.args)
        if len(rows) > 1:
            raise ambiguous("focus area", s, rows, "focus_area_id", "name")
    if not rows:
        raise not_found("focus area")
    return rows[0]


async def source_ref(q, v, base_id: int | None = None) -> dict:
    """The mcp_sources row of a source named by id or title."""
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the source (its title, or its id).", fields=["source"])
    w = Where()
    if base_id is not None:
        w.add("base_id = {}", base_id)
    if is_int(v):
        w.add("source_id = {}", int(v))
        rows = await q(f"SELECT * FROM mcp_sources WHERE {w.sql()}", *w.args)
    else:
        s = str(v).strip()
        rows = await q(f"SELECT * FROM mcp_sources WHERE {w.sql()} AND lower(title) = lower({w.p(s)}) AND status <> 'forgotten' ORDER BY source_id LIMIT 7", *w.args)
        if not rows:
            w2 = Where()
            if base_id is not None:
                w2.add("base_id = {}", base_id)
            rows = await q(f"SELECT * FROM mcp_sources WHERE {w2.sql()} AND title ILIKE {w2.p(like(s))} AND status <> 'forgotten' ORDER BY title LIMIT 7", *w2.args)
        if len(rows) > 1:
            raise ambiguous("source", s, rows, "source_id", "title")
    if not rows:
        raise not_found("source")
    return rows[0]


async def section_ref(q, v) -> dict:
    """The mcp_sections row of a section id (ids only: sections are never named)."""
    if not is_int(v):
        raise Refusal("invalid_argument", "A section is named by its id (read it from source_outline).", fields=["section"])
    rows = await q("SELECT * FROM mcp_sections WHERE section_id = $1", int(v))
    if not rows:
        raise not_found("section")
    return rows[0]


async def member_ref(q, v) -> int:
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the member (their id, or their name).", fields=["member"])
    if is_int(v):
        rows = await q("SELECT member_id FROM mcp_members WHERE member_id = $1", int(v))
    else:
        rows = await q("SELECT member_id, display_name FROM mcp_members WHERE lower(display_name) = lower($1) LIMIT 7", str(v).strip())
        if len(rows) > 1:
            raise ambiguous("member", v, rows, "member_id", "display_name")
    if not rows:
        raise not_found("member")
    return int(rows[0]["member_id"])


async def guideline_ref(q, v, base_id: int | None = None) -> dict:
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the guideline (its title, or its id).", fields=["guideline"])
    w = Where()
    if base_id is not None:
        w.add("base_id = {}", base_id)
    if is_int(v):
        w.add("guideline_id = {}", int(v))
        rows = await q(f"SELECT * FROM mcp_guidelines WHERE {w.sql()}", *w.args)
    else:
        rows = await q(f"SELECT * FROM mcp_guidelines WHERE {w.sql()} AND lower(title) = lower({w.p(str(v).strip())}) ORDER BY guideline_id LIMIT 7", *w.args)
        if len(rows) > 1:
            raise ambiguous("guideline", v, rows, "guideline_id", "title")
    if not rows:
        raise not_found("guideline")
    return rows[0]


# ---- the bridge ---------------------------------------------------------------------------------------------------------------------------

BRIDGE_WAIT = {"ask": 65, "search": 35, "share_ask": 55, "share_search": 55, "share_bases": 7, "graph_query": 20, "duplicate_subjects": 20}
ENGINE_OPS = {"ask", "search", "suggest", "graph_stats", "communities", "community_members", "subject", "subject_neighbors", "subject_statements", "path", "god_nodes", "surprises",
              "graph_query", "section_graph", "source_graph", "sections_for_subject", "duplicate_subjects", "preview", "share_ask", "share_search"}
# The codes the PHP side answers with that a tool passes on in its own words (anything else on a failure is bridge_unavailable).
PASSED = {"not_found": "not_found", "invalid": "invalid_argument", "invalid_argument": "invalid_argument", "curators_only": "curators_only", "not_in_base": "not_in_base",
          "no_path_in_base": "no_path_in_base", "engine_unavailable": "engine_unavailable", "engine_timeout": "engine_timeout", "engine_error": "engine_error",
          "unknown_member": "unknown_member", "caller_not_allowed": "forbidden", "unknown_op": "bridge_unavailable"}


async def bridge(op: str, args: dict | None = None, *, application: bool = False) -> dict:
    """POST to the application's own loopback door (html/internal/bridge.php), signed with ACTIONS_RELAY_KEY: the HMAC covers the timestamp and the body's sha256. The caller is the verified
    member (door mcp or agent) or, for the kernel's token, the consumer the kernel named in X-OS-Consumer (K26) — never an argument. Returns the decoded answer; raises Refusal with the PHP side's code."""
    key = db.ENV.get("ACTIONS_RELAY_KEY", "")
    port = db.ENV.get("APP_INTERNAL_PORT", "")
    if len(key) < 32 or not port:
        raise Refusal("bridge_unavailable", "The application's own door is not configured (ACTIONS_RELAY_KEY and APP_INTERNAL_PORT).", retry=False)
    ctx = await server_common.run_context()
    if application:
        caller: dict = {"kind": "application", "application": db.request_consumer.get(), "agent_member_id": db.request_consumer_agent.get()}
        ctx = {"run_id": None, "request_id": None, "is_eval": False, "door": "share"}
    else:
        member = db.request_member_id.get()
        if member is None:
            raise Refusal("not_found", "Who is asking? No member is behind this call.")
        caller = {"kind": "member", "member_id": int(member)}
    body = json.dumps({"op": op, "caller": caller, "door": ctx["door"], "run_id": ctx["run_id"], "request_id": ctx["request_id"], "is_eval": ctx["is_eval"], "args": args or {}},
                      separators=(",", ":"), default=str).encode()
    ts = str(int(time.time()))
    sig = hmac.new(key.encode(), f"kn-bridge:{ts}.{hashlib.sha256(body).hexdigest()}".encode(), hashlib.sha256).hexdigest()
    try:
        async with httpx.AsyncClient(timeout=BRIDGE_WAIT.get(op, 15)) as client:
            r = await client.post(f"http://127.0.0.1:{port}/internal/bridge.php", content=body, headers={"Content-Type": "application/json", "X-KN-Bridge": f"{ts}.{sig}"})
    except httpx.TimeoutException as ex:
        if op in ENGINE_OPS:
            raise Refusal("engine_timeout", "MaluDB did not answer in time.", retry=True) from ex
        raise Refusal("bridge_unavailable", "The application's own door did not answer in time.", retry=True) from ex
    except httpx.HTTPError as ex:
        raise Refusal("bridge_unavailable", "The application's own door could not be reached.", retry=True) from ex
    try:
        data = r.json()
    except ValueError:
        data = {}
    if r.status_code == 200 and isinstance(data, dict):
        return data
    err = (data.get("error") if isinstance(data, dict) else None) or {}
    code = str(err.get("code") or "")
    if code in PASSED and r.status_code != 401:
        extra = {}
        if err.get("fields"):
            extra["fields"] = err["fields"]
        if err.get("detail"):
            extra["detail"] = err["detail"]
        raise Refusal(PASSED[code], str(err.get("message") or code), retry=code in ("engine_unavailable", "engine_timeout"), **extra)
    raise Refusal("bridge_unavailable", "The application's own door refused or failed (" + str(r.status_code) + ").", retry=r.status_code >= 500)


# ---- every field of every parameter model carries a description (the surface: "a described Field on every field") --------------------------------------------------------------------------------

DESCRIBE = {
    "limit": "How many rows at most", "offset": "Skip this many rows (paging)", "since": "A day or ISO time — only what is newer", "base": "The knowledge base (key, name or id)",
    "include_archived": "Include archived ones", "include_inactive": "Include inactive ones", "include_forgotten": "Include forgotten ones", "mine": "Only mine",
    "focus_area": "A focus area of that base (slug, name or id)", "area": "A focus area (slug, name or id)", "source": "The source (title or id)", "section": "The section (id)",
    "q": "Words to look for (also resolves a record by its name)", "kind": "The kind", "status": "The status", "guideline": "A guideline (title or id)", "subject": "A subject of the graph (id or name)",
    "question": "The question (id)", "month": "The month, YYYY-MM", "text": "The words", "member": "A person or an agent (id or name)", "department": "A department (id or name)",
}
KIND = {"FindSourcesIn": "file, text, url, feed or note", "FindGuidelinesIn": "extraction, vocabulary, exclusion, answering or audience", "FindBuildsIn": "extract, rebuild_all, reindex, embed or forget",
        "MembersIn": "human or agent", "ProposalsIn": "area, merge, gap, stale_source, failed_source, review_pin, unassigned, unverified, feed_stopped, limit_near or url_changed",
        "DispatchesIn": "ask, propose_areas, draft_guidelines or report", "BaseMembersIn": "member, department, agent, application or everyone", "SubjectNeighborsIn": "subject, document or statement",
        "BaseReadersIn": "agent or application"}
STATUS = {"FindBasesIn": "empty, building, ready or needs_rebuild", "FindSourcesIn": "uploaded, extracting_text, sectioning, ready, no_text, failed or forgotten", "FindBuildsIn": "queued, running, done, failed or cancelled",
          "ProposalsIn": "proposed, accepted or dismissed", "DispatchesIn": "sent, answered, refused, failed or awaiting_approval", "FindFocusAreasIn": "proposed, active or archived",
          "MyQuestionsIn": "answered, evidence_only, no_evidence, unverified, refused or failed", "QuestionsIn": "answered, evidence_only, no_evidence, unverified, refused or failed",
          "PinnedIn": "active or retired", "BuildProgressIn": "queued, running, done, failed or skipped (items)"}


def describe_models(ns: dict) -> None:
    """Give every field without a description one (called at the foot of each tool module, before register() builds the tools)."""
    for obj in list(ns.values()):
        if isinstance(obj, type) and issubclass(obj, BaseModel) and obj.__module__ == ns["__name__"] and obj is not _Base:
            changed = False
            for name, f in obj.model_fields.items():
                if not f.description:
                    f.description = (KIND.get(obj.__name__) if name == "kind" else STATUS.get(obj.__name__) if name == "status" else None) or DESCRIBE.get(name) or name.replace("_", " ").capitalize()
                    changed = True
            if changed:
                obj.model_rebuild(force=True, _types_namespace=ns)
