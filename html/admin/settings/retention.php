<?php
declare(strict_types=1);
/** Action `retention_set` (retention.manage; other — pauses for an agent; confirmed): question retention in days. Logged `retention.set`. */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
admin_settings_handle('retention');
