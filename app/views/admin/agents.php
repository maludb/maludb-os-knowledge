<?php /** The agents (screen `admin-agents`; agents.settings). Data: declared (declared_agents), members (agents_here), s (settings: expert_member_id, archivist_member_id), note (last_monday_note|null), tz, hr (the OS's Agent HR url|null), notice */
$expert = isset($s['expert_member_id']) ? (int) $s['expert_member_id'] : 0; $archivist = isset($s['archivist_member_id']) ? (int) $s['archivist_member_id'] : 0;
$named = static function (int $id) use ($members): string { foreach ($members as $m) { if ((int) $m['member_id'] === $id) { return (string) $m['display_name']; } } return ''; }; ?>
<?= view('shared/header.php', ['id' => 'admin-agents', 'title' => 'Agents', 'crumbs' => [['Home', '/'], ['Admin', '/admin/'], ['Agents', null]], 'back' => back_link() ?? ['/admin/', 'Admin']]) ?>
<div class="main-content" id="admin-agents-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-3" id="admin-agents-hr">Hiring an agent, granting it tools and setting its duties are done in the operating system<?php if ($hr !== null): ?> — <a href="<?= e($hr) ?>" id="admin-agents-hr-link">Agent HR</a><?php endif; ?>. Here you say which agent is which.</div>
    <div class="row g-3">
        <div class="col-12 col-xl-6">
            <h6 class="mb-2">The agents Knowledge ships</h6>
            <?php foreach ($declared as $a): ?><?= view('admin/partials/agent-card.php', ['a' => $a, 'who' => $a['key'] === 'expert' ? $named($expert) : ($a['key'] === 'archivist' ? $named($archivist) : ''), 'note' => $a['key'] === 'archivist' ? $note : null, 'tz' => $tz]) ?><?php endforeach; ?>
        </div>
        <div class="col-12 col-xl-6">
            <form method="post" action="/admin/agents.php" hx-post="/admin/agents.php" hx-target="#flash" class="card mb-3" id="agents-form">
                <div class="card-header"><h5 class="card-title mb-0">Who is who</h5></div>
                <div class="card-body">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label fs-12 text-muted" for="agents-field-expert">The expert</label>
                        <select name="expert" id="agents-field-expert" class="form-select btn-touch"><option value="">No expert named</option>
                            <?php foreach ($members as $m): ?><option value="<?= (int) $m['member_id'] ?>" <?= (int) $m['member_id'] === $expert ? 'selected' : '' ?>><?= e((string) $m['display_name']) ?></option><?php endforeach; ?></select>
                        <div class="fs-11 text-muted mt-1">Asked when an answer needs more than the passages, and woken after a first build.</div></div>
                    <div class="mb-3"><label class="form-label fs-12 text-muted" for="agents-field-archivist">The Archivist</label>
                        <select name="archivist" id="agents-field-archivist" class="form-select btn-touch"><option value="">No Archivist named</option>
                            <?php foreach ($members as $m): ?><option value="<?= (int) $m['member_id'] ?>" <?= (int) $m['member_id'] === $archivist ? 'selected' : '' ?>><?= e((string) $m['display_name']) ?></option><?php endforeach; ?></select>
                        <div class="fs-11 text-muted mt-1">Proposes on Mondays and writes the curators' note.</div></div>
                    <button type="submit" class="btn btn-primary btn-touch w-100" id="agents-save-btn">Save</button>
                </div>
            </form>
            <h6 class="mb-2">Agent members here</h6>
            <?php if ($members === []): ?><div class="card"><div class="card-body text-muted fs-12" id="agents-members-none">No agent has been admitted to Knowledge yet. Hire one in the operating system.</div></div><?php endif; ?>
            <?php foreach ($members as $m): $mid = (int) $m['member_id']; ?>
                <div class="card mb-2" id="agent-member-<?= $mid ?>"><div class="card-body p-3">
                    <div class="d-flex justify-content-between gap-2 mb-1"><span class="fw-semibold"><i class="feather-cpu me-1"></i><?= e((string) $m['display_name']) ?></span>
                        <span class="text-nowrap"><?= $mid === $expert ? chip(['expert', 'primary'], 'agent-member-' . $mid . '-expert') . ' ' : '' ?><?= $mid === $archivist ? chip(['Archivist', 'info'], 'agent-member-' . $mid . '-archivist') : '' ?></span></div>
                    <div class="fs-12 text-muted">Roles <?= $m['roles'] === [] ? 'none yet' : e(implode(', ', $m['roles'])) ?> · <?= e((string) $m['status']) ?> · last active <?= $m['last_active'] !== null ? e(format_ts($m['last_active'], $tz, 'M j, g:i A')) : 'never' ?></div>
                    <div class="fs-12 mt-1" id="agent-member-<?= $mid ?>-dispatches">
                    <?php if ($m['dispatches'] === []): ?><span class="text-muted">No dispatch yet.</span>
                    <?php else: foreach ($m['dispatches'] as $status => $n): ?><?= hx_link('/admin/dispatches?status=' . rawurlencode((string) $status), (int) $n . ' ' . e(str_replace('_', ' ', (string) $status)), 'badge bg-soft-secondary text-dark me-1', 'id="agent-member-' . $mid . '-dispatch-' . e((string) $status) . '"') ?><?php endforeach; endif; ?>
                    </div>
                </div></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
