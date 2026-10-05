# Question statuses and what to do with each

| Status | Meaning | What you say and do |
|---|---|---|
| `answered` | The engine composed an answer and every quote in it was verified against the evidence | Give the answer with its citations (base, section heading path, page). |
| `evidence_only` | The engine's answer route is not available; the passages themselves came back, ranked | Present the two or three best passages with their sections; say plainly that this is evidence, not a composed answer. |
| `no_evidence` | Nothing in the base was close enough to the question; no model was called | "Base X holds nothing on this." Say what you searched for; suggest the kind of source that would settle it. It is logged as a gap for the curators. |
| `unverified` | A quote could not be verified against the section it cites; the answer was withheld | Do not paraphrase from memory. Read the cited section (`get_section`); quote it yourself if it holds the answer; otherwise say the base could not confirm. |
| `refused` | The asker may not read the base, or a key was over its rate | Say you cannot answer from that base; do not name what it contains. |
| `failed` | The engine was unavailable (`engine_unavailable`) or errored | Say so; offer to try again; do not answer from your own knowledge. |

A weak answer is not improved by asking again in other words. Narrow the question (a focus area, a named subject) or say the
base holds nothing.
