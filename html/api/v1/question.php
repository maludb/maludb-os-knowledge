<?php
declare(strict_types=1);
/**
 * GET /api/v1/questions/{id} — a question as os.knowledge-answer/1: for a key, one asked with THIS key or the key it was rotated from (any other is 404, in
 * the same words as a missing one); for a session caller, whatever mcp_questions shows them. The key's call is counted once first.
 */
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/keys/handler.php';
api_cors();
api_require_get();
api_authenticate();
api_rate_limit();
$pdo = db();
$q = api_question_for_caller($pdo, (int) ($_GET['id'] ?? 0));
$base = find_base($pdo, (int) $q['base_id']);
$area = $q['focus_area_id'] === null ? null : ($pdo->query('SELECT slug, name FROM focus_areas WHERE id = ' . (int) $q['focus_area_id'])->fetch(PDO::FETCH_ASSOC) ?: null);
$base = ['key' => $base['key'], 'name' => $base['name']];
api_json(ask_present_question($q, $base, $area, question_citations($pdo, (int) $q['question_id'])));
