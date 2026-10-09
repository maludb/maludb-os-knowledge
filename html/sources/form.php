<?php
declare(strict_types=1);
/**
 * /sources/new?base={id}&tab=files|text|url (screen `source-add`: three tabs, each a full page — Files (a drop zone; one POST per file
 * with JavaScript, one POST of up to 20 without), Text, Address (hidden when fetching is off)) and /sources/{id}/edit (screen
 * `source-edit`: title, language).
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_once APP_ROOT . '/app/knowledge/fetch.php';
require_human();
$pdo = db();
$id = request_integer('id');
if ($id !== null) {
    [$s, $b] = source_from_request($pdo, 'source-edit');
    require_base_right((int) $b['base_id'], 'sources.manage');
    if ($s['status'] === 'forgotten') {
        refuse(422, '"' . $s['title'] . '" is removed: nothing of it changes.');
    }
    base_screen_view($pdo, 'source-edit', (int) $b['base_id']);
    if (wants_json()) {
        respond_screen(['source' => present_source($s)]);
    }
    render_screen('Change ' . $s['title'], view('sources/form.php', ['s' => $s, 'b' => $b]),
        ['activeNav' => 'bases', 'screen' => 'source-edit', 'entity' => 'source', 'recordId' => (string) $s['source_id']]);
    exit;
}
$b = base_from_request($pdo, 'source-add');
$bid = (int) $b['base_id'];
require_base_right($bid, 'sources.add');
require_base_live($b);
$fetchOn = kn_fetch_enabled() && (bool) one_value($pdo, 'SELECT url_fetch_allowed FROM kn_settings WHERE id = 1');
$tab = request_string('tab', 'files');
if (!in_array($tab, ['files', 'text', 'url'], true) || ($tab === 'url' && !$fetchOn)) {
    $tab = 'files';
}
$results = null;
if (request_bool('done') && isset($_SESSION['kn_upload_results'][$bid])) {
    $results = $_SESSION['kn_upload_results'][$bid];                 // the no-JavaScript upload's result lines, shown once
    unset($_SESSION['kn_upload_results'][$bid]);
}
base_screen_view($pdo, 'source-add', $bid);
if (wants_json()) {
    respond_screen(['base' => present_base($b), 'tab' => $tab, 'fetch_allowed' => $fetchOn, 'max_file_bytes' => source_max_bytes($pdo), 'files_per_request' => SOURCE_FILES_PER_REQUEST,
                    'accept' => SOURCE_FILE_ACCEPT]);
}
render_screen('Add documents to ' . $b['name'], view('sources/form.php', ['s' => null, 'b' => $b, 'tab' => $tab, 'fetchOn' => $fetchOn, 'maxBytes' => source_max_bytes($pdo), 'results' => $results]),
    ['activeNav' => 'bases', 'screen' => 'source-add', 'entity' => 'base', 'recordId' => (string) $bid]);
