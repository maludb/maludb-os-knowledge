<?php /** The statements about a subject, by verb. Data: id (base), r (graph_subject), here */ ?>
<div class="card mb-3" id="subject-statements"><div class="card-body">
        <h6 class="mb-2">Statements</h6>
        <?php if ($r['statements'] === []): ?>
            <div class="text-muted fs-13" id="subject-statements-empty">No statements about this subject are tied to a section of this base.</div>
        <?php else: ?>
            <?php foreach ($r['statements'] as $g): ?>
                <div class="mb-2"><div class="fs-12 text-muted mb-1"><?= e($g['verb'] ?? 'Statements') ?></div>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($g['statements'] as $x): ?>
                            <li class="d-flex flex-wrap align-items-center gap-2 py-1"><span class="text-break fs-13"><?= e($x['text']) ?></span>
                                <?php if (!empty($x['section'])): ?><?= hx_link(with_back('/sections/' . (int) $x['section']['section_id'], $here), '<i class="feather-file-text me-1"></i>' . e(graph_section_node($x['section'])['name']), 'fs-12 kn-row-link') ?><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div></div>
