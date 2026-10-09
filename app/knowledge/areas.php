<?php
declare(strict_types=1);

/**
 * Focus areas as MaluDB projects (docs/build-specs/guidelines-areas.md "Focus areas"), used by the area handlers and by the worker (the
 * purge here; slice 4's build before extraction). The engine calls go through app/knowledge/engine.php only; a project is named
 * kb:<id>/<area name> (projects are tenant-wide). Base tables are read directly: authorization is the caller's.
 */
require_once __DIR__ . '/engine.php';

/** The area row with its base's namespace, or null. */
function kn_area_row(PDO $pdo, int $areaId): ?array
{
    $st = $pdo->prepare('SELECT a.*, b.namespace FROM focus_areas a JOIN knowledge_bases b ON b.id = a.base_id WHERE a.id = :a');
    $st->execute(['a' => $areaId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/**
 * Bring an active area's project in step: create it (or find it by name and update its description and classifier); a project whose name is
 * no longer the area's is deleted (links first) and created again (divergence 5); then link every seed term's subject (a missing subject is
 * seeded through the extraction path — no model call — typed by the base's term when the seed is one, else `other`) and unlink a subject
 * that is no longer a seed. ['ok', 'code', 'project_id', 'linked', 'unlinked', 'recreated', 'error'].
 */
function kn_area_engine_sync(PDO $pdo, int $areaId): array
{
    $out = ['ok' => false, 'code' => null, 'project_id' => null, 'linked' => 0, 'unlinked' => 0, 'recreated' => false, 'error' => null];
    $a = kn_area_row($pdo, $areaId);
    if ($a === null || $a['status'] !== 'active') {
        return ['code' => 'not_active', 'error' => 'Only an active area is linked in the engine.'] + $out;
    }
    $ns = (string) $a['namespace'];
    $want = kn_engine_project_name($ns, (string) $a['name']);
    $desc = mb_substr((string) $a['description'], 0, 4000);
    $pid = $a['maludb_project_id'] !== null && ctype_digit((string) $a['maludb_project_id']) ? (int) $a['maludb_project_id'] : null;
    if ($pid !== null) {
        $p = kn_engine_project($pid);
        if (!$p['ok'] && $p['code'] !== 'not_found') {
            return ['code' => $p['code'], 'error' => $p['error']] + $out;
        }
        if (!$p['ok'] || ($p['data']['project']['name'] ?? null) !== $want) {
            if ($p['ok']) {
                $d = kn_engine_project_delete($pid);
                if (!$d['ok'] && $d['code'] !== 'not_found') {
                    return ['code' => $d['code'], 'error' => $d['error']] + $out;
                }
            }
            $out['recreated'] = true;
            $pid = null;
        }
    }
    $c = kn_engine_project_create($ns, (string) $a['name'], $desc === '' ? null : $desc, $a['description'] === '' ? null : (string) $a['description']);
    if (!$c['ok']) {
        return ['code' => $c['code'], 'error' => $c['error']] + $out;
    }
    $pid = (int) $c['data']['project_id'];
    $pdo->prepare('UPDATE focus_areas SET maludb_project_id = :p WHERE id = :a AND maludb_project_id IS DISTINCT FROM :p')->execute(['p' => (string) $pid, 'a' => $areaId]);
    $out['project_id'] = $pid;
    // the seed terms' subjects
    $seeds = array_values(array_unique(array_filter(array_map('trim', kn_area_pg_array((string) $a['seed_terms'])), static fn ($s) => $s !== '')));
    $typeOf = [];
    if ($seeds !== []) {
        $st = $pdo->prepare('SELECT lower(canonical_name) AS k, subject_type FROM vocabulary_terms WHERE base_id = :b');
        $st->execute(['b' => (int) $a['base_id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $typeOf[$t['k']] = $t['subject_type'] !== null && preg_match('/^[a-z_]{1,60}$/', (string) $t['subject_type']) ? $t['subject_type'] : 'other';
        }
    }
    $wantIds = [];
    foreach ($seeds as $seed) {
        $f = kn_engine_find_subject($seed);
        if (!$f['ok']) {
            return ['code' => $f['code'], 'error' => $f['error']] + $out;
        }
        $sid = (int) ($f['data']['subject']['id'] ?? 0);
        if ($sid <= 0) {
            $s = kn_engine_seed_subjects([['canonical_name' => $seed, 'subject_type' => $typeOf[mb_strtolower($seed)] ?? 'other']]);
            if (!$s['ok']) {
                return ['code' => $s['code'], 'error' => $s['error']] + $out;
            }
            $sid = (int) ($s['data']['subjects'][0]['subject_id'] ?? 0);
        }
        if ($sid > 0) {
            $wantIds[$sid] = true;
        }
    }
    $detail = kn_engine_project($pid);
    $have = [];
    foreach ($detail['ok'] ? ($detail['data']['project']['subjects'] ?? []) : [] as $s) {
        $have[(int) $s['id']] = true;
    }
    foreach (array_keys($wantIds) as $sid) {
        if (!isset($have[$sid])) {
            $l = kn_engine_project_link_subject($pid, $sid);
            if (!$l['ok']) {
                return ['code' => $l['code'], 'error' => $l['error']] + $out;
            }
            $out['linked']++;
        }
    }
    foreach (array_keys($have) as $sid) {
        if (!isset($wantIds[$sid])) {
            $u = kn_engine_project_unlink_subject($pid, $sid);
            if (!$u['ok'] && $u['code'] !== 'not_found') {
                return ['code' => $u['code'], 'error' => $u['error']] + $out;
            }
            $out['unlinked']++;
        }
    }
    return ['ok' => true] + $out;
}

/** Remove an area's project (links first — the engine defect of design §12). ['ok', 'code', 'project_id', 'error']; nothing to remove is ok. */
function kn_area_engine_remove(PDO $pdo, int $areaId): array
{
    $a = kn_area_row($pdo, $areaId);
    $pid = $a !== null && $a['maludb_project_id'] !== null && ctype_digit((string) $a['maludb_project_id']) ? (int) $a['maludb_project_id'] : null;
    if ($pid === null) {
        return ['ok' => true, 'code' => null, 'project_id' => null, 'error' => null];
    }
    $d = kn_engine_project_delete($pid);
    if (!$d['ok'] && $d['code'] !== 'not_found') {
        return ['ok' => false, 'code' => $d['code'], 'project_id' => $pid, 'error' => $d['error']];
    }
    $pdo->prepare('UPDATE focus_areas SET maludb_project_id = NULL WHERE id = :a')->execute(['a' => $areaId]);
    return ['ok' => true, 'code' => null, 'project_id' => $pid, 'error' => null];
}

/** The engine's project id of an area, as an int, or null. */
function kn_area_project_id(?string $id): ?int
{
    return $id !== null && ctype_digit($id) ? (int) $id : null;
}

function kn_area_pg_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== 'NULL') {
            $out[] = $v;
        }
    }
    return $out;
}
