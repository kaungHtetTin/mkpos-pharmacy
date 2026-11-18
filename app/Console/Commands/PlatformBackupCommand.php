<?php

namespace App\Console\Commands;

use App\Operations\PlatformBackup;
use Illuminate\Console\Command;
class PlatformBackupCommand extends Command
{
    protected $signature = 'platform:backup {--verify= : Verify an existing private backup}';
    protected $description = 'Create and verify an AES-256 encrypted private database/media backup';
    public function handle(PlatformBackup $backup): int
    {
        try {
            if ($file = $this->option('verify')) {
                $result = $backup->verify($file);
                $this->info('Backup verified: ' . $result['files'] . ' files.');
            } else {
                $path = $backup->create();
                $backup->prune();
                $this->info('Backup created and verified: ' . basename($path));
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Backup failed. Check the private application log; the previous backup was retained.');
            return self::FAILURE;
        }
    }
}
