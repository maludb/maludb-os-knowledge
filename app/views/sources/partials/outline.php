<?php /** The outline of a source, nested (source-view). Data: nodes (source_outline), curate, depth? */
$depth = $depth ?? 0; ?>
<ul class="kn-outline<?= $depth === 0 ? ' list-unstyled mb-0' : '' ?>"<?= $depth === 0 ? ' id="source-outline"' : '' ?>>
    <?php foreach ($nodes as $n): $nid = (int) $n['section_id']; ?>
        <li id="outline-node-<?= $nid ?>">
            <div class="d-flex align-items-center gap-2 py-1 flex-wrap">
                <?= hx_link('/sections/' . $nid, e($n['heading'] !== '' ? $n['heading'] : ($n['heading_path'] !== '' ? $n['heading_path'] : 'Part ' . ((int) $n['pos'] + 1))), 'fw-semibold kn-outline-link', 'id="outline-node-' . $nid . '-link"') ?>
                <?php if ($n['page_no'] !== null): ?><span class="badge bg-light text-dark border">p. <?= (int) $n['page_no'] ?></span><?php endif; ?>
                <span class="fs-11 text-muted"><?= number_format((int) $n['char_count']) ?> characters</span>
                <?php if ($curate): ?><?= chip(section_status_chip((string) $n['status'])) ?><?php endif; ?>
            </div>
            <?php if ($n['children'] !== []): ?><?= view('sources/partials/outline.php', ['nodes' => $n['children'], 'curate' => $curate, 'depth' => $depth + 1]) ?><?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
