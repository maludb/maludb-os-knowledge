<?php /** A region's engine error, in the module's words (never MaluDB's own). Data: region (an id suffix), code */ ?>
<div class="alert alert-warning mb-0 py-2 fs-13" role="status" id="graph-<?= e($region) ?>-error"><?= e(graph_error_words((string) $code)) ?></div>
