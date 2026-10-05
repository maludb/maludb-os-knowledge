# Question statuses and what to do with each

| Status | Meaning | What you say and do |
|---|---|---|
| `answered` | The engine composed an answer and every quote in it was verified against the evidence | Give the answer with its citations (base, section heading path, page). |
| `evidence_only` | The engine's answer route is not available, or the model call failed; the passages themselves came back, ranked, with no composed answer (`answer_md` is empty) | Present the two or three best passages with their sections; say plainly that this is evidence, not a composed answer. It cannot be pinned. |
| `no_evidence` | Nothing in the base was close enough to the question; no model was called | "Base X holds nothing on this." Say what you searched for; suggest the kind of source that would settle it. It is logged as a gap for the curators. |
| `unverified` | A quote could not be verified against the section it cites; the answer was withheld | Do not paraphrase from memory. Read the cited section (`get_section`); quote it yourself if it holds the answer; otherwise say the base could not confirm. |
| `refused` | The base or the focus area is not built yet (`base_not_built`, `area_not_built`), or the engine would not take the question as sent | Say the base (or the area) is not in the graph yet and a curator's build will add it; do not answer from your own knowledge. (A base the asker may not read is not a status: it answers "not found".) |
| `failed` | The engine could not be reached (`engine_unavailable`, `engine_timeout`) or errored | Say so; offer to try again; do not answer from your own knowledge. |

A weak answer is not improved by asking again in other words. Narrow the question (a focus area, a named subject) or say the
base holds nothing.
