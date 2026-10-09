<?php
declare(strict_types=1);
/**
 * /bases (screen `base-list`): the bases I may read as cards — status, visibility, my level, sources · sections, last built, needs a
 * rebuild, a building bar; Create a knowledge base for a holder of bases.create; filters q, status, mine, archived. The admin sees
 * every base; a private one they are not on is marked "admin view" (logged on opening, not here) and the Settings link is shown
 * until slice 9's admin index exists.
 */
require_once dirname(__DIR__, 2) . '/app/features/bases/handler.php';
require_right('bases.read');
$pdo = db();
$filters = ['q' => mb_substr(request_string('q'), 0, 80), 'status' => request_string('status'), 'mine' => request_bool('mine'), 'archived' => request_bool('archived')];
$bases = find_bases($pdo, $filters);
log_screen_view($pdo, 'base-list');
$may = ['create' => has_right('bases.create'), 'admin' => is_kn_admin(),
        'settings' => has_right('settings.manage') || has_right('limits.manage') || has_right('retention.manage')];
if (wants_json()) {
    respond_screen(['bases' => array_map('present_base', $bases), 'filters' => $filters, 'may' => $may]);
}
$notices = ['deleted' => ['success', 'Deleted the knowledge base.'],
            'forgetting' => ['warning', 'The base is being deleted: the engine forgets its sections first, then it disappears.']];
render_screen('Knowledge bases', view('bases/index.php', ['bases' => $bases, 'filters' => $filters, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => kn_notice(request_string('notice') ?: null, $notices)]), ['activeNav' => 'bases', 'screen' => 'base-list']);
