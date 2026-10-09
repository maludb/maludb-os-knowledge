<?php /** Who may read or curate a base, and the ways to add one (screen `base-share`). Data: b, members, departments, may (own, visibility), tabs, tz, notice */
$id = (int) $b['base_id'];
$levels = $may['own'] ? ['read', 'curate', 'own'] : ['read', 'curate'];
$here = '/bases/' . $id . '/share'; ?>
<?= view('shared/header.php', ['id' => 'base-share', 'title' => 'Share ' . $b['name'], 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Share', null]],
    'back' => back_link() ?? ['/bases/' . $id, $b['name']]]) ?>
<div class="main-content" id="base-share-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'share']) ?>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Who may read or curate</h5></div>
        <div class="table-responsive" id="base-members-scroll">
            <table class="table table-hover mb-0" id="base-members-table">
                <thead><tr><th>Who</th><th>Level</th><th>Granted by</th><th>Since</th><th class="text-end"><span class="visually-hidden">Remove</span></th></tr></thead>
                <tbody>
                    <tr id="member-row-owner"><td class="text-nowrap"><span class="fw-semibold"><?= e((string) $b['owner_name']) ?></span> <?= chip(['owner', 'primary']) ?></td><td>own</td><td></td><td class="fs-12 text-muted text-nowrap"><?= e(format_ts($b['created_at'], $tz, 'M j, Y')) ?></td><td class="text-end fs-11 text-muted">the owner</td></tr>
                    <?php foreach ($members as $m): ?><?= view('bases/partials/member-row.php', ['m' => $m, 'base_id' => $id, 'levels' => $levels, 'tz' => $tz]) ?><?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($b['archived_at'] === null): ?>
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <form method="post" action="/bases/members/add.php" hx-post="/bases/members/add.php" hx-target="#flash" class="card h-100 mb-0" id="share-form-member"><div class="card-body">
                <h6 class="fw-bold mb-1">A person or an agent</h6>
                <div class="fs-12 text-muted mb-2">Someone inside the business. Curate lets them add sources, guidelines and areas.</div>
                <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <label class="form-label fs-12 text-muted" id="share-form-member-who">Who</label>
                <div class="mb-2"><?= picker_field(['id' => 'share-form-member-field', 'name' => 'member', 'source' => 'person', 'labelledby' => 'share-form-member-who', 'title' => 'A person or an agent', 'placeholder' => 'Choose a person or an agent']) ?></div>
                <label class="form-label fs-12 text-muted" for="share-form-member-level">Level</label>
                <select name="level" id="share-form-member-level" class="form-select btn-touch mb-2"><?php foreach ($levels as $l): ?><option value="<?= e($l) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
                <button type="submit" class="btn btn-primary btn-touch w-100" id="share-form-member-btn">Share</button>
            </div></form>
        </div>
        <div class="col-12 col-lg-6">
            <form method="post" action="/bases/members/add.php" hx-post="/bases/members/add.php" hx-target="#flash" class="card h-100 mb-0" id="share-form-department"><div class="card-body">
                <h6 class="fw-bold mb-1">A department</h6>
                <div class="fs-12 text-muted mb-2">Everyone in it, as people join and leave.</div>
                <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <label class="form-label fs-12 text-muted" for="share-form-department-field">Department</label>
                <select name="department" id="share-form-department-field" class="form-select btn-touch mb-2" required><option value="">Choose a department</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select>
                <label class="form-label fs-12 text-muted" for="share-form-department-level">Level</label>
                <select name="level" id="share-form-department-level" class="form-select btn-touch mb-2"><option value="read">read</option><option value="curate">curate</option></select>
                <button type="submit" class="btn btn-primary btn-touch w-100" id="share-form-department-btn">Share</button>
            </div></form>
        </div>
        <div class="col-12 col-lg-6">
            <form method="post" action="/bases/members/add-guest.php" hx-post="/bases/members/add-guest.php" hx-target="#flash" class="card h-100 mb-0" id="share-form-guest"><div class="card-body">
                <h6 class="fw-bold mb-1">A guest</h6>
                <div class="fs-12 text-muted mb-2">Someone outside the business. A guest sees only the bases named to them, and only reads.</div>
                <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <label class="form-label fs-12 text-muted" id="share-form-guest-who">Guest</label>
                <div class="mb-2"><?= picker_field(['id' => 'share-form-guest-field', 'name' => 'guest', 'source' => 'guest', 'labelledby' => 'share-form-guest-who', 'title' => 'A guest', 'placeholder' => 'Choose a guest']) ?></div>
                <button type="submit" class="btn btn-primary btn-touch w-100" id="share-form-guest-btn">Share to read</button>
            </div></form>
        </div>
        <div class="col-12 col-lg-6">
            <form method="post" action="/bases/members/add-application.php" hx-post="/bases/members/add-application.php" hx-target="#flash" class="card h-100 mb-0" id="share-form-application"><div class="card-body">
                <h6 class="fw-bold mb-1">An application</h6>
                <div class="fs-12 text-muted mb-2">Another application of the business asks this base. The kernel must also approve a connection from that application.</div>
                <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <label class="form-label fs-12 text-muted" for="share-form-application-field">Its catalog key</label>
                <input type="text" name="application" id="share-form-application-field" class="form-control btn-touch mb-2" list="share-form-application-known" pattern="[a-z0-9_]{1,60}" maxlength="60" required autocapitalize="none" spellcheck="false" placeholder="helpdesk">
                <datalist id="share-form-application-known"><?php foreach (['helpdesk', 'projects', 'spaces', 'consultant_tracking'] as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
                <button type="submit" class="btn btn-primary btn-touch w-100" id="share-form-application-btn">Share to read</button>
            </div></form>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($may['visibility']): ?>
    <form method="post" action="/bases/visibility.php" hx-post="/bases/visibility.php" hx-target="#flash" class="card mt-3" id="visibility-form"><div class="card-body">
        <h6 class="fw-bold mb-2">Visibility</h6>
        <?= csrf_field() ?><input type="hidden" name="base" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <?php foreach (BASE_VISIBILITIES as $v => $words): ?>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="visibility-form-<?= e($v) ?>"><input type="radio" class="form-check-input mt-0" name="visibility" value="<?= e($v) ?>" id="visibility-form-<?= e($v) ?>" <?= $b['visibility'] === $v ? 'checked' : '' ?>><span class="fs-12"><?= e($words) ?></span></label>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-outline-primary btn-touch w-100" id="visibility-form-btn">Set visibility</button>
    </div></form>
    <?php endif; ?>
</div>
