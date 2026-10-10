<?php /** The base's graph (screen `graph`). Data: b, o (graph_overview), q, search, dups, curate, tabs, tz, here */
$id = (int) $b['base_id']; $err = $o['errors']; ?>
<?= view('shared/header.php', ['id' => 'graph', 'title' => 'Graph', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Graph', null]], 'back' => back_link()]) ?>
<div class="main-content" id="graph-content">
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'graph']) ?>
    <?= view('graph/partials/size.php', ['b' => $b, 'o' => $o, 'tz' => $tz]) ?>
    <?= view('graph/partials/search-results.php', ['b' => $b, 'q' => $q, 'search' => $search, 'error' => $err['search'] ?? null, 'here' => $here]) ?>
    <?= view('graph/partials/communities.php', ['b' => $b, 'o' => $o, 'here' => $here]) ?>
    <?= view('graph/partials/god-nodes.php', ['b' => $b, 'o' => $o, 'here' => $here]) ?>
    <?= view('graph/partials/surprises.php', ['b' => $b, 'o' => $o, 'here' => $here]) ?>
    <?php if ($curate): ?><?= view('graph/partials/duplicates.php', ['b' => $b, 'dups' => $dups, 'error' => $err['duplicates'] ?? null, 'here' => $here]) ?><?php endif; ?>
</div>
