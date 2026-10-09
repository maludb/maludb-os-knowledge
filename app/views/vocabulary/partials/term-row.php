<?php /** One term of the vocabulary table. Data: t, may */
$tid = (int) $t['term_id']; $first = trim((string) strtok((string) ($t['description'] ?? ''), "\n")); ?>
<tr id="term-row-<?= $tid ?>">
    <td class="fw-semibold"><?= e($t['canonical_name']) ?><?= $t['never_met'] ? ' ' . chip(['never met', 'warning']) : '' ?></td>
    <td class="fs-12"><?= e(implode(', ', $t['aliases_list'])) ?></td>
    <td class="fs-12 text-nowrap"><?= e((string) ($t['subject_type'] ?? '')) ?></td>
    <td class="fs-12 text-muted"><?= e(mb_substr($first, 0, 120)) ?></td>
    <td class="fs-12 text-nowrap"><?= $t['maludb_subject_id'] !== null ? chip(['seeded', 'success']) : '' ?></td>
    <td class="text-end"><?php if ($may['manage']): ?><?= hx_link('/vocabulary/' . $tid . '/edit', 'Edit', 'btn btn-light btn-sm btn-touch', 'id="term-row-' . $tid . '-edit"') ?><?php endif; ?></td>
</tr>
