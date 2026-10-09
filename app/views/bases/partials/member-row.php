<?php /** One row of who may read or curate a base (base-share). Data: m (base_members_list row), base_id, levels (the levels the caller may give), tz */
$mid = (int) $m['base_member_id'];
$kind = (string) $m['principal_kind'];
$fixed = $kind === 'everyone' || $kind === 'application' || !empty($m['is_guest']);     // the everyone row follows visibility; an application and a guest read
$field = $kind === 'department' ? 'department' : 'member'; ?>
<tr id="member-row-<?= $mid ?>">
    <td class="text-nowrap"><span class="fw-semibold"><?= e((string) $m['principal_name']) ?></span> <?= chip(principal_chip($m)) ?></td>
    <td class="text-nowrap">
        <?php if ($fixed): ?>
            <span id="member-row-<?= $mid ?>-level"><?= e((string) $m['level']) ?></span>
        <?php else: ?>
            <form method="post" action="/bases/members/add.php" hx-post="/bases/members/add.php" hx-target="#flash" class="d-flex gap-1 m-0" id="member-row-<?= $mid ?>-level-form">
                <?= csrf_field() ?><input type="hidden" name="base" value="<?= (int) $base_id ?>"><input type="hidden" name="<?= $field ?>" value="<?= (int) $m['principal_id'] ?>"><input type="hidden" name="return_to" value="/bases/<?= (int) $base_id ?>/share">
                <label class="visually-hidden" for="member-row-<?= $mid ?>-level-select">Level for <?= e((string) $m['principal_name']) ?></label>
                <select name="level" id="member-row-<?= $mid ?>-level-select" class="form-select form-select-sm btn-touch kn-level-select">
                    <?php foreach ($levels as $l): ?><option value="<?= e($l) ?>" <?= $m['level'] === $l ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                    <?php if (!in_array($m['level'], $levels, true)): ?><option value="<?= e((string) $m['level']) ?>" selected><?= e((string) $m['level']) ?></option><?php endif; ?>
                </select>
                <button type="submit" class="btn btn-light btn-sm btn-touch" id="member-row-<?= $mid ?>-change">Change level</button>
            </form>
        <?php endif; ?>
    </td>
    <td class="fs-12 text-muted text-nowrap"><?= e((string) ($m['granted_by_name'] ?? '')) ?></td>
    <td class="fs-12 text-muted text-nowrap"><?= e(format_ts($m['created_at'], $tz, 'M j, Y')) ?></td>
    <td class="text-end">
        <?php if ($kind !== 'everyone'): ?>
            <form method="post" action="/bases/members/remove.php" hx-post="/bases/members/remove.php" hx-target="#flash" hx-confirm="Remove <?= e((string) $m['principal_name']) ?> from this base?" class="m-0" id="member-row-<?= $mid ?>-remove-form">
                <?= csrf_field() ?><input type="hidden" name="base" value="<?= (int) $base_id ?>"><input type="hidden" name="base_member" value="<?= $mid ?>">
                <button type="submit" class="btn btn-light btn-sm btn-touch text-danger" id="member-row-<?= $mid ?>-remove">Remove</button>
            </form>
        <?php else: ?>
            <span class="fs-11 text-muted">follows visibility</span>
        <?php endif; ?>
    </td>
</tr>
