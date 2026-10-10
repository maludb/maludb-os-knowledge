<?php /** One build of the table (builds). Data: x, tz */
$xid = (int) $x['build_id'];
$secs = $x['started_at'] !== null ? (int) ((strtotime((string) ($x['finished_at'] ?? 'now')) ?: time()) - (strtotime((string) $x['started_at']) ?: time())) : null; ?>
<tr id="build-row-<?= $xid ?>">
    <td class="text-nowrap fs-12"><?= hx_link('/builds/' . $xid, e(format_ts($x['requested_at'], $tz, 'M j, g:i A'))) ?></td>
    <td class="fs-12 text-nowrap"><?= e(str_replace('_', ' ', $x['kind'])) ?><?= $x['started_by'] === null ? ' ' . chip(['automatic', 'light']) : '' ?></td>
    <td class="fs-12 text-nowrap"><?= e((string) ($x['started_by_name'] ?? '—')) ?></td>
    <td class="text-nowrap"><?= chip(build_status_chip($x['status'])) ?></td>
    <td class="fs-12 text-nowrap"><?= (int) $x['items_done'] ?> · <?= (int) $x['items_failed'] ?> · <?= (int) $x['items_total'] ?></td>
    <td class="fs-12 text-nowrap"><?= number_format((int) $x['chars_processed']) ?></td>
    <td class="fs-12 text-nowrap"><?= e(build_duration($secs)) ?></td>
    <td class="fs-12"><?= e(build_error_words($x['error'], $x['error'] !== null ? max(0, (int) $x['items_total'] - (int) $x['items_done'] - (int) $x['items_failed']) : null)) ?></td>
</tr>
