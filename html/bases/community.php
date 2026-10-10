<?php
declare(strict_types=1);
/**
 * /bases/{id}/community?community= (screen `community-view`): one community — verified to be one of the base's (kn_engine_communities), else
 * 404 "Community not found." — its label, size and members (the subjects confined, each a link to its subject-view), and "Ask about this".
 * MaluDB computes no communities from ingested documents yet (M6); this works the day it does.
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
require_once APP_ROOT . '/app/features/graph/queries.php';
require_right('bases.read');
$pdo = db();
$b = base_from_request($pdo, 'community-view');
$id = (int) $b['base_id'];
graph_fresh(graph_curates($id) && request_string('fresh') === '1');
$cid = request_integer('community');
if ($cid === null || $cid <= 0) {
    refuse(404, 'Community not found.');
}
$c = graph_community($pdo, $id, $cid) ?? refuse(404, 'Community not found.');
base_screen_view($pdo, 'community-view', $id, ['community_id' => $cid]);
if (wants_json()) {
    respond_screen(['base' => ['base_id' => $id, 'name' => $b['name']]] + present_graph_community($id, $c));
}
render_screen($c['community']['label'] ?? 'Community', view('graph/community.php', ['b' => $b, 'c' => $c, 'here' => graph_here(), 'back' => back_link()]), ['activeNav' => 'bases', 'screen' => 'community-view', 'entity' => 'base', 'recordId' => (string) $id]);
