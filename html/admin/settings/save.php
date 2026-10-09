<?php
declare(strict_types=1);
/** Action `settings_save` (settings.manage; other — pauses for an agent): the Settings form's columns only. Logged `settings.save` (form, the changed fields). */
require_once dirname(__DIR__, 3) . '/app/features/admin/handler.php';
admin_settings_handle('settings');
