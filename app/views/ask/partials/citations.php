<?php /** The citation chips (answered) or the passages as quote cards (evidence_only). A chip opens the section at its quote, with a way back. Data: q, qid, citations (mcp_citations rows), here */
if ($citations === []) { return; }
$status = (string) $q['status'];
$href = static fn (array $c): string => ask_cite_url((int) $c['section_id'], (string) $c['quote'], false) . '&back=' . rawurlencode($here . '#q-' . $qid);
$chip = static fn (array $c): string => '<a class="kn-cite' . ($c['verified'] ? ' kn-cite-verified' : '') . '" id="cite-' . $qid . '-' . (int) $c['rank'] . '" href="' . e($href($c)) . '" hx-boost="false">'
    . ($c['verified'] ? '<i class="feather-check me-1"></i>' : '') . e(cite_chip_words((int) $c['rank'], (string) $c['heading_path'], $c['page_no'] !== null ? (int) $c['page_no'] : null)) . '</a>'; ?>
<?php if ($status === 'evidence_only'): ?>
    <div class="d-flex flex-column gap-2 mb-2" id="passages-<?= $qid ?>">
        <?php foreach ($citations as $c): ?>
            <figure class="kn-passage m-0"><blockquote class="mb-1 fs-13"><?= nl2br(e((string) $c['quote'])) ?></blockquote><figcaption><?= $chip($c) ?></figcaption></figure>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="d-flex flex-wrap gap-2 mt-2 mb-2" id="citations-<?= $qid ?>"><?php foreach ($citations as $c): ?><?= $chip($c) ?><?php endforeach; ?></div>
<?php endif; ?>
