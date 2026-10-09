<?php
declare(strict_types=1);
/**
 * Action `source_add` (sources.add on a curated base — the owner, or a curate share with the right; free for an agent, which may add
 * text or an address: a file cannot travel over the Actions MCP). One handler for the three kinds:
 *   file  — `files[]`, at most 20 a request, each on its own: one refused file never stops the others ("3 added, 1 refused"; 200 when one
 *           was added, 422 fields.files when none); JSON answers `results` — a line per file — and `records`;
 *   text  — `title` (1–300), `text` (1 character to 2 MB);
 *   url   — `url` (public, resolving, not already in the base), `title` optional; refused when fetching is off.
 * Each new source logs `source.add` (kind, title, bytes or characters, mime type, the origin of a file or an address — never text). The
 * worker reads and cuts it within the minute. Location /sources/{id} for one source, /bases/{id}/sources for many.
 */
require_once dirname(__DIR__, 2) . '/app/features/sources/handler.php';
require_once APP_ROOT . '/app/knowledge/fetch.php';
// A POST over post_max_size arrives empty — its CSRF token too: say so in words before anything else refuses it.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $_POST === [] && $_FILES === []) {
    refuse(422, 'The upload was larger than the server accepts (100 MB a request).');
}
kn_handler_begin();
$pdo = db();
$b = base_from_request($pdo);
$bid = (int) $b['base_id'];
require_base_right($bid, 'sources.add');
$me = (int) current_member_id();
$kind = (string) (req_val('kind') ?? '');
if ($kind === '' && !empty($_FILES['files'])) {
    $kind = 'file';
}
if (!in_array($kind, ['file', 'text', 'url'], true)) {
    kn_refuse_fields(['kind' => 'Add a file, text or an address (kind file, text or url).']);
}

if ($kind === 'file') {
    $files = [];
    $f = $_FILES['files'] ?? null;
    if (is_array($f) && is_array($f['name'] ?? null)) {
        foreach (array_keys($f['name']) as $i) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i], 'size' => $f['size'][$i] ?? 0];
        }
    } elseif (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $files[] = $f;
    }
    if ($files === []) {
        kn_refuse_fields(['files' => 'Choose at least one file.']);
    }
    if (count($files) > SOURCE_FILES_PER_REQUEST) {
        kn_refuse_fields(['files' => 'At most ' . SOURCE_FILES_PER_REQUEST . ' files at a time.']);
    }
    $results = [];
    $ids = [];
    foreach ($files as $file) {
        $name = source_clean_filename((string) $file['name']);
        try {
            $pdo->beginTransaction();
            $r = add_file_source($pdo, $bid, $file, $me);
            source_log($pdo, 'source.add', $bid, $r['source_id'], ['after' => ['source_id' => $r['source_id'], 'kind' => 'file', 'title' => $r['title'],
                'bytes' => $r['bytes'], 'mime_type' => $r['mime_type'], 'origin' => $r['title']]]);
            $pdo->commit();
            $ids[] = $r['source_id'];
            $results[] = ['name' => $name, 'ok' => true, 'sentence' => '', 'source_id' => $r['source_id']];
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $results[] = ['name' => $name, 'ok' => false, 'sentence' => $e->getMessage()];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $words = db_error_words($e);
            if (str_contains($words, 'archived base')) {
                kn_refuse_fields(['base' => kn_sentence($words)]);
            }
            throw $e;
        }
    }
    $added = count($ids);
    $refused = count($results) - $added;
    $did = $added === 1 && $refused === 0 ? 'Added ' . $results[0]['name'] : $added . ' added' . ($refused > 0 ? ', ' . $refused . ' refused' : '');
    if (!wants_json() && !is_htmx_request()) {
        $_SESSION['kn_upload_results'][$bid] = $results;                // a browser without JavaScript: the lines on the form, once
        header('Location: /sources/new?base=' . $bid . '&tab=files&done=1', true, 303);
        exit;
    }
    if ($added === 0) {
        $lines = array_map(static fn (array $r): string => $r['name'] . ': ' . $r['sentence'], $results);
        emit_action_status(false, ['errors' => $lines, 'fields' => ['files' => implode(' ', $lines)], 'results' => $results]);
        json_error('invalid', $lines[0], 422, ['errors' => $lines, 'fields' => (object) ['files' => implode(' ', $lines)], 'results' => $results]);
    }
    kn_done($did, $ids[0], $added === 1 ? '/sources/' . $ids[0] : '/bases/' . $bid . '/sources', 'sourcesChanged', ['records' => $ids, 'results' => $results, 'base_id' => $bid]);
}

$errors = [];
if ($kind === 'text') {
    $title = trim((string) (req_val('title') ?? ''));
    $text = (string) ($_POST['text'] ?? '');
    if ($title === '' || mb_strlen($title) > 300) {
        $errors['title'] = 'Give the text a title of up to 300 characters.';
    }
    if (trim($text) === '') {
        $errors['text'] = 'Paste the text to add.';
    } elseif (strlen($text) > SOURCE_TEXT_MAX_BYTES) {
        $errors['text'] = 'Pasted text is at most 2 MB — this is ' . fmt_bytes(strlen($text)) . '.';
    }
    if ($errors !== []) {
        kn_refuse_fields($errors);
    }
    $id = kn_guard_fields($pdo, static function () use ($pdo, $bid, $title, $text, $me): int {
        $pdo->beginTransaction();
        $id = add_text_source($pdo, $bid, $title, $text, $me);
        source_log($pdo, 'source.add', $bid, $id, ['after' => ['source_id' => $id, 'kind' => 'text', 'title' => $title, 'characters' => mb_strlen($text)]]);
        $pdo->commit();
        return $id;
    }, source_field_refusals());
    kn_done('Added ' . $title, $id, kn_land(return_path('/sources/' . $id), 'added'), 'sourcesChanged', ['records' => [$id], 'base_id' => $bid]);
}

// kind = url
$url = trim((string) (req_val('url') ?? ''));
$title = trim((string) (req_val('title') ?? ''));
if (!kn_fetch_enabled() || !(bool) one_value($pdo, 'SELECT url_fetch_allowed FROM kn_settings WHERE id = 1')) {
    kn_refuse_fields(['url' => 'Fetching pages is turned off.']);
}
if ($url === '') {
    $errors['url'] = 'Give the page\'s address.';
} elseif (!kn_url_is_public($url, kn_fetch_test_seam()['resolve'])) {
    $errors['url'] = 'That address is not public, or it does not resolve.';
} elseif (($dup = base_has_url($pdo, $bid, $url)) !== null) {
    $errors['url'] = 'That address is already here as "' . $dup['title'] . '".';
}
if (mb_strlen($title) > 300) {
    $errors['title'] = 'A title is up to 300 characters.';
}
if ($errors !== []) {
    kn_refuse_fields($errors);
}
$id = kn_guard_fields($pdo, static function () use ($pdo, $bid, $url, $title, $me): int {
    $pdo->beginTransaction();
    $id = add_url_source($pdo, $bid, $url, $title === '' ? null : $title, $me);
    source_log($pdo, 'source.add', $bid, $id, ['after' => ['source_id' => $id, 'kind' => 'url', 'title' => $title === '' ? source_origin_for_log($url) : $title, 'origin' => source_origin_for_log($url)]]);
    $pdo->commit();
    return $id;
}, source_field_refusals());
kn_done('Added ' . ($title !== '' ? $title : $url), $id, kn_land(return_path('/sources/' . $id), 'added'), 'sourcesChanged', ['records' => [$id], 'base_id' => $bid]);
