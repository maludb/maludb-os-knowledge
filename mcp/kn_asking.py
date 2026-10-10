"""Asking (Q1–Q7) and the K7 shares ask / search / bases_index: ask, search, suggested_questions, my_questions, get_question, questions, question_feedback, question_gaps, pinned_answers, pins_due,
bases_index. docs/knowledge-mcp-tool-surface.md "Asking" and "The shares". `ask` and `search` are ONE tool each for every door: as a person or an agent they post to the engine bridge as that member
(door mcp / agent: the PHP side runs kn_ask() / kn_search() with the screens' own gates and writes the question row); under the kernel's own token they post as the APPLICATION PRINCIPAL the kernel
named in X-OS-Consumer (K26) — never an argument — and the base must be one shared with that application. Nothing here writes: a question is stored by PHP, never from the read role."""
from __future__ import annotations

import datetime
import urllib.parse
from typing import Literal

from pydantic import Field

import db
from kn_common import RO, Ref, Refusal, Where, _Base, answers, area_ref, base_ref, bridge, cut, describe_models, dumps, lim, like, me, member_ref, off, plain, q_alone, when

QUESTION_STATUSES = Literal["answered", "evidence_only", "no_evidence", "unverified", "refused", "failed"]


class AskIn(_Base):
    base: Ref | None = Field(None, description="The base (key, name or id); required unless conversation_id names one of your conversations")
    question: str = Field(..., min_length=1, max_length=2000, description="The question, in the asker's own words")
    focus_area: Ref | None = Field(None, description="Narrow the question to one active focus area of that base (slug, name or id)")
    conversation_id: str | None = Field(None, max_length=120, description="Continue a conversation: its id from an earlier answer (yours alone)")


class SearchIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    query: str = Field(..., min_length=1, max_length=2000, description="What to find evidence on")
    focus_area: Ref | None = Field(None, description="Narrow the search to one active focus area (slug, name or id)")
    limit: int = Field(8, ge=1, le=20, description="How many passages at most")


class SuggestedIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    focus_area: Ref | None = Field(None, description="Questions of this focus area first (slug, name or id)")
    limit: int = Field(6, ge=1, le=20, description="How many questions at most")


class MyQuestionsIn(_Base):
    base: Ref | None = Field(None, description="Only questions on this base")
    status: QUESTION_STATUSES | None = Field(None, description="Only questions with this outcome")
    since: str | None = Field(None, description="A day or ISO time — only what is newer")
    conversation_id: str | None = Field(None, max_length=120, description="Only the turns of this conversation")
    q: str | None = Field(None, max_length=200, description="Words of the question; with nothing else it resolves one of your questions")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class GetQuestionIn(_Base):
    question: int = Field(..., ge=1, description="The question (id)")


class QuestionsIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    status: QUESTION_STATUSES | None = Field(None, description="Only questions with this outcome")
    since: str | None = Field(None, description="A day or ISO time — only what is newer")
    asked_by: Ref | None = Field(None, description="Only this person's or agent's questions (id or name)")
    door: Literal["web", "api", "mcp", "share", "agent"] | None = Field(None, description="Only questions that came through this door")
    key: int | None = Field(None, ge=1, description="Only questions asked with this API key (id from find_keys)")
    focus_area: Ref | None = Field(None, description="Only questions in this focus area")
    q: str | None = Field(None, max_length=200, description="Words of the question or the answer")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class FeedbackIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    rating: Literal["up", "down"] | None = Field(None, description="Only thumbs up or only thumbs down")
    since: str | None = Field(None, description="A day or ISO time — only what is newer")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class GapsIn(_Base):
    base: Ref = Field(..., description="The base (key, name or id)")
    since: str | None = Field(None, description="A day or ISO time; the last 30 days by default")
    limit: int = Field(25, ge=1, le=100, description="How many clusters at most")


class PinnedIn(_Base):
    base: Ref | None = Field(None, description="The base; required unless q is given")
    q: str | None = Field(None, max_length=200, description="Words of the title or the question; with nothing else it resolves a pinned answer")
    status: Literal["active", "retired"] = Field("active", description="active (default) or retired")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class PinsDueIn(_Base):
    base: Ref | None = Field(None, description="Only this base")
    owner: Literal["me"] | None = Field(None, description="'me': only the pinned answers I own")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class BasesIndexIn(_Base):
    q: str | None = Field(None, max_length=100, description="Words of a base's name")


describe_models(globals())

