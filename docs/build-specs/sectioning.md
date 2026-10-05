# Build spec: text extraction and sectioning (Phase 0, second half — package SECTIONER)

Built by the planning-class model against synthetic fixtures. Every source the application ever holds — an uploaded file, pasted
text, a fetched page — passes through this library once, in the worker, and comes out as (1) its **text with page anchors** and (2) a
tree of **sections**: deterministic, never a model. Each section is one future MaluDB document and **the unit of citation** (design
§6.1): its title is its heading path, its metadata names `{base, source, section, page}`, so evidence maps back to a section and a
page, an edited source re-ingests only its own sections, and removing a source forgets exactly what it taught.

The library is pure procedural PHP 8.3 (`declare(strict_types=1)`, no classes, no framework, no database). It reads the file it is
given and runs `pdftotext`; nothing else has a side effect. Slice 2 (sources and sectioning, THE FIRST EXEMPLAR) calls it from the
worker and writes `source_text`, `source_pages` and `sections` (design §6.3); nothing in this package touches a table.

## Files (exactly these)
| File | Concern |
|---|---|
| `app/knowledge/extract/extract.php` | the front door: `require_once` it and the library is loaded; `kn_extract_text()`, `kn_extract_url_text()`, `kn_extract_pasted_text()`, the result shape |
| `app/knowledge/extract/text.php` | hygiene (`kn_text_clean()`, `kn_nfc()`), the text builder every reader shares, byte ↔ character offset maps, env helpers |
| `app/knowledge/extract/detect.php` | `kn_detect_kind()` — the kind from the bytes |
| `app/knowledge/extract/plain.php` | Markdown, plain text, CSV, JSON readers |
| `app/knowledge/extract/html.php` | the HTML reader pass |
| `app/knowledge/extract/ooxml.php` | the zip guard and the DOCX, PPTX, XLSX readers |
| `app/knowledge/extract/pdf.php` | `pdftotext` under a timeout, page split, running header/footer removal |
| `app/knowledge/extract/url.php` | `kn_url_is_public()` |
| `app/knowledge/extract/section.php` | the sectioner, the engine title and metadata |
| `app/knowledge/extract/nfc_data.php` | generated Unicode tables for NFC when `intl` is absent (it is absent on this host) |
| `app/knowledge/extract/gen_nfc_data.py` | the generator of `nfc_data.php` (`python3 app/knowledge/extract/gen_nfc_data.py > app/knowledge/extract/nfc_data.php`) |
| `tests/sectioning/fixtures.php`, `tests/sectioning/run.php` | the synthetic fixtures and the proof |

## The functions and their contracts

### `kn_extract_text(string $path, string $filename, ?string $mime = null): array`
A file on disk → the **extraction**:
```
['ok' => bool, 'kind' => 'pdf|docx|pptx|xlsx|html|markdown|text|csv|json' (null when refused before the kind was known),
 'text' => string, 'pages' => [[page_no, char_from, char_to], …], 'blocks' => [['type' => 'heading|slide|sheet', 'level' => 1–9,
 'heading' => string, 'char_from' => int, 'char_to' => int], …], 'title' => ?string, 'language' => ?string, 'error' => ?string,
 'no_text' => bool]
```
- **Offsets are characters** (code points) into `text`, `char_to` exclusive — what PostgreSQL's `substr()` on `source_text.text` expects.
- `ok = false` → `error` ∈ `unreadable`, `too_large`, `executable`, `svg`, `unsupported`, `legacy_office`, `zip_bomb`, `unsafe_xml`,
  `pdf_unreadable`, `pdf_timeout`, `pdftotext_missing`; `text`, `pages`, `blocks` empty. The worker sets `sources.status = 'failed'` and
  `sources.error` to the code (the screen words it).
