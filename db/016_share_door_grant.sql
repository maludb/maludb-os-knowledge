-- 016: THE SHARE DOOR'S ONE GRANT (slice 7, docs/build-specs/api-keys-k7.md). db/015 made kn_application_bases(app_key) — the
-- bases shared with a sibling application, as os.knowledge-bases/1 rows — executable by knowledge_records_ro alone, for a records
-- server that would read it directly. The K7 shares do not: the records server reaches every share through the engine bridge
-- (/internal/bridge.php, docs/knowledge-mcp-tool-surface.md "The engine bridge"), which PHP serves as knowledge_rw, and kn_share_ask /
-- kn_share_search / kn_share_bases_index (app/knowledge/share.php) answer from this one function so that the set of bases a sibling
-- may read is the database's, in one place. Additive: one grant, nothing else changes; the function stays closed to PUBLIC.
BEGIN;

GRANT EXECUTE ON FUNCTION kn_application_bases(text) TO knowledge_rw;

COMMIT;
