<?php /** One item of a build (build-view). Data: i */
$iid = (int) $i['build_item_id']; $first = trim((string) strtok((string) ($i['error'] ?? ''), "\n")); ?>
<tr id="item-row-<?= $iid ?>">
    <td class="fs-12"><?= hx_link('/sections/' . (int) $i['section_id'], e((string) ($i['heading_path'] ?? 'Section ' . (int) $i['section_id']))) ?></td>
    <td class="text-nowrap"><?= chip(build_item_chip((string) $i['status'])) ?></td>
    <td class="fs-12 text-nowrap"><?= number_format((int) $i['chars']) ?></td>
    <td class="fs-12 text-muted"><?= e(mb_substr($first, 0, 160)) ?></td>
</tr>
