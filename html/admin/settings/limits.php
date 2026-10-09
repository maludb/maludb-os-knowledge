<?php
declare(strict_types=1);
/** Action `limits_save` (limits.manage; other — pauses for an agent): the Limits form's columns only. Logged `limits.save` (form, the changed fields). */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
admin_settings_handle('limits');
