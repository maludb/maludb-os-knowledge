<?php
declare(strict_types=1);
/**
 * /sources/{id}/replace — GET: screen `source-replace` (one file, text or address — "the old version's sections are forgotten and the new
 * one is read and cut afresh; its areas are kept"). POST: action `source_replace` (sources.manage; free for an agent — nothing is lost):
 * one transaction — the new source, its areas copied, the old one superseded (the trigger forgets it and its sections) and its content
 * wiped; the old file deleted after the commit. Logged `source.replace`. Location /sources/{new}.
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_once APP_ROOT . '/app/knowledge/fetch.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_human();
    [$s, $b] = source_from_request($pdo, 'source-replace');
    require_base_right((int) $b['base_id'], 'sources.manage');
    require_base_live($b);
    if ($s['status'] === 'forgotten') {
        refuse(422, '"' . $s['title'] . '" is removed already: add the new version instead.');
    }
    if ($s['kind'] === 'feed') {
        refuse(422, 'A fed source is replaced by its feed.');
    }
    $fetchOn = kn_fetch_enabled() && (bool) one_value($pdo, 'SELECT url_fetch_allowed FROM kn_settings WHERE id = 1');
    $tab = request_string('tab', $s['kind'] === 'url' ? 'url' : ($s['kind'] === 'text' ? 'text' : 'files'));
    if (!in_array($tab, ['files', 'text', 'url'], true) || ($tab === 'url' && !$fetchOn)) {
        $tab = 'files';
    }
    base_screen_view($pdo, 'source-replace', (int) $b['base_id'], ['source_id' => (int) $s['source_id']]);
    if (wants_json()) {
        respond_screen(['source' => present_source($s), 'tab' => $tab, 'fetch_allowed' => $fetchOn]);
    }
    render_screen('Replace ' . $s['title'], view('sources/replace.php', ['s' => $s, 'b' => $b, 'tab' => $tab, 'fetchOn' => $fetchOn, 'maxBytes' => source_max_bytes($pdo)]),
        ['activeNav' => 'bases', 'screen' => 'source-replace', 'entity' => 'source', 'recordId' => (string) $s['source_id']]);
    exit;
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $_POST === [] && $_FILES === []) {
    refuse(422, 'The upload was larger than the server accepts (100 MB a request).');
}
kn_handler_begin();
[$s, $b] = source_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'sources.manage');
$me = (int) current_member_id();
$kind = (string) (req_val('kind') ?? '');
if ($kind === '' && !empty($_FILES['files'])) {
    $kind = 'file';
}
$input = ['kind' => $kind, 'title' => trim((string) (req_val('title') ?? ''))];
$errors = [];
if ($kind === 'file') {
    $f = $_FILES['files'] ?? ($_FILES['file'] ?? null);
    if (is_array($f) && is_array($f['name'] ?? null)) {
        $f = count($f['name']) === 1 ? ['name' => $f['name'][0], 'type' => $f['type'][0] ?? '', 'tmp_name' => $f['tmp_name'][0] ?? '', 'error' => $f['error'][0], 'size' => $f['size'][0] ?? 0] : null;
    }
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors['files'] = 'Choose one file — the new version.';
    }
    $input['file'] = $f;
} elseif ($kind === 'text') {
    $input['text'] = (string) ($_POST['text'] ?? '');
    if (trim($input['text']) === '') {
        $errors['text'] = 'Paste the new text.';
    } elseif (strlen($input['text']) > SOURCE_TEXT_MAX_BYTES) {
        $errors['text'] = 'Pasted text is at most 2 MB.';
    }
} elseif ($kind === 'url') {
    $input['url'] = trim((string) (req_val('url') ?? ''));
    if (!kn_fetch_enabled() || !(bool) one_value($pdo, 'SELECT url_fetch_allowed FROM kn_settings WHERE id = 1')) {
        $errors['url'] = 'Fetching pages is turned off.';
    } elseif (!kn_url_is_public($input['url'], kn_fetch_test_seam()['resolve'])) {
        $errors['url'] = 'That address is not public, or it does not resolve.';
    }
} else {
    $errors['kind'] = 'Replace it with a file, text or an address (kind file, text or url).';
}
if (mb_strlen($input['title']) > 300) {
    $errors['title'] = 'A title is up to 300 characters.';
}
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$sid = (int) $s['source_id'];
$r = kn_guard_fields($pdo, static function () use ($pdo, $sid, $bid, $input, $me, $kind): array {
    $pdo->beginTransaction();
    $r = replace_source($pdo, $sid, $input, $me);
    source_log($pdo, 'source.replace', $bid, $sid, ['after' => ['old_source_id' => $sid, 'new_source_id' => $r['new_id'], 'kind' => $kind, 'sections_forgotten' => $r['sections_forgotten']]]);
    $pdo->commit();
    return $r;
}, source_field_refusals());
delete_files_after_commit([$r['old_file']]);
kn_done('Replaced ' . $s['title'], $r['new_id'], kn_land('/sources/' . $r['new_id'], 'replaced'), 'sourcesChanged', ['old_source_id' => $sid, 'base_id' => $bid]);