ENGINE_WORDS = {"engine_unavailable": "The knowledge engine is not available right now.", "engine_timeout": "The knowledge engine took too long to answer. Try again in a moment.",
                "engine_auth": "The knowledge engine refused this installation's credentials. An administrator must check them.",
                "engine_forbidden": "The knowledge engine refused this installation's credentials. An administrator must check them.",
                "invalid_argument": "The request was not valid.", "answer_unavailable": "Composed answers are not available on this engine yet; the matching passages are shown instead.",
                "project_not_found": "That focus area is not in the knowledge graph yet. Rebuild the base.", "not_found": "That item is no longer in the knowledge graph.",
                "model_failed": "The language model failed to answer. Try again later.",
                "model_not_configured": "No language model is configured for this knowledge base. An administrator must set one.",
                "model_api_key_missing": "No language model is configured for this knowledge base. An administrator must set one.",
                "no_edges": "Nothing could be extracted from this section.", "conflict": "The knowledge graph refused a duplicate.",
                "validation_failed": "The knowledge engine did not accept the request."}


def status_words(status: str, error: str | None) -> str | None:
    """The plain words of a status (the same sentences as app/features/ask/present.php ask_status_words)."""
    if status == "evidence_only":
        return ("This installation cannot compose answers yet, so here are the passages the base holds on your question." if error == "answer_unavailable"
                else "The model could not be reached, so here are the passages the base holds.")
    if status == "no_evidence":
        return "This knowledge base holds nothing on this. It has been noted so a curator can add a source."
    if status == "unverified":
        return "An answer was drafted, but its quotes could not be checked against the sources, so it is withheld. Here is what the base holds."
    if status == "refused":
        return {"base_not_built": "This base has no built documents yet.", "area_not_built": "This focus area is not in the graph yet — it is added at the next build."}.get(
            error or "", "The knowledge base could not take this question as it was asked.")
    if status == "failed":
        return "The knowledge base could not be asked just now — " + (ENGINE_WORDS.get(error or "", "The knowledge engine could not complete the request.") if error else "")
    return None


def section_ref_doc(section_id, heading_path, source_id, source_title, source_kind, page_no) -> dict:
    return {"id": int(section_id), "heading_path": heading_path or "", "source": {"id": int(source_id or 0), "title": source_title or "", "kind": source_kind or ""},
            "page": None if page_no is None else int(page_no)}


def cite_url(section_id: int, quote: str) -> str:
    return db.ENV.get("APP_URL", "").rstrip("/") + f"/sections/{section_id}?quote=" + urllib.parse.quote(quote[:200], safe="")


