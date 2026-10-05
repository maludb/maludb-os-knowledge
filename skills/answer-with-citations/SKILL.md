---
name: answer-with-citations
description: Runbook for answering a person's question from a base — choose the base, ask, check the status, open the cited sections when wording matters, write the reply with base, section, page and quotes, or say plainly that the base holds nothing. Use for every question put to the command bar or by a sibling application.
kind: runbook
---

# Answer with citations

**Input**: a question and the asker. **Output**: one reply that cites, or says the base holds nothing.

## 1. Choose the base and the area
`find_bases`. One plausible base: use it. Several: ask the person which, or ask each and label each answer. None: say the asker has
no base that covers this. Check `find_focus_areas`; use the area that fits (`asking-well`).

## 2. Ask
`ask` with the question (and the focus area). Keep the question to one point; ask separately for separate points. A follow-up
reuses the conversation.

## 3. Read the status
- `answered`: continue.
- `evidence_only`: present the best passages with their sections; say it is evidence, not a composed answer (nothing to pin).
- `no_evidence`: step 5.
- `unverified`: read the cited section with `get_section`; quote it only if it holds the answer; otherwise say the base could not
  confirm.
- `refused` (the base or the area is not built yet), `failed` (the engine could not be reached): say so; do not answer from your
  own knowledge.

## 4. Write the reply
1. Start with the answer in one or two sentences.
2. Support it: short quotes where the words matter (limits, dates, clauses, steps), each followed by its citation.
3. Cite as: base name, source title, section heading path, page. Example: "Fleet Manual › Brakes › Inspection interval, p. 14".
4. Say **which base answered** and the focus area if one was used.
5. Where sources disagree, give both and say which is newer if the base says.
6. Add nothing the citations do not carry. No outside knowledge dressed as the base's.
7. Offer one next step if useful: a related question from `suggested_questions`, or the section to read.

## 5. When the base holds nothing
Say: "Base X holds nothing on this." Name what you searched, what kind of source would settle it, and that the question is logged
for the curators. If the asker is a curator, offer to propose the source (the Archivist's gap report will list it).

## 6. After
If the asker gave a thumbs-up and wants it kept, `answer_pin` (an `answered` question only; a curator's pin becomes a note source).
Do not pin without being asked.

## Do not
Answer without calling `ask` when a base was asked. Paraphrase a clause, a limit or a dose. Name bases the asker cannot read. Put a
key or token in a reply.
