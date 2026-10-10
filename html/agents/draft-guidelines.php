<?php
declare(strict_types=1);
/**
 * Action `guidelines_draft_request` (base.manage on a curated base; people only): `base` — a base with at least one extracted section. The Knowledge Expert is asked to read a sample
 * and DRAFT guidelines (saved inactive, origin agent — a draft shapes nothing until a curator activates it): one draft_guidelines dispatch acting as the caller, called by the worker's
 * next pass (a live one is returned, not doubled). Logged `agent.dispatch` (requested true, kind). Location /bases/{id}/guidelines.
 */
require_once dirname(__DIR__, 2) . '/app/features/agents/handler.php';
agent_request_handle('draft_guidelines', 'guidelines', 'guidelinesChanged');
