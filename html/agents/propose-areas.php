<?php
declare(strict_types=1);
/**
 * Action `areas_propose_request` (base.manage on a curated base; people only): `base` — a base with at least one extracted section. The Knowledge Expert is asked to propose
 * focus areas from the graph: one propose_areas dispatch acting as the caller, called by the worker's next pass (a live one is returned, not doubled: "The expert is already on
 * it."). Logged `agent.dispatch` (requested true, kind). Location /bases/{id}/areas.
 */
require_once dirname(__DIR__, 2) . '/app/features/agents/handler.php';
agent_request_handle('propose_areas', 'areas', 'areasChanged');
