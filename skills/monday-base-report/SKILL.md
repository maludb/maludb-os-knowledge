---
name: monday-base-report
description: Runbook for the Archivist's Monday duty — for each base gather the base report and the answer report, turn each finding into a proposal, write one note per base to its curators and one to the admin's assistant. Run every Monday 06:45 or when an admin asks for the report.
kind: runbook
---

# The Monday base report

**When**: every Monday 06:45 (the duty), or when an admin asks. **Who runs it**: the Knowledge Archivist.
**Writes**: proposals (`archivist_propose`), one note per base (`notification`), one message to the admin's assistant.

## 1. The bases
`find_bases`. Work base by base. Skip an empty base with one line.

## 2. The base report
- `find_sources`: status `failed`, `no_text`, or older than the base's guideline version (stale); `get_source` for the error.
- URL sources: changed or vanished since the last check (`get_source`).
- `find_feeds`: feeds whose last sync is old or whose status is not ok.
- `find_builds`, `build_progress`: builds that failed; a build stuck running.
- `base_usage`, `get_settings`: the month's text against the limit; flag at 80 percent.

## 3. The answer report
- `question_gaps`: clustered questions with no evidence; give the cluster, the count, the example.
- `questions`, `question_feedback`: thumbs-down answers with their evidence; unverified answers.
- `pinned_answers`, `pins_due`: pins past review.
- `duplicate_subjects`: subjects the vocabulary should merge (runbook `merge-duplicate-subjects`).
- `unassigned_sources`, `empty_areas`: sources in no area; areas with no source.
- `graph_stats`: a base with sources but no graph growth since the last build.

## 4. Proposals
`archivist_propose` once per finding or cluster: kind (stale source, failed source, gap, merge, review pin, unassigned, unverified), the
evidence (ids and counts, a short excerpt at most), the suggested hand ("add a source about returns"). First look at
`archivist_proposals`: do not repeat an open or dismissed proposal unless the facts changed.

## 5. The note
One note per base to its curators (`notification`): the counts, the five findings that matter most, how many proposals are new and
how many still open. A healthy base gets one line. Then one message to the admin's assistant (`message_send`) with all bases in a
table: base, sources failed or stale, gaps, open proposals.

## 6. Check yourself
Every finding names its base and its record. No source or question text beyond a short excerpt. Nothing deleted, shared, minted,
rebuilt or changed.
