<?php

return ['mfa_required' => env('PLATFORM_MFA_REQUIRED', false), 'export_max_rows' => (int) env('EXPORT_MAX_ROWS', 250000), 'export_max_bytes' => (int) env('EXPORT_MAX_BYTES', 268435456), 'backup_path' => env('PLATFORM_BACKUP_PATH', storage_path('app/private/platform-backups')), 'backup_retention_days' => 14, 'backup_password' => env('PLATFORM_BACKUP_PASSWORD')];
