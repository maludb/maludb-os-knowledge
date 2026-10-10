<?php /** A share call of the connections screen. Data: c (share_reads() row), tz */ ?>
<tr id="share-read-<?= (int) $c['activity_id'] ?>">
    <td class="text-nowrap"><?= e(format_ts($c['occurred_at'], $tz, 'M j, g:i:s A')) ?></td>
    <td><?= $c['application'] === null ? '<span class="text-muted">not named</span>' : e((string) $c['application']) ?></td>
    <td><code><?= e($c['tool']) ?></code></td>
    <td><?= $c['base_id'] !== null && $c['base_name'] !== null ? hx_link('/bases/' . (int) $c['base_id'], e((string) $c['base_name'])) : '<span class="text-muted">—</span>' ?></td>
    <td><?= chip(share_outcome_chip($c['outcome'])) ?></td>
    <td class="text-end"><?= $c['ms'] === null ? '' : (int) $c['ms'] ?></td>
</tr>
