# Vocabulary terms

A vocabulary term has a canonical name, aliases, a subject type and a short description. The engine seeds a subject with the
aliases and the type before a build, so every alias lands on one node instead of several.

- **Canonical name**: the form the business uses in writing ("PostgreSQL 17").
- **Aliases**: every other form the sources use ("PG17", "Postgres 17"). Abbreviations, old names, plurals that differ.
- **Type**: a short noun the base reuses (`technology`, `party`, `benefit`, `part`, `study`). Fewer types are better; list them in
  the template's subject types before inventing one.
- **Description**: one sentence a reader needs to tell it from a neighbour.

Do not add a term the sources never use (`unused_terms` lists terms the graph never met). Two terms that look like the same
thing are merged, not duplicated (runbook `merge-duplicate-subjects`). A term that is only an alias of another is an alias.
