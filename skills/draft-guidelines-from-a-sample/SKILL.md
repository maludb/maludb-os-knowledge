---
name: draft-guidelines-from-a-sample
description: Runbook for drafting a base's guidelines from a sample of its own sections — pick sections, preview the template's extraction, write extraction, vocabulary, exclusion, answering and audience guidelines, preview again, save, estimate the build. Use when a curator adds the first documents to a base or says the extraction is poor.
kind: runbook
---

# Draft guidelines from a sample

**Input**: a base with at least a few sources. **Output**: draft guidelines saved **inactive** (`guideline_save` with `is_active`
no — a curator activates them), the vocabulary terms you would add listed in your report, and a build estimate. An inactive draft
shapes nothing; activating an extraction, vocabulary or exclusion guideline (or saving a term) makes sections stale, which is the
curator's decision.

## 1. Know the base
`get_base` (template, guideline version, status), `find_guidelines` (what the template shipped), `find_sources`,
`source_outline` for two or three sources. Note the kind of corpus: manual, policy, contract, research, mixed.

## 2. Pick the sample
Five to eight sections: a typical one, a dense one, a table or list, one with abbreviations, one that is boilerplate. Read each
with `get_section`. Spread them over more than one source.

## 3. Preview what the shipped guidelines ask for
`extraction_preview` on each: it shows the prompt the model will be told, with the section in place (a dry run of the prompt, not of
the model — MaluDB M5). Write down what a person would pull out that the prompt does not ask for, and what it asks for that is noise.

## 4. Draft, one kind at a time (see `writing-guidelines`)
- `extraction`: what to pull out, with types ("a deadline is a statement").
- `vocabulary`: the terms and aliases the sample used; check `find_vocabulary` first.
- `exclusion`: headers, footers, boilerplate, anything the business does not want in the graph (personal data).
- `answering` and `audience`: how answers should read and who reads them.
One instruction each; concrete; no rule the sample does not justify.

## 5. Preview again
`extraction_preview` on the same sections with your draft in its `draft` parameter (nothing is saved). Stop when the prompt asks for
what is on the person's list.

## 6. Save and estimate
`guideline_save` for each draft with `is_active` no (at most one of each kind). List the vocabulary terms you would add (canonical
name, aliases, type) in the report instead of saving them: a term shapes the graph the moment it is saved. Then `build_estimate`
(sections, characters, against the monthly limit) and tell the curator the number for when they activate the drafts. Start a build
(`build_start`) only when asked.

## 7. Report
What you drafted (a line per guideline), what the previews showed before and after, the estimate, and what you were unsure of.

## Do not
Activate your own drafts. Delete a guideline. Write a rule from one odd section. Include a secret or a person's data in an example. Start a build over the
limit.
