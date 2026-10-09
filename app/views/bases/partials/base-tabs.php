<?php /** The base's tabs (BASE_TABS, only the built ones the level sees). A row under the header at 1280; a pill row that scrolls inside the page at 375. Data: tabs, active */ ?>
<nav class="kn-base-tabs mb-3" id="base-tabs" aria-label="This knowledge base">
    <ul class="nav nav-pills flex-nowrap">
        <?php foreach ($tabs as $key => [$label, $url]): ?>
            <li class="nav-item"><?= hx_link($url, e($label), 'nav-link btn-touch' . ($key === $active ? ' active' : ''), 'id="base-tab-' . e($key) . '"' . ($key === $active ? ' aria-current="page"' : '')) ?></li>
        <?php endforeach; ?>
    </ul>
</nav>
