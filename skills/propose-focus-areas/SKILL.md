---
name: propose-focus-areas
description: Runbook for proposing focus areas after a base's first build — read the graph's most connected subjects and clusters (MaluDB's communities when it computes them), name each area with its terms and three example questions, check overlap, save them as proposed for a curator to accept. Use when a build has finished on a base with no areas, or when asked to organise a base.
kind: runbook
---

# Propose focus areas

**When**: after a base's first successful build (a dispatch wakes you), or when a curator asks. **Input**: a base.
**Output**: three to eight focus areas saved as **proposed** (`focus_area_propose`), each with a note saying why. A curator accepts
or dismisses; you do not activate them.

## 1. Check the base is ready
`get_base` (status `ready`, last built), `graph_stats` (size), `find_focus_areas` (do any exist; do not duplicate).
If the base is `building` or has no built sections, stop and say so.

## 2. Read the graph's clusters
You propose areas from the graph's most connected subjects and clusters; MaluDB's communities when it computes them (today
`communities` usually answers empty with a note — MaluDB M6). `god_nodes` for the most connected subjects; for each of the strong ones,
`subject_neighbors` and `graph_query` on its name to see the cluster around it; when `communities` does answer, use its members too
(`community_members`). Read one or two sections behind each cluster's main subjects (`sections_for_subject`, `get_section`). Name the
cluster by what the sources call it, in two or three words. Skip clusters of a handful of subjects; note them as "too small".

## 3. Draft each area
- **Name**: what a reader would look for ("Warranty", "Onboarding", "Fire alarm testing").
- **Description**: one sentence of what the area covers and what it does not.
- **Terms**: five to twelve subject names or aliases that anchor it (the cluster's central subjects).
- **Example questions**: three real questions a reader would ask, each answerable from the area (check one with `search`).

## 4. Check overlap and gaps
- Two areas sharing more than half their terms: merge the drafts.
- `unassigned_sources`: sources in no area; mention them, do not force them in.
- `empty_areas`: an area with no source is not proposed.

## 5. Save and report
`focus_area_propose` for each; then one message to the asker or the curators: the areas in a list (name, terms, example
questions, source count), the sources left out, anything odd (a god node with a vague name). Say the areas await acceptance.

## Do not
Activate or delete an area. Propose an area with no source. Invent terms the graph does not hold. Propose more than eight.
