<?php /** The templates, read-only (screen `templates`). Data: templates */ ?>
<?= view('shared/header.php', ['id' => 'templates', 'title' => 'Templates', 'crumbs' => [['Home', '/'], ['Settings', '/admin/settings'], ['Templates', null]],
    'back' => back_link() ?? ['/admin/settings', 'Settings']]) ?>
<div class="main-content" id="templates-content">
    <div class="fs-12 text-muted mb-3">A template is the set of guidelines a new base starts with. A base's own guidelines are changed on the base; a template ships with the application.</div>
    <div class="row g-3" id="templates-list">
        <?php foreach ($templates as $t): ?><div class="col-12 col-xl-6"><?= view('bases/partials/template-card.php', ['t' => $t, 'full' => true]) ?></div><?php endforeach; ?>
    </div>
</div>