def register(mcp, q, bridge_=None) -> None:
    async def conversation_base(conversation_id: str) -> int | None:
        rows = await q("SELECT base_id FROM mcp_questions WHERE conversation_id = $1 AND asked_by_member_id = app_current_member_id() AND door IN ('web', 'mcp', 'agent') "
                       "ORDER BY question_id DESC LIMIT 1", conversation_id)
        return int(rows[0]["base_id"]) if rows else None

    @mcp.tool(name="ask", annotations={"title": "Ask a knowledge base", **RO})
    @answers
    async def ask(params: AskIn) -> str:
        """ANSWER THIS FROM BASE X, OR FOCUS AREA A, WITH CITATIONS. The one tool behind every door: call it before answering any question about what a base holds, and never answer such a question from memory. It answers
        `answered` with the answer in Markdown and citations to the exact words and sections it relies on (every quote verified), or says plainly it has nothing (`no_evidence`), withheld it (`unverified`), could only
        find passages (`evidence_only`) or could not take the question (`refused`, `failed`). Keep `conversation_id` to ask a follow-up. Not for raw passages — use search."""
        p = params
        if db.request_is_kernel.get():                        # the K7 share: the application principal the kernel named; the gate is the base's application row
            a = {"base": p.base, "question": p.question, "focus_area": p.focus_area, "conversation_id": p.conversation_id}
            return dumps(await bridge("share_ask", {k: v for k, v in a.items() if v not in (None, "")}, application=True))
        bid = None
        if p.base not in (None, ""):
            bid = int((await base_ref(q, p.base))["base_id"])
        elif p.conversation_id:
            bid = await conversation_base(p.conversation_id)
        if bid is None:
            raise Refusal("invalid_argument", "Name the base, or the conversation you are continuing.", fields=["base"])
        a = {"base": bid, "question": p.question}
        if p.focus_area not in (None, ""):
            a["focus_area"] = int((await area_ref(q, p.focus_area, bid))["focus_area_id"])
        if p.conversation_id:
            a["conversation_id"] = p.conversation_id
        return dumps(await bridge("ask", a))

    @mcp.tool(name="search", annotations={"title": "Find evidence in a knowledge base", **RO})
    @answers
    async def search(params: SearchIn) -> str:
        """WHAT EVIDENCE BASE X HOLDS ON THIS: the passages and statements that match, each with its section, page and similarity — no composed answer. Use it when you will compose the answer yourself, or when `ask`
        came back `unverified`. Nothing is stored. As a share, the base must be shared with the asking application."""
        p = params
        if db.request_is_kernel.get():
            a = {"base": p.base, "query": p.query, "focus_area": p.focus_area, "limit": p.limit}
            return dumps(await bridge("share_search", {k: v for k, v in a.items() if v not in (None, "")}, application=True))
        bid = int((await base_ref(q, p.base))["base_id"])
        a = {"base": bid, "query": p.query, "limit": p.limit}
        if p.focus_area not in (None, ""):
            a["focus_area"] = int((await area_ref(q, p.focus_area, bid))["focus_area_id"])
        return dumps(await bridge("search", a))

    @mcp.tool(name="suggested_questions", annotations={"title": "Questions a base can answer", **RO})
    @answers
    async def suggested_questions(params: SuggestedIn) -> str:
        """What base X (or one focus area of it) could answer: its areas' example questions, its pinned answers' questions and, when the engine is up, 'What does the base say about …?' for its most connected
        subjects. For an empty ask window, or an agent offering help. Never another person's question."""
        p = params
        bid = int((await base_ref(q, p.base))["base_id"])
        a: dict = {"base": bid, "limit": p.limit}
        if p.focus_area not in (None, ""):
            a["focus_area"] = int((await area_ref(q, p.focus_area, bid))["focus_area_id"])
        rows = list((await bridge("suggest", a)).get("questions") or [])
        if len(rows) < p.limit:
            try:
                gods = (await bridge("god_nodes", {"base": bid, "limit": 5})).get("god_nodes") or []
            except Refusal:
                gods = []
            seen = {r["question"].lower() for r in rows}
            for g in gods:
                text = f"What does the base say about {g['name']}?"
                if len(rows) < p.limit and text.lower() not in seen and g.get("kind") == "subject":
                    rows.append({"question": text, "from": "graph"})
                    seen.add(text.lower())
        return dumps(rows)

    @mcp.tool(name="my_questions", annotations={"title": "Questions I asked", **RO})
    @answers
    async def my_questions(params: MyQuestionsIn | None = None) -> str:
        """What I asked and what was answered: the question, its outcome, how many citations, my rating, whether it is pinned. Newest first. `q` alone resolves one of my questions (a plain list of {question_id,
        question}). Only my own — a curator reads everyone's with `questions`."""
        p = params or MyQuestionsIn()
        mid = await me(q)
        w = Where().add("q.asked_by_member_id = {}", mid)
        if p.base not in (None, ""):
            w.add("q.base_id = {}", int((await base_ref(q, p.base))["base_id"]))
        if p.status:
            w.add("q.status = {}", p.status)
        if p.since:
            w.add("q.created_at >= {}", when(p.since, "since"))
        if p.conversation_id:
            w.add("q.conversation_id = {}", p.conversation_id)
        if p.q:
            w.add("q.question ILIKE {}", like(p.q))
        rows = await q(f"""SELECT q.question_id, q.base_id, q.focus_area_id, q.conversation_id, q.turn_no, q.door, left(q.question, 300) AS question, q.status, q.confidence, q.citation_count,
                                  (SELECT count(*) FROM mcp_citations c WHERE c.question_id = q.question_id AND c.verified) AS verified_citations,
                                  (SELECT f.rating FROM mcp_question_feedback f WHERE f.question_id = q.question_id AND f.member_id = app_current_member_id()) AS my_rating, q.pinned, q.created_at
                             FROM mcp_questions q WHERE {w.sql()} ORDER BY q.created_at DESC, q.question_id DESC LIMIT {lim(p.limit)} OFFSET {off(p.offset)}""", *w.args)
        if q_alone(p):
            return dumps([{"question_id": r["question_id"], "question": cut(r["question"], 80), "base_id": r["base_id"]} for r in rows[:6]])
        return dumps(rows)

    @mcp.tool(name="get_question", annotations={"title": "One question in full", **RO})
    @answers
    async def get_question(params: GetQuestionIn) -> str:
        """One question in full: the os.knowledge-answer/1 document it was answered with (the answer in Markdown, the citations with their sections and pages, the evidence), and who asked, through which door, when, and
        my rating. The model, usage and latency are shown to the base's curators only. Mine, or any on a base I curate."""
        row = await q("SELECT * FROM mcp_questions WHERE question_id = $1", params.question)
        if not row:
            raise Refusal("not_found", "No such question among those you can read.")
        r = row[0]
        b = (await q("SELECT key, name, i_curate FROM mcp_bases WHERE base_id = $1", int(r["base_id"])))
        if not b:
            raise Refusal("not_found", "No such question among those you can read.")
        area = None
        if r["focus_area_id"] is not None:
            a = await q("SELECT slug, name FROM mcp_focus_areas WHERE focus_area_id = $1", int(r["focus_area_id"]))
            area = {"slug": a[0]["slug"], "name": a[0]["name"]} if a else None
        cites = await q("SELECT rank, section_id, heading_path, source_id, source_title, page_no, quote, verified FROM mcp_citations WHERE question_id = $1 ORDER BY rank", params.question)
        evidence = r["evidence"] or []
        usage = r["usage"] or {}
        kinds = {int(e["source_id"]): e.get("source_kind", "") for e in evidence if e.get("source_id") is not None}
        status, error = r["status"], r["error"]
        doc = {
            "schema": "os.knowledge-answer/1", "generated_at": datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0).isoformat(), "application": "knowledge",
            "base": {"key": b[0]["key"], "name": b[0]["name"]}, "focus_area": area, "question_id": int(r["question_id"]), "conversation_id": r["conversation_id"] or "", "turn_no": int(r["turn_no"]),
            "status": status, "answer_md": r["answer_md"] if status == "answered" else None,
            "confidence": round(float(r["confidence"]), 3) if status == "answered" and r["confidence"] is not None else None,
            "citations": [{"n": int(c["rank"]), "quote": c["quote"], "verified": bool(c["verified"]),
                           "section": section_ref_doc(c["section_id"], c["heading_path"], c["source_id"], c["source_title"], kinds.get(int(c["source_id"] or 0), ""), c["page_no"]),
                           "url": cite_url(int(c["section_id"]), c["quote"])} for c in cites if c["section_id"] is not None],
            "evidence": [{"n": int(e["n"]), "text": e["text"], "similarity": e.get("similarity"), "subject": e.get("subject"), "verb": e.get("verb"),
                          "section": section_ref_doc(e["section_id"], e.get("heading_path"), e.get("source_id"), e.get("source_title"), e.get("source_kind"), e.get("page_no"))} for e in evidence],
            "dropped": int(usage.get("dropped", 0) or 0), "guideline_version": int(r["guideline_version"] or 0), "message": status_words(status, error), "error_code": error,
        }
        mine = await q("SELECT rating FROM mcp_question_feedback WHERE question_id = $1 AND member_id = app_current_member_id()", params.question)
        doc |= {"asker_name": r["asker_name"], "door": r["door"], "created_at": r["created_at"], "my_rating": mine[0]["rating"] if mine else None}
        if r["key_label"]:
            doc["key_label"] = r["key_label"]
        if r["application_key"]:
            doc["application_key"] = r["application_key"]
        if b[0]["i_curate"]:
            doc |= {"usage": usage, "model": r["model"], "latency_ms": r["latency_ms"]}
        return dumps(doc)

    @mcp.tool(name="questions", annotations={"title": "What was asked of a base", **RO})
    @answers
    async def questions(params: QuestionsIn) -> str:
        """What was asked of base X: by whom or through which key, answered or not, rated how. Filter by outcome, time, asker, door, key, focus area or words. Newest first; the question is cut to 300 characters and the
        answer to 600 (read one in full with get_question). For the base's curators."""
        p = params
        b = await base_ref(q, p.base, curate=True)
        asker = None
        if p.asked_by not in (None, ""):
            asker = await member_ref(q, p.asked_by)
        area = int((await area_ref(q, p.focus_area, int(b["base_id"])))["focus_area_id"]) if p.focus_area not in (None, "") else None
        rows = await q("SELECT * FROM kn_questions($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)", int(b["base_id"]), p.status, when(p.since, "since"), asker, p.door, p.key, area, p.q, lim(p.limit), off(p.offset))
        out = []
        for r in rows:
            r = dict(r)
            r["question"], r["answer_md"] = cut(r["question"], 300), cut(r["answer_md"], 600)
            out.append(r)
        return dumps(out)

    @mcp.tool(name="question_feedback", annotations={"title": "Ratings on a base's answers", **RO})
    @answers
    async def question_feedback(params: FeedbackIn) -> str:
        """The thumbs and comments on base X's answers, newest first: who rated (and through which key), the rating, the comment, and the question and its outcome. For the base's curators."""
        p = params
        b = await base_ref(q, p.base, curate=True)
        w = Where().add("f.base_id = {}", int(b["base_id"]))
        if p.rating:
            w.add("f.rating = {}", p.rating)
        if p.since:
            w.add("f.created_at >= {}", when(p.since, "since"))
        rows = await q(f"""SELECT f.feedback_id, f.question_id, f.member_name, f.key_label, f.rating, f.comment, f.created_at, left(q.question, 200) AS question, q.status
                             FROM mcp_question_feedback f LEFT JOIN mcp_questions q ON q.question_id = f.question_id WHERE {w.sql()} ORDER BY f.created_at DESC, f.feedback_id DESC LIMIT {lim(p.limit)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="question_gaps", annotations={"title": "What the base could not answer", **RO})
    @answers
    async def question_gaps(params: GapsIn) -> str:
        """WHAT BASE X COULD NOT ANSWER, clustered by their significant words: each cluster's number of questions, a sample question and when it was last asked — the documents to add next. Questions the base had no
        evidence for or withheld. The last 30 days by default. For the base's curators."""
        p = params
        b = await base_ref(q, p.base, curate=True)
        since = when(p.since, "since") if p.since else None
        rows = await q("SELECT cluster_key, question_count, sample_question, last_asked_at, question_ids FROM kn_question_gaps($1, COALESCE($2::timestamptz, now() - interval '30 days')) LIMIT " + str(lim(p.limit)),
                       int(b["base_id"]), since)
        for r in rows:
            r["sample_question"] = cut(r["sample_question"], 200)
            r["question_ids"] = list(r["question_ids"] or [])[:20]
        return dumps(rows)

    @mcp.tool(name="pinned_answers", annotations={"title": "Pinned answers", **RO})
    @answers
    async def pinned_answers(params: PinnedIn) -> str:
        """The pinned answers of a base — answers a curator vouched for, each with an owner and a review date (review_due when it has passed). `q` alone resolves one by its title (a plain list of
        {pinned_answer_id, title}). Active by default."""
        p = params
        if p.base in (None, "") and not p.q:
            raise Refusal("invalid_argument", "Name the base, or give q to find a pinned answer.", fields=["base"])
        w = Where().add("status = {}", p.status)
        if p.base not in (None, ""):
            w.add("base_id = {}", int((await base_ref(q, p.base))["base_id"]))
        if p.q:
            n = w.p(like(p.q))
            w.raw(f"(title ILIKE {n} OR question_text ILIKE {n})")
        rows = await q(f"SELECT pinned_answer_id, base_id, title, question_text, answer_md, owner_name, review_until, review_due, verified_at, source_id, status FROM mcp_pinned_answers WHERE {w.sql()} "
                       f"ORDER BY review_until NULLS LAST, pinned_answer_id LIMIT {lim(p.limit)}", *w.args)
        if q_alone(p):
            return plain(rows, "pinned_answer_id", "title", "base_id")
        return dumps(rows)

    @mcp.tool(name="pins_due", annotations={"title": "Pinned answers due for review", **RO})
    @answers
    async def pins_due(params: PinsDueIn | None = None) -> str:
        """Pinned answers whose review date has passed — the answers to read again before anyone relies on them. Oldest review date first; `owner: me` keeps only mine."""
        p = params or PinsDueIn()
        w = Where().raw("status = 'active'").raw("review_due")
        if p.base not in (None, ""):
            w.add("base_id = {}", int((await base_ref(q, p.base))["base_id"]))
        if p.owner == "me":
            w.add("owner_member_id = {}", await me(q))
        rows = await q(f"SELECT pinned_answer_id, base_id, title, question_text, answer_md, owner_name, review_until, review_due, verified_at, source_id, status FROM mcp_pinned_answers WHERE {w.sql()} "
                       f"ORDER BY review_until, pinned_answer_id LIMIT {lim(p.limit)}", *w.args)
        return dumps(rows)

    @mcp.tool(name="bases_index", annotations={"title": "Bases shared with the requesting application (share)", **RO})
    @answers
    async def bases_index(params: BasesIndexIn | None = None) -> str:
        """A shared tool (os.knowledge-bases/1), the kernel's alone: the bases shared with the requesting application — key, name, description, template, language, build state, focus areas with example questions. People-free:
        no owner, curator, member or key. A call that does not say which application asks shares nothing."""
        p = params or BasesIndexIn()
        return dumps(await bridge("share_bases", {"q": p.q} if p.q else {}, application=True))
