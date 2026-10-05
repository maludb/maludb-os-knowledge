---
name: asking-well
description: How to ask a knowledge base and read what comes back — local questions (one passage answers) versus global questions (the whole base), scoping to a focus area, reading evidence and citations, and when to say the base holds nothing. Use before calling ask or search, and when an answer looks thin.
---

# Asking well

## Local and global questions
- A **local** question is answered by one or a few passages: "How long is the battery warranty?", "Who owns the vendor list?",
  "What is the notice period in the Acme contract?". Use `ask`. Name the thing; keep one question to one point.
- A **global** question needs the shape of the whole base: "What are the main themes?", "Which policies conflict?", "What do we
  know about returns across all documents?". Do not expect one passage to answer it. Start from the graph: `graph_stats`,
  `communities`, `community_members`, `god_nodes`; then `ask` per community or per subject, and combine the cited answers
  yourself, citing each.
- A **relationship** question ("how is X related to Y?") is `graph_path` and `subject_neighbors`, then `ask` to check the sections.
- A **list** question ("every deadline in the handbook") is `search` plus `sections_for_subject`; say how many passages you read.

## Scope before you ask
1. Which base? `find_bases`. If more than one could hold the answer, ask the person which, or ask each and say which answered.
2. Which area? `find_focus_areas`. A question scoped to a focus area is answered from that area's subjects: narrower and cheaper.
3. As the asker, not as yourself: you only see what the asker may read.

## Reading what comes back
- **Citations** carry the section heading path, the source and the page. Open the section (`get_section`) when the wording
  matters, and quote it.
- **Evidence** is the passages the engine retrieved, with a similarity. A high-similarity passage that does not contain the answer
  is not an answer. Two weak passages that agree are still weak: say so.
- **Confidence** is the engine's; do not raise it. Where sources disagree, say they disagree and cite both.
- **Status**: `answered`, `evidence_only`, `no_evidence`, `unverified`, `refused`, `failed` — see the basics skill.

## When to say the base holds nothing
Say "Base X holds nothing on this" when `ask` returns `no_evidence`, or when the evidence is about a neighbouring thing and not
this one. Then:
1. say what you searched (the base, the area, the words);
2. say what kind of source would settle it ("a returns policy");
3. never fill the gap from your own knowledge or from another base unless the asker named it;
4. the question is logged as a gap for the curators; tell the asker that.

Do not rephrase repeatedly hoping for an answer. One rephrase with the right term (check `find_vocabulary` for the business's word)
is fair; more is fishing.

## Follow-ups
Keep the conversation: a follow-up that says "and for the Chicago site?" is answered with the same base and the last turns as
context. When the follow-up changes the subject, start a new question.

See also `answer-with-citations` for the format of the reply.
