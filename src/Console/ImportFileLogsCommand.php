<?php

namespace Bernskiold\LaravelBackgroundJobs\Console;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Logging\FileLogStore;
use Bernskiold\LaravelBackgroundJobs\Logging\LogEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Copies the log files of earlier versions into the configured log store.
 *
 * Run it one time on each server that has log files, after you change the log
 * store from "file" to "database".
 */
#[AsCommand(name: 'background-jobs:import-file-logs')]
class ImportFileLogsCommand extends Command
{
    protected $signature = 'background-jobs:import-file-logs
        {--path= : The directory with the log files. The default is the "logs.path" config.}
        {--delete : Delete each file after the import.}';

    protected $description = 'Copy the log files of background jobs into the configured log store.';

    public function handle(): int
    {
        $path = rtrim((string) ($this->option('path') ?? BackgroundJobs::logPath()), '/');
        $store = BackgroundJobs::logs()->store();

        if ($store instanceof FileLogStore) {
            $this->components->error('The log store is "file". Change it before you import the files.');

            return self::FAILURE;
        }

        if (! File::isDirectory($path)) {
            $this->components->info("There is no directory [{$path}]. There is nothing to import.");

            return self::SUCCESS;
        }

        $imported = 0;
        $skipped = 0;

        foreach (File::glob("{$path}/*.log") as $file) {
            $jobId = basename($file, '.log');

            if (! ctype_digit($jobId) || ! BackgroundJobs::model()::query()->whereKey($jobId)->exists() || $store->exists($jobId)) {
                $skipped++;

                continue;
            }

            foreach (array_chunk(FileLogStore::parse((string) File::get($file)), 500) as $entries) {
                /** @var list<LogEntry> $entries */
                $store->write($jobId, $entries);
            }

            if ($this->option('delete')) {
                File::delete($file);
            }

            $imported++;
        }

        $this->components->info("Imported {$imported} log file(s). Skipped {$skipped}.");

        return self::SUCCESS;
    }
}
