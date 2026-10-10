<?php /** Home (screen `home`, design §9; home-admin.md). Data: s (home_summary), tz.
 * First run alone: no base visible. Otherwise on a phone the regions stack My bases · Builds running · My recent questions · Pins due · the Archivist's note · Gaps this week · the admin cards;
 * at 1280 My bases is full width and the rest sit in two columns (the left is the first three, the right the last). A region the caller has no right to is null and not drawn. */
$may = $s['may'];
?>
<?= view('shared/header.php', ['id' => 'home', 'title' => 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
<?php if ($s['first_run']): ?>
    <?= view('home/partials/first-run.php', ['may' => $may]) ?>
<?php else: ?>
    <?= view('home/partials/bases.php', ['bases' => $s['bases'], 'may' => $may, 'tz' => $tz]) ?>
    <div class="row g-3 mt-0">
        <div class="col-12 col-xl-6">
            <?php if ($s['builds'] !== null): ?><?= view('home/partials/builds.php', ['builds' => $s['builds'], 'poll' => $s['builds'] !== []]) ?><?php endif; ?>
            <?= view('home/partials/recent-questions.php', ['rows' => $s['recent_questions'], 'tz' => $tz]) ?>
            <?= view('home/partials/pins-due.php', ['rows' => $s['pins_due']]) ?>
        </div>
        <div class="col-12 col-xl-6">
            <?= view('home/partials/note.php', ['note' => $s['archivist_note'], 'tz' => $tz]) ?>
            <?php if ($s['gaps'] !== null): ?><?= view('home/partials/gaps.php', ['gaps' => $s['gaps']]) ?><?php endif; ?>
            <?php if ($s['admin'] !== null): ?><?= view('home/partials/admin.php', ['a' => $s['admin'], 'may' => $may, 'tz' => $tz]) ?><?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</div>
