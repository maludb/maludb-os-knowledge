<?php /** Show the evidence: every passage the engine held, with its section (a Reader may see them all — design §3). Data: qid, evidence (stored items), open, here */ ?>
<details class="kn-evidence mt-2" id="evidence-<?= $qid ?>" <?= $open ? 'open' : '' ?>>
    <summary class="btn-touch fs-12 fw-semibold d-flex align-items-center">Show the evidence (<?= count($evidence) ?>)</summary>
    <ol class="list-unstyled m-0 mt-2 d-flex flex-column gap-2">
        <?php foreach ($evidence as $e): $text = (string) ($e['text'] ?? ''); $sim = max(0, min(1, (float) ($e['similarity'] ?? 0))); ?>
            <li class="kn-evidence-item" id="evidence-<?= $qid ?>-<?= (int) $e['n'] ?>">
                <div class="fs-13"><?= nl2br(e(mb_substr($text, 0, 600))) ?><?php if (mb_strlen($text) > 600 && isset($e['section_id'])): ?> <a href="<?= e('/sections/' . (int) $e['section_id'] . '?back=' . rawurlencode($here . '#q-' . $qid)) ?>" hx-boost="false">more</a><?php endif; ?></div>
                <div class="d-flex align-items-center gap-2 fs-11 text-muted mt-1">
                    <span class="kn-sim" title="similarity <?= number_format($sim, 2) ?>"><span style="width: <?= (int) round($sim * 100) ?>%"></span></span>
                    <?php if (isset($e['section_id'])): ?>
                        <a href="<?= e('/sections/' . (int) $e['section_id'] . '?back=' . rawurlencode($here . '#q-' . $qid)) ?>" hx-boost="false" class="text-truncate"><?= e((string) ($e['heading_path'] ?? '')) ?></a>
                        <span class="text-truncate"><?= e((string) ($e['source_title'] ?? '')) ?><?= isset($e['page_no']) && $e['page_no'] !== null ? ' · p. ' . (int) $e['page_no'] : '' ?></span>
                    <?php else: ?><span>This passage has no section.</span><?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
</details>
