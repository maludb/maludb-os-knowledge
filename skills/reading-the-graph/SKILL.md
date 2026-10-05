---
name: reading-the-graph
description: How to read a base's knowledge graph — subjects, statements, aliases, clusters and communities, god nodes and surprising links — and which tool shows each, with the section behind every node. Use when explaining a subject or a path, proposing focus areas, or judging whether a base is healthy.
---

# Reading the graph

Each base has one graph in the engine, one namespace per base. You never edit it; you read it, and a curator shapes it with
guidelines and builds.

## What is in it
- **Subject** — a thing the sources talk about: a product, a rule, a party, a part. It has a type, aliases and statements.
- **Statement** — a fact about a subject or between two subjects, taken from a section ("The battery warranty is 24 months").
- **Alias** — another name for the same subject. Duplicates that should be one subject are a vocabulary problem
  (`duplicate_subjects`, runbook `merge-duplicate-subjects`).
- **Community** — a cluster of subjects that are linked more to each other than to the rest, as MaluDB computes it. MaluDB does
  not compute communities yet (M6), so `communities` usually answers empty with a note. Focus areas are proposed from the graph's most
  connected subjects and clusters (`god_nodes`, `graph_query`), and from MaluDB's communities when it computes them (runbook
  `propose-focus-areas`).
- **God node** — a subject connected to very many others. Usually the base's centre ("Warranty" in a warranty handbook); sometimes
  a vague word that swallowed everything ("company"), which is a vocabulary or exclusion problem.
- **Surprise** — a link between two parts of the graph that are otherwise apart (it needs communities, so it is usually empty today). Worth a human look: either an insight or an error.

## Which tool shows it
| To see | Tool |
|---|---|
| Size and shape: nodes, edges, communities | `graph_stats` |
| The clusters and what is in one | `communities`, `community_members` |
| One subject: type, aliases, statements | `get_subject`, `subject_statements` |
| What it connects to | `subject_neighbors` |
| How two things are related | `graph_path` |
| The most connected subjects; unexpected links | `god_nodes`, `surprises` |
| A subgraph for a question | `graph_query` |
| The sections behind a subject, a section or a source | `sections_for_subject`, `section_graph`, `source_graph` |
| Possible duplicates | `duplicate_subjects` |
| Vocabulary terms the graph never met | `unused_terms` |

## Reading it honestly
1. **A graph node is not a citation.** Before you state a fact from the graph, open the section behind it
   (`sections_for_subject`, `get_section`) and cite that.
2. **Absence is information, not proof.** A subject missing from the graph may be missing from the sources, excluded by a guideline,
   or stale (sections built under an older guideline version). Check `find_sources` and `guideline_versions` before saying the
   base lacks it.
3. **Counts move with builds.** `graph_stats` is cached from the engine after a build; say when it was last built (`get_base`).
4. **A very large cluster, a god node with a vague name, or many one-edge subjects** are signs the guidelines need work, not that
   the business is simple.
5. **Explain a path in words and cite each hop's section.** "A relates to B because section S1 says …; B relates to C because S2
   says …".

## A healthy base looks like
Several clusters of moderate size around the most connected subjects (communities, once MaluDB computes them), each nameable in two
or three words; god nodes that are real centres; few duplicates; every
vocabulary term met; no source `no_text` or failed. When it does not, report it as a proposal; do not change anything yourself
without a curator's word.
