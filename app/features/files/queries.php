<?php
declare(strict_types=1);

/**
 * Attachments (db/007 `attachments`: GL db/009 canonical, record_type widened to 'source'): the one place a file enters storage and the
 * one place it is looked up. The type is judged from the BYTES (finfo), never from the name or the browser's word; PDF, Office, text,
 * Markdown, CSV, JSON and HTML are admitted; executables, scripts and SVG are refused (SVG carries script); the size is
 * ATTACHMENT_MAX_BYTES (default 50 MB, the settings' max_source_bytes is the base's own further limit, checked by the source slice).
 * Files live under storage/attachments/ (gitignored, outside the web root) and are served only through html/files.php, which asks the
 * view mcp_attachments whether the caller may see the record's base — never by path. A refusal is a DomainException with a sentence.
 */

/** extension => the MIME types finfo may answer for it (plain text families answer text/plain; OOXML and ODF answer zip on older libmagic). */
const ATTACHMENT_TYPES = [
    'pdf'      => ['application/pdf'],
    'doc'      => ['application/msword', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2'],
    'xls'      => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2'],
    'ppt'      => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2'],
    'docx'     => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'xlsx'     => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    'pptx'     => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
    'odt'      => ['application/vnd.oasis.opendocument.text', 'application/zip'],
    'ods'      => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
    'odp'      => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
    'rtf'      => ['text/rtf', 'application/rtf', 'text/plain'],
    'txt'      => ['text/plain'],
    'md'       => ['text/plain', 'text/markdown', 'text/x-markdown'],
    'markdown' => ['text/plain', 'text/markdown', 'text/x-markdown'],
    'csv'      => ['text/csv', 'text/plain', 'application/csv'],
    'json'     => ['application/json', 'text/plain'],
    'html'     => ['text/html', 'text/plain'],
    'htm'      => ['text/html', 'text/plain'],
];

/** What is never admitted whatever its name: programs, scripts, SVG and XML families that can carry script. */
const ATTACHMENT_REFUSED_MIME = ['image/svg+xml', 'application/x-dosexec', 'application/x-executable', 'application/x-pie-executable', 'application/x-sharedlib',
    'application/x-mach-binary', 'application/x-msdownload', 'application/x-sh', 'text/x-shellscript', 'application/javascript', 'text/javascript',
    'application/x-httpd-php', 'text/x-php', 'application/java-archive', 'application/x-msi', 'application/vnd.microsoft.portable-executable'];

function attachment_max_bytes(): int
{
    $v = (int) env('ATTACHMENT_MAX_BYTES', '52428800');
    return $v > 0 ? $v : 52428800;
}

/** Store one uploaded file for a record of a base ($file: a $_FILES entry). Answers the attachment id. */
function store_attachment(PDO $pdo, string $recordType, int $recordId, array $file, ?int $byMember): int
{
    if ($recordType !== 'source') {
        throw new DomainException('Files attach to sources.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException(in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'That file is too large.' : 'The upload did not arrive.');
    }
    $size = (int) ($file['size'] ?? 0);
    $max = attachment_max_bytes();
    if ($size > $max) {
        throw new DomainException('A file is at most ' . ($max >= 1048576 ? intdiv($max, 1048576) . ' MB' : intdiv($max, 1024) . ' KB') . '.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new DomainException('The upload did not arrive.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);          // the bytes, not the name
    $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'file')));
    $name = preg_replace('/[^\w.\- ()]+/u', '_', $name) ?: 'file';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $okType = isset(ATTACHMENT_TYPES[$ext]) && in_array($mime, ATTACHMENT_TYPES[$ext], true) && !in_array($mime, ATTACHMENT_REFUSED_MIME, true);
    if ($okType && in_array($ext, ['txt', 'md', 'markdown', 'csv', 'json', 'html', 'htm', 'rtf'], true)) {
        $head = (string) file_get_contents($tmp, false, null, 0, 2048);        // text that is really a program or an SVG is not text
        if (preg_match('/^\s*(<\?php|<svg\b|#!\/|MZ|\x7fELF)/i', $head) || stripos($head, '<svg') !== false) {
            $okType = false;
        }
    }
    if (!$okType) {
        throw new DomainException('That kind of file is not accepted (PDF, Word, Excel, PowerPoint, text, Markdown, CSV, JSON and HTML are).');
    }
    $sha = hash_file('sha256', $tmp);
    $st = $pdo->prepare('INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES (:rt, :rid, :n, :mt, :s, :h, :p, :by) RETURNING id');
    $st->execute(['rt' => $recordType, 'rid' => $recordId, 'n' => $name, 'mt' => $mime, 's' => $size, 'h' => $sha, 'p' => 'pending', 'by' => $byMember]);
    $id = (int) $st->fetchColumn();
    $dir = APP_ROOT . '/storage/attachments/' . $recordType . '-' . $recordId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new DomainException('The attachment store is not writable.');
    }
    $path = 'attachments/' . $recordType . '-' . $recordId . '/' . $id . '-' . substr($sha, 0, 12);
    $full = APP_ROOT . '/storage/' . $path;
    if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $full) : rename($tmp, $full))) {
        throw new DomainException('The file could not be stored.');
    }
    $pdo->prepare('UPDATE attachments SET storage_path = :p WHERE id = :id')->execute(['p' => $path, 'id' => $id]);
    return $id;
}

/** The file of an attachment the caller may see (mcp_attachments decides, by the record's base): [path, filename, mime, size] or null. */
function attachment_path(PDO $pdo, int $attachmentId): ?array
{
    $st = $pdo->prepare('SELECT a.attachment_id, a.filename, a.mime_type, a.byte_size, b.storage_path FROM mcp_attachments a JOIN attachments b ON b.id = a.attachment_id WHERE a.attachment_id = :id');
    $st->execute(['id' => $attachmentId]);
    $r = $st->fetch();
    return $r === false ? null : ['path' => APP_ROOT . '/storage/' . $r['storage_path'], 'filename' => $r['filename'], 'mime' => $r['mime_type'], 'size' => (int) $r['byte_size']];
}

/** Remove an attachment's row and its file. Authorization is the caller's (the source's curator). */
function delete_attachment(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT record_type, record_id, filename, storage_path FROM attachments WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    $pdo->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $id]);
    $full = APP_ROOT . '/storage/' . $r['storage_path'];
    if (is_file($full)) {
        @unlink($full);
    }
    return ['record_type' => $r['record_type'], 'record_id' => (int) $r['record_id'], 'filename' => $r['filename']];
}

/** "1.4 MB" for a byte count. */
function fmt_bytes(int $n): string
{
    return $n >= 1048576 ? number_format($n / 1048576, 1) . ' MB' : ($n >= 1024 ? number_format($n / 1024, 0) . ' KB' : $n . ' B');
}
