<?php

namespace App\Console\Commands;

use App\Services\Backup;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    protected $signature = 'crm:backup {--files : Also zip the uploaded files}';

    protected $description = 'Back up the database (and optionally uploaded files)';

    public function handle(Backup $backup): int
    {
        $this->info('Database: '.$backup->database());
        if ($this->option('files')) {
            $this->info('Files: '.($backup->files() ?? 'skipped (ZipArchive missing)'));
        }

        return self::SUCCESS;
    }
}