- `ok = true, no_text = true` → the source has no text layer (an image-only PDF, an empty file): `sources.status = 'no_text'`.
- `title`: the HTML `<title>` (else its first heading); otherwise the file name made readable ("Staff_Handbook-2026.docx" → "Staff
  Handbook 2026"). `language`: HTML `<html lang>`, DOCX `w:docDefaults` `w:lang`; otherwise null.
- `pages`: PDF pages; PPTX one per slide; XLSX one per sheet; **none** for DOCX, HTML, Markdown, text, CSV, JSON (a section's `page_no` is
  then null — a DOCX's rendered pagination is not in the file).

### `kn_extract_url_text(string $html, string $url): array`
The reader pass over a page the worker already fetched (fetching, its 10 MB / 20 s limits and its redirects are the worker's). Same
shape, kind `html`; with no `<title>` and no heading the title is `host/path`.

### `kn_extract_pasted_text(string $text, ?string $title = null): array`
Pasted text verbatim: kind `markdown` when it carries ATX or setext headings, else `text`.

### `kn_url_is_public(string $url, ?callable $resolve = null): bool`
True only when **all** hold: scheme `http`/`https`; no userinfo (no `@` in the authority); port absent or 80, 443, 8080, 8443; no
control characters, spaces or backslashes; at most 2,048 characters; the host is a DNS name with a dot that is not `localhost`,
`*.localhost`, `*.local`, `*.internal`, `*.localdomain`, `*.home.arpa`, `*.lan`, `*.intranet`, `*.corp` or a bare metadata name, or a
canonical IP literal; numeric hosts in any other spelling (`2130706433`, `0x7f000001`, `0x7f.0.0.1`, `0177.0.0.1`, `127.1`) are refused
without resolving; and **every** address the name resolves to is public. `$resolve(string $host): string[]` defaults to the system's
A + AAAA lookup; the proof passes a stub. Refused ranges — IPv4: 0/8, 10/8, 100.64/10 (CGNAT, Alibaba's metadata), 127/8, 169.254/16
(link-local, the cloud metadata address), 172.16/12, 192.0.0/24, 192.0.2/24, 192.31.196/24, 192.52.193/24, 192.88.99/24, 192.168/16,
192.175.48/24, 198.18/15, 198.51.100/24, 203.0.113/24, 224/4, 240/4; IPv6: ::/96 (with `::` and `::1`), `::ffff:0:0/96` (every
IPv4-mapped form, public or not), `::ffff:0:0:0/96`, 64:ff9b::/96 and 64:ff9b:1::/48 (NAT64), 100::/64, 2001::/23 (Teredo and the IANA
block), 2001:db8::/32, 2002::/16 (6to4), 3fff::/20, 5f00::/16, fc00::/7 (ULA, AWS's `fd00:ec2::254`), fe80::/10, fec0::/10, ff00::/8.
**The worker owes**: connect only to an address this approved (pin it — no second lookup), and run the check again on every redirect.

### `kn_section_document(array $extracted, array $opts = []): array`
An extraction → its sections **in document order**:
```
['position' => int (1..n), 'parent_index' => ?int (index into the returned list), 'level' => int (1–9),
 'kind' => 'heading|part|slide|sheet|rows', 'heading' => string, 'heading_path' => string, 'text' => string,
 'char_from' => int, 'body_from' => int, 'char_to' => int, 'page_no' => ?int]
```
`$opts`: `title` (else the extraction's title, else "Untitled"), `target_chars` (4000), `max_chars` (6000), `min_chars` (200).
An extraction with no text has no sections. `body_from` is an addition to the design's columns: `text[char_from, body_from)` is the
section's heading line; slice 2 may keep it or drop it (`sections` has `char_from`/`char_to`; the heading line is recoverable as
`heading`).

### `kn_section_title_for_engine(array $section, string $sourceTitle): string`
The MaluDB document title: the heading path (the source title when it is empty), at most 200 characters, cut at a word boundary with
an ellipsis.

### `kn_section_metadata(int $baseId, int $sourceId, int $sectionId, array $section): array`
`['base' => int, 'source' => int, 'section' => int, 'page' => ?int, 'heading_path' => string, 'position' => int]` — posted as the
document's `metadata` (design §6.2), read back by `kn_ask()` to map evidence to a section and page.

Lower-level functions (`kn_detect_kind()`, `kn_text_clean()`, `kn_nfc()`, `kn_read_*()`, `kn_zip_open()`, `kn_run()`,
`kn_pdf_headings()`, `kn_split_ranges()`, `kn_ip_is_public()`) are public for the proof and for slice 2's diagnostics, not for screens.

## The rules

### Detection (from the bytes)
1. Executables first, whatever the name: `MZ`, `\x7FELF`, `#!`, Mach-O and fat-binary magic, WebAssembly, or a finfo type among the
   executable MIME list → `executable`.
2. `%PDF-` in the first KB → `pdf`. `PK\x03\x04` → a zip: `[Content_Types].xml` plus `word/document.xml` → `docx`,
   `ppt/presentation.xml` → `pptx`, `xl/workbook.xml` → `xlsx`; any other zip → `unsupported`. OLE (`D0 CF 11 E0`) → `legacy_office`.
3. More than a stray NUL or two (UTF-16 with its BOM excepted), or an image/audio/video/font type → `unsupported`.
4. SVG by its root element (after an XML declaration, comments, a doctype) → `svg`, refused: it is active content.
5. `<!doctype html>` or a leading `<html|head|body>` → `html`; `{`/`[` that parses as JSON → `json`; a fragment with a `<script>`,
   `<iframe>`, `<object>` or `<embed>` and closing block tags → `html` (so it is stripped, never stored as text).
6. CSV by its shape (three or more lines, the same field count ≥ 2 on nine in ten, quotes honoured, `,` `\t` or `;`); Markdown by an
   ATX heading, a setext underline or a fence; only then do the name (`.csv`, `.tsv`, `.md`) and the client's MIME choose; else `text`.

### Hygiene (every piece of text, before it is placed)
UTF-16 by BOM converted; a UTF-8 BOM dropped; invalid UTF-8 read as Windows-1252; `\r\n`, `\r`, U+2028, U+2029 → `\n`; NUL, C0 (except
`\t` `\n`), DEL, C1 controls and stray U+FEFF removed; trailing blanks on each line trimmed; three or more newlines → one blank line;
**NFC** (PHP's `Normalizer` when `intl` is loaded; otherwise the generated tables — full canonical decomposition, canonical ordering,
canonical composition with the exclusions, Hangul algorithmically; cross-checked against Python's `unicodedata` on 3,000 strings).
The final text is never rewritten after offsets are taken.

### Readers
- **PDF**: `pdftotext -layout -enc UTF-8 -eol unix -q <file> -` (binary `KN_PDFTOTEXT`, default `/usr/bin/pdftotext`; arguments through
  `escapeshellarg`; `proc_open` with a `KN_PDFTOTEXT_TIMEOUT` timeout, default 60 s, killed at the timeout or when the output passes
  4 × `KN_MAX_BYTES`). Pages split on the form feeds. **Running headers and footers**: among the first and last three non-blank lines
  of each page, a line whose key (trimmed, spaces collapsed, every digit run `#`) appears on more than 60% of the pages (two pages or
  more) is dropped; the text and every offset are those **after** the removal. Pages are joined by one blank line; an image-only page
  is an empty range.
- **DOCX**: `word/styles.xml` maps style ids to levels — name `heading N` or id `HeadingN` → N, `Title` → 1, a style's
  `w:outlineLvl` → level + 1; a paragraph's own `w:outlineLvl` also counts; without `styles.xml` the ids `Heading1`–`Heading9` and
  `Title` still count. The body is walked in order (into `w:sdt`, `w:customXml`, `w:smartTag`, `w:ins`): a paragraph is its runs'
  text (`w:tab` → tab, `w:br`/`w:cr` → newline, deleted text and field instructions dropped); a table is one line per row, cells joined
  by ` | `.
- **PPTX**: slides in `p:sldIdLst` order through the presentation's relationships; the title placeholder (`title`/`ctrTitle`) is the
  slide's heading block (kind `slide`, level 1; "Slide N" when there is none); the other shapes' paragraphs and tables follow; slide
  number, date, header and footer placeholders are dropped; the notes slide's body placeholder follows as `Notes:` + its lines.
- **XLSX**: sheets in workbook order (very hidden ones skipped) through the relationships; the sheet name is the heading block (kind
  `sheet`, level 1); shared strings resolved (phonetic runs excluded), inline strings read, booleans `TRUE`/`FALSE`, numbers as stored;
  the first non-empty row is the header and every later row is one line `Header: value | Header: value` (empty cells left out, a cell
  beyond the header named `Column X`) — so any cut of a sheet still carries its column names.
- **HTML**: parsed with `LIBXML_NONET`; removed: `script`, `style`, `noscript`, `template`, frames, `object`, `embed`, `applet`, `svg`,
  `math`, `canvas`, `form` and its controls, `nav`, `footer`, `aside`, `dialog`, `menu`, media, `img`, `head`; a `header` outside
  `article`/`main`; elements with `role` navigation/banner/contentinfo/search/complementary/menu/menubar/dialog, `hidden`,
  `aria-hidden="true"`, `display:none`, `visibility:hidden`. The root is `main` (or `role="main"`), else a lone `article`, else `body`.
  `h1`–`h6` → heading blocks; block elements → paragraphs; `li` → `- item`; table rows → ` | ` lines; `pre` verbatim; `br` → newline. A
  non-UTF-8 page is converted by its `meta charset` (else Windows-1252).
- **Markdown**: the text is the source, verbatim after hygiene. ATX headings (`#`–`######`, closing `#`s stripped) and setext headings
  (a line after a blank line, underlined by `=` → 1 or `-` → 2; list items and indented code are not headings) outside fenced code
  (`` ``` `` or `~~~`, closed by the same character at least as long).
- **CSV**: the delimiter is the first line's most frequent of `,` `\t` `;` (`.tsv` prefers the tab); RFC quoting (quoted newlines stay
  in their row); each row one `Header: value | …` line; empty rows skipped.
- **JSON**: flattened depth-first to `a.b[0].c: value` lines (`null`, `true`, `false`, `(empty)` for `{}`/`[]`), one paragraph per
  top-level member.
- **Plain text**: as it is, after hygiene; no structure.

### Limits
`KN_MAX_BYTES` (52,428,800) for a file, a fetched page and pasted text. Zips: at most `KN_ZIP_MAX_ENTRIES` (5,000) entries,
`KN_ZIP_MAX_UNCOMPRESSED` (200 MB) declared in all, an inflation ratio of `KN_ZIP_MAX_RATIO` (100) overall and for any entry over 1 MB,
checked before inflating; every part read through a stream capped at `KN_ZIP_MAX_PART` (100 MB) in case the directory lies. An OOXML
part carrying `<!DOCTYPE` or `<!ENTITY` → `unsafe_xml` (OOXML never has one; it is how XXE and entity bombs arrive). External
relationships are ignored.

### Sectioning
1. **Structure first.** The blocks (DOCX styles, HTML `h1`–`h6`, Markdown headings, PPTX slides, XLSX sheets) cut the text; for a PDF
   (no blocks) `kn_pdf_headings()` finds them: after a blank line, a numbered line (`1.`, `2.3`, `2.3.1 Title`; level = the number of
   components; never ending in `. , ; :`; the word after the number capitalised), `Chapter N`/`Part N` (level 1), `Section N`/`Article
   N` (level 2); between blank lines, a short (≤ 70) ALL-CAPS line (level 1), or a short line beginning with a capital and ending
   without punctuation, at most ten words (one level under the last numbered or capitals heading). Lines over 90 characters, lines with
   column gaps (three or more spaces inside) and page-number lines are never headings. A heading never crosses a page header or footer
   because those lines are gone before sectioning.
2. **The tree.** Levels are clamped to 1–9; a heading's parent is the nearest earlier heading of a lower level. A block that overlaps the
   one before, lies outside the text or is empty is ignored. Text before the first heading is a **preamble** section (kind `part`,
   heading and path = the source title); blank space before the first heading belongs to the first heading's range.
3. **Ranges tile the text.** Section *k* spans `[char_from, char_to)`, the first starts at 0, each starts where the one before ended,
   the last ends at the text's length; `text[char_from, body_from)` is the heading line (a setext heading's includes its underline) and
   `text` is `text[body_from, char_to)` trimmed. So **headings + section texts, in order, reproduce the text modulo whitespace** — the
   proof asserts it for every fixture.
4. **Small sections merge.** A section whose text is under `min_chars` (200) **and that has no children** is merged into its
   **following sibling** (same parent, next in order) when there is one: the merged section starts where the small one did, takes its
   heading line, carries the sibling's heading line inside its text, and is headed `Small · Sibling` (a preamble adds no heading); the
   sibling's children stay its children. One forward pass, so runs of small siblings chain (`B · C · D`, capped at 200 characters).
5. **Large sections split.** A section whose text is over `max_chars` (6,000) — the preamble included — is cut into `n = ⌈length /
   target⌉` pieces of about `length / n` at paragraph boundaries, then line breaks, sentence ends (`.!?。！？`), spaces, and as a last
   resort every `target` characters; no piece over `max_chars`; a whitespace-only piece never stands alone; a trailing runt under a
   quarter of a piece joins the one before when both fit. Parts are headed `Heading (part k of n)`, kind `part` (`rows` for a sheet or
   a CSV), the same level and parent; part 1 keeps the heading line; **the original's children hang on part 1** and their paths use the
   plain heading (`Doc › Big › Child`).
6. **No structure** (plain text, CSV, JSON, a PDF without headings): the whole text is cut by rule 5's splitter with no heading lines;
   one piece is headed by the title; several are `Title (part k of n)`, kind `part` (`rows` for CSV and XLSX), level 1, no parents.
7. **Paths.** `heading_path` = the source title › each ancestor's heading › the section's heading, joined by ` › `; a preamble and a
   no-structure part have the title (or `Title (part k of n)`) alone.
8. **Pages.** `page_no` = the page holding the section's first non-blank character (the next page when it falls between pages); null
   when the source has no pages.
9. **Determinism.** The same bytes give the same extraction and the same sections, byte for byte — no clock, no randomness, no
   locale, no model.

## Fixtures (`tests/sectioning/fixtures.php` — generated at run time, never committed, no third-party text)
Prose from a fixed word list by a seeded generator. **DOCX** (`fx_docx`): `[Content_Types].xml`, package and document relationships,
`docProps/core.xml`, `word/styles.xml` defining Normal, Title, Heading1–3 (with `w:outlineLvl`) and a table style, a body with a Title,
nested headings, a short section and a table; and a variant without `styles.xml`. **PPTX** (`fx_pptx`): presentation with master,
layout, theme and notes master, slides with title, content and slide-number placeholders, notes slides. **XLSX** (`fx_xlsx`): workbook,
relationships, styles, shared strings, inline strings, numbers and booleans across two sheets (150 rows in one). **PDF** (`fx_pdf`):
written by hand as raw PDF 1.4 syntax with computed xref offsets (`pdfinfo` reads it clean): five Helvetica pages with numbered,
chapter, section, all-capitals and short-line headings, a running header on every page and a `Page N of 5` footer; and an image-only
PDF (one 8 × 8 grey image per page, no text). **HTML** with header, nav, scripts (head and inline), styles, a form, hidden and invisible
elements, an iframe, an aside and a footer; **Markdown** with ATX, closed ATX, setext headings and two fenced blocks holding `#` lines;
**CSV** with quoted commas and a quoted newline; **JSON** nested; **plain text** of 30 paragraphs of ~700 characters and no structure.

## Out of scope
Fetching a URL (the worker: public-address check, pinning, redirects, 10 MB, 20 s); OCR of image-only PDFs (Extended — tesseract is not
installed; `no_text` is the answer); legacy `.doc`/`.xls`/`.ppt`, RTF, ODF, EPUB (refused as `legacy_office` or `unsupported`); number
formats and dates in XLSX (values as stored); DOCX footnotes, comments, headers and footers; PPTX hidden slides as hidden; language
detection from the text itself; the structured-documents parsers (provisions, amendments — `docs/structured-documents-extended.md`);
any table write (slice 2).

## Built and proven
**Built and proven 2026-10-05: 250 checks green** — `php tests/sectioning/run.php` (exit 0; clean under `error_reporting = E_ALL` with
every warning turned into a failure; no database, no network).
- [x] **DOCX** (19): kind, language, Title and Heading1–3 levels, block offsets, the table as rows, paragraphs, no pages, tiling, the
  path `Staff Handbook › Leave › Carry-over`, parent links, the short Notice merged into Requests with Approvals' parent and path
  following, positions, kinds; heading style ids honoured without `styles.xml`.
- [x] **PPTX** (9): slide blocks, "Slide 3" for an untitled slide, notes, slide numbers dropped, a page per slide, tiling, slide
  sections with pages 1–3, the path.
- [x] **XLSX** (12): sheet blocks, shared strings, header on every row, booleans, inline strings, a page per sheet, tiling, the long
  sheet split into `rows` parts ≤ 6,000 each still carrying the column names, the second sheet on page 2.
- [x] **HTML** (25): detected despite a `.txt` name, title, language, eleven kinds of noise removed, heading levels, list items, table
  rows, entities, `pre`, tiling, the path; the URL reader pass, its title fallbacks, a Latin-1 page.
- [x] **Markdown** (8): detected by content, ATX + closed ATX + setext levels, fenced `#` lines not headings, the text verbatim, tiling,
  the setext path, the code block in its section, the setext range.
- [x] **CSV and JSON** (13): CSV by shape, header per row, quoted newline, rows sections ≤ 6,000 with no row cut, part names; TSV by name;
  JSON despite a `.csv` name, flattening, one paragraph per member, top-level lists.
- [x] **No structure** (9): kind text, the title from the name, tiling, parts of ~4,000 (2,500–6,000) at paragraph boundaries, kind
  `part` level 1 without parents, names and paths, the options, a short text as one section.
- [x] **Oversize** (8): a 13,000-character section in parts ≤ 6,000 with `(part k of n)`, part 1 keeping the heading line, the child on
  part 1 with the plain path; a 14,000-character line with no spaces hard-cut with no whitespace-only part; a long line of words cut
  only at spaces.
- [x] **PDF** (19): detected despite a `.txt` name, five contiguous page anchors, the running header and the page-number footer
  removed, page 2 beginning with its heading, nine headings with their levels (numbered 1–3, all-caps, chapter, short line, section),
  the numbered path, first pages `[1,1,2,2,3,3,4,4,5]`, a section running across a page break, the short-line heading under its
  chapter; image-only → `ok` + `no_text` with three empty pages and no sections; a broken PDF → `pdf_unreadable`; a missing binary →
  `pdftotext_missing`; the runner killing a process at its timeout.
- [x] **Refusals** (17): an executable named `.pdf`, ELF named `.txt`, a shell script, Mach-O named `.docx`, SVG named `.html` and behind a
  comment and doctype, an image, legacy Office, a plain zip named `.docx`, a 30 MB zip bomb of a few KB, too many entries, a DOCTYPE in
  `document.xml`, over `KN_MAX_BYTES` (file, page, paste), a missing file, a refusal's empty shape.
- [x] **URL check** (61): 60 URLs across schemes, userinfo, ports, private/loopback/link-local/CGNAT/multicast/broadcast IPv4,
  loopback/ULA/link-local/mapped/NAT64/documentation IPv6, a public v6 literal, decimal/hex/octal/short IPv4 spellings, local names,
  a name resolving to a mix, metadata addresses by name, garbage from the resolver, an unresolvable name, control characters and a
  backslash, an over-long URL; the resolver never consulted for an IP literal.
- [x] **Hygiene** (15): CRLF/CR, C0 and C1 controls, BOMs, UTF-16LE, Windows-1252, NFC (composition, canonical reordering, Hangul, a
  singleton, an exclusion, composed text untouched), U+2028, newline collapse, an extracted file free of controls.
- [x] **Determinism** (6): DOCX, PPTX, XLSX, PDF, Markdown and HTML extracted and sectioned twice — identical.
- [x] **Adversarial** (17): an empty file, whitespace only, a heading with no body, a document of only headings (tiling, merges, the
  path), 5,000 nested heading levels capped at 9 and tiling, mixed line endings, a BOM before the first heading, multibyte text
  (character offsets, CJK cut at sentence ends), a preamble, a tiny preamble merged without a heading, out-of-range blocks and level 0,
  pasted Markdown and plain text.
- [x] **Engine title and metadata** (5) and **detection with lying names** (7: PPTX named `.xlsx`, XLSX named `.docx`, DOCX named `.pdf`;
  CSV named `.md`; prose named `.csv`; an HTML fragment with a script; UTF-16 text).

Measured once (not in the suite): an 18.8 MB Markdown file with 400 headings extracts in 0.7 s and sections into 5,200 sections in
0.3 s at 83 MB peak.

**Decisions taken while building (2026-10-05):**
- `pdftotext` runs **once per file** and the pages are split on its form feeds, rather than one process per page: the text and the
  anchors are identical and a 1,000-page PDF does not start 1,000 processes. The timeout covers the whole run.
- Offsets are **characters**, not bytes, everywhere a caller sees them (they index `source_text.text` in PostgreSQL); the library works in
  bytes internally and converts once, in a single forward pass.
- Each section also carries **`body_from`** (where its own text starts after the heading line) so the tiling and the round trip are exact.
- A merged section is headed by both headings joined with ` · ` and starts at the small one's heading line (the sibling's heading line
  is part of its text); the alternative — keeping only the sibling's heading — would cite text under a heading it does not sit under.
- Rows are rendered `Header: value | Header: value`, one line per row, for both CSV and XLSX: that is how "the header row repeated per
  chunk" holds for any cut without writing characters into a section that are not in the source text.
- Formats without pages (DOCX, HTML, Markdown, text, CSV, JSON) have **no** page anchors and a null `page_no`; PPTX slides and XLSX sheets
  are their pages.
- The host has no `intl`, so NFC falls back to generated tables (`nfc_data.php`, Unicode 15.0 from Python's `unicodedata`) — PHP's
  `Normalizer` is used whenever it is loaded.
- A text file is binary (refused) only with more than a stray NUL or two (> max(2, 0.1%) of the first 64 KB); a stray NUL is removed by
  hygiene instead.
- The preamble (text before the first heading) is a section of kind `part` named by the source title; a short one merges into the first
  heading without adding a heading.

## Open questions
(none)
