<?php
declare(strict_types=1);
/** /bases/{id}/vocabulary-import (screen `vocabulary-import`): a CSV file or pasted CSV; Check shows the rows and writes nothing; Import writes them in one transaction. */
require_once dirname(__DIR__, 2) . '/app/features/guidelines/handler.php';
require_human();
$pdo = db();
$b = base_from_request($pdo, 'vocabulary-import');
$id = (int) $b['base_id'];
require_base_right($id, 'base.manage');
require_base_live($b);
base_screen_view($pdo, 'vocabulary-import', $id);
if (wants_json()) {
    respond_screen(['base' => present_base($b), 'columns' => ['canonical_name', 'aliases', 'subject_type', 'description'], 'max_rows' => 500, 'max_bytes' => 1048576]);
}
render_screen('Import terms', view('vocabulary/import.php', ['b' => $b, 'check' => null, 'csv' => '']), ['activeNav' => 'bases', 'screen' => 'vocabulary-import', 'entity' => 'base', 'recordId' => (string) $id]);
