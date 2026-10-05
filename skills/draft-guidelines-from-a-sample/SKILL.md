---
name: draft-guidelines-from-a-sample
description: Runbook for drafting a base's guidelines from a sample of its own sections — pick sections, preview the template's extraction, write extraction, vocabulary, exclusion, answering and audience guidelines, preview again, save, estimate the build. Use when a curator adds the first documents to a base or says the extraction is poor.
kind: runbook
---

# Draft guidelines from a sample

**Input**: a base with at least a few sources. **Output**: guidelines saved (`guideline_save`, `vocabulary_save`) and a build
estimate. Guidelines shape the graph, so each save makes sections stale; do the whole job before saving the last one.

## 1. Know the base
`get_base` (template, guideline version, status), `find_guidelines` (what the template shipped), `find_sources`,
`source_outline` for two or three sources. Note the kind of corpus: manual, policy, contract, research, mixed.

## 2. Pick the sample
Five to eight sections: a typical one, a dense one, a table or list, one with abbreviations, one that is boilerplate. Read each
with `get_section`. Spread them over more than one source.

## 3. Preview what the shipped guidelines extract
`extraction_preview` on each. Write down what a person would have pulled out and the preview missed, and what it pulled out that
is noise.

## 4. Draft, one kind at a time (see `writing-guidelines`)
- `extraction`: what to pull out, with types ("a deadline is a statement").
- `vocabulary`: the terms and aliases the sample used; check `find_vocabulary` first.
- `exclusion`: headers, footers, boilerplate, anything the business does not want in the graph (personal data).
- `answering` and `audience`: how answers should read and who reads them.
One instruction each; concrete; no rule the sample does not justify.

## 5. Preview again
`extraction_preview` on the same sections with the draft in place (a preview takes the draft without saving where the screen
allows; otherwise save the first guideline and re-preview). Stop when the preview matches the person's list.

## 6. Save and estimate
`guideline_save` and `vocabulary_save` for each. Then `build_estimate` (sections, characters, against the monthly limit) and tell
the curator the number. Start the build (`build_start`) only when asked, or when the curator's standing instruction says so.

## 7. Report
What you drafted (a line per guideline), what the previews showed before and after, the estimate, and what you were unsure of.

## Do not
Delete a guideline. Write a rule from one odd section. Include a secret or a person's data in an example. Start a build over the
limit.
