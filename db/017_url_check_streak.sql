-- 017: THE URL CHECK'S FAILURE STREAK (slice 8, docs/build-specs/agents-feeds.md "URL change checks"). The weekly check of an address source
-- calls a page vanished on a 404 or 410, or on a fetch that failed TWO checks running (a week apart) — a single failed fetch is a bad day,
-- not a vanished page. The count of checks that failed in a row has to live somewhere between two passes a week apart: nothing in db/001-016
-- holds it (sources.error is the read step's word for a source in status failed, and a ready source has none; the activity log is not a
-- state). One additive column on sources, written only by the worker's url_checks step, reset by any check that reached the page.
-- Additive: no existing row, trigger, view or grant changes (knowledge_rw holds the table's privileges; the column inherits them).
BEGIN;

ALTER TABLE sources ADD COLUMN url_failed_checks smallint NOT NULL DEFAULT 0 CHECK (url_failed_checks >= 0);
COMMENT ON COLUMN sources.url_failed_checks IS 'Address sources: the weekly checks that failed to reach the page in a row (fetch_failed); two = vanished. Reset by a check that reached it.';

COMMIT;
