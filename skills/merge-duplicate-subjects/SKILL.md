---
name: merge-duplicate-subjects
description: Runbook for handling subjects that look like duplicates — list the candidates, read the sections behind each, decide same or different, and propose the vocabulary term (canonical name and aliases) a curator should save. Use when duplicate_subjects returns candidates, in the Monday report, or when a curator says the graph splits one thing in two.
kind: runbook
---

# Merge duplicate subjects

**Input**: a base. **Output**: proposals for vocabulary terms (canonical name, aliases, type). A curator saves them; the next build
merges the nodes. You do not edit the graph.

## 1. List the candidates
`duplicate_subjects` for the base. Note each pair or group: names, types, statement counts. Check `find_vocabulary` first: a pair
that already has a term is a stale-build issue, not a vocabulary one (check `guideline_versions`).

## 2. Decide same or different
For each candidate:
1. `get_subject` on both: types, aliases, statements.
2. `sections_for_subject` for both; read one or two sections each (`get_section`).
3. Same thing under two names ("PG17" and "PostgreSQL 17"): merge.
   Different things with similar names ("Plan A" the insurance plan and "Plan A" the roadmap): do not merge; propose a type or a
   disambiguating alias instead.
   Unsure: say so and ask the curator; do not guess.

## 3. Pick the canonical name
The form the business uses in writing, in full ("PostgreSQL 17"). Aliases: every other form the sources use, including
abbreviations and old names. Type: the base's existing type for it (`find_vocabulary`, the template's subject types).

## 4. Propose
One `archivist_propose` of kind `merge` per base (only one may be open), with every group in its payload (`groups`: for each, the
names and the suggested canonical name, with the type), the section behind each name as evidence in the reason (heading path and
source, no long quotes), and the effect ("the next build merges N subjects, M statements"). If an open merge proposal exists, say so
in the note instead of proposing again. The curator ticks the groups to apply when accepting. If you are asked to
save it and hold the grant, `vocabulary_save`; otherwise leave it to the curator.

## 5. Report
How many candidates, how many merged, how many left different, how many need a human. Say the merge happens at the next build
and that sections built under the old version are stale until then.

## Do not
Merge on name alone. Merge subjects of unlike types without saying why. Delete a vocabulary term. Start the build unasked.
