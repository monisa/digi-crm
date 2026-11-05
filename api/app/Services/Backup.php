<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Backups written in plain PHP (Hostinger shared hosting has no mysqldump /
 * exec): a gzipped SQL dump of the database daily, and a zip of uploaded
 * files weekly. Kept in storage/app/private/backups (never public).
 * Restore: download the .sql.gz and import it in phpMyAdmin.
 */
class Backup
{
    public const DIR = 'backups';

    public const KEEP_DB = 14;

    public const KEEP_FILES = 4;

    private const STRUCTURE_ONLY = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens'];

    /** @return string file name */
    public function database(): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);
        $name = 'database-'.now()->format('Y-m-d-His').'.sql.gz';
        $path = $disk->path(self::DIR.'/'.$name);
        $gz = gzopen($path, 'wb6');
        if (! $gz) {
            throw new RuntimeException('Cannot write the backup file.');
        }

        try {
            $pdo = DB::connection()->getPdo();
            $mysql = DB::connection()->getDriverName() === 'mysql';
            gzwrite($gz, "-- DigiFox Pro CRM backup ".now()->toDateTimeString()."\n-- Import in phpMyAdmin to restore.\n");
            if ($mysql) {
                gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            }

            foreach ($this->tables() as $table) {
                $q = $mysql ? "`$table`" : "\"$table\"";
                gzwrite($gz, "\n-- Table $table\nDROP TABLE IF EXISTS $q;\n".$this->createSql($table, $mysql).";\n");

                // Sessions, cache and queued jobs are temporary: keep the table, not the rows.
                if (in_array($table, self::STRUCTURE_ONLY, true)) {
                    continue;
                }
                // Rows in INSERTs of 200, streamed so big tables do not fill the memory.
                $buf = [];
                $flush = function () use (&$buf, $gz, $q, $mysql, $pdo) {
                    if (! $buf) {
                        return;
                    }
                    $cols = implode(', ', array_map(fn ($c) => $mysql ? "`$c`" : "\"$c\"", array_keys($buf[0])));
                    $values = implode(",\n", array_map(fn ($r) => '('.implode(', ', array_map(
                        fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $r)).')', $buf));
                    gzwrite($gz, "INSERT INTO $q ($cols) VALUES\n$values;\n");
                    $buf = [];
                };
                foreach (DB::table($table)->cursor() as $row) {
                    $buf[] = (array) $row;
                    if (count($buf) >= 200) {
                        $flush();
                    }
                }
                $flush();
            }
            if ($mysql) {
                gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
            }
        } finally {
            gzclose($gz);
        }
        $this->prune('database-', self::KEEP_DB);

        return $name;
    }

    /** Uploaded files (logos, receipts, chat files…), weekly. */
    public function files(): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);
        $name = 'files-'.now()->format('Y-m-d-His').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($disk->path(self::DIR.'/'.$name), ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Cannot write the files backup.');
        }
        foreach ($disk->allFiles() as $f) {
            if (str_starts_with($f, self::DIR.'/') || str_starts_with($f, '.')) {
                continue;
            }
            $zip->addFile($disk->path($f), $f);
        }
        if ($zip->numFiles === 0) {
            $zip->addFromString('README.txt', 'No uploaded files yet.');
        }
        $zip->close();
        $this->prune('files-', self::KEEP_FILES);

        return $name;
    }

    /** @return list<array{name: string, size: int, at: string, kind: string}> */
    public function list(): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIR))
            ->filter(fn ($f) => preg_match('/(database|files)-[\d-]+\.(sql\.gz|zip)$/', $f))
            ->map(fn ($f) => [
                'name' => basename($f), 'size' => $disk->size($f),
                'at' => date(DATE_ATOM, $disk->lastModified($f)),
                'kind' => str_starts_with(basename($f), 'database-') ? 'database' : 'files',
            ])->sortByDesc('at')->values()->all();
    }

    public function path(string $name): ?string
    {
        if (! preg_match('/^(database|files)-[\d-]+\.(sql\.gz|zip)$/', $name)) {
            return null; // no path tricks
        }
        $f = self::DIR.'/'.$name;

        return Storage::disk('local')->exists($f) ? Storage::disk('local')->path($f) : null;
    }

    /** @return list<string> */
    private function tables(): array
    {
        $tables = DB::connection()->getDriverName() === 'mysql'
            ? collect(DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))->map(fn ($r) => array_values((array) $r)[0])
            : collect(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"))->pluck('name');

        return $tables->values()->all();
    }

    private function createSql(string $table, bool $mysql): string
    {
        if ($mysql) {
            return (string) array_values((array) DB::selectOne("SHOW CREATE TABLE `$table`"))[1];
        }

        return (string) DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?", [$table])->sql;
    }

    private function prune(string $prefix, int $keep): void
    {
        $disk = Storage::disk('local');
        collect($disk->files(self::DIR))->filter(fn ($f) => str_starts_with(basename($f), $prefix))
            ->sortByDesc(fn ($f) => $disk->lastModified($f).$f)->slice($keep)->each(fn ($f) => $disk->delete($f));
    }
}
