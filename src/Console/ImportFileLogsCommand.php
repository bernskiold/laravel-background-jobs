<?php

namespace Bernskiold\LaravelBackgroundJobs\Console;

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobLogStore;
use Bernskiold\LaravelBackgroundJobs\Logging\FileLogStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

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

    /**
     * The number of entries that the command writes at a time.
     */
    protected const BATCH_SIZE = 500;

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

            try {
                $this->import($store, $jobId, $file);
            } catch (Throwable $exception) {
                $store->delete($jobId);

                throw $exception;
            }

            if ($this->option('delete')) {
                File::delete($file);
            }

            $imported++;
        }

        $this->components->info("Imported {$imported} log file(s). Skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Write the entries of one file in batches. The command reads the file
     * line by line, so a large file does not have to fit in memory.
     */
    protected function import(BackgroundJobLogStore $store, string $jobId, string $file): void
    {
        $batch = [];

        foreach (FileLogStore::stream($file) as $entry) {
            $batch[] = $entry;

            if (count($batch) >= self::BATCH_SIZE) {
                $store->write($jobId, $batch);
                $batch = [];
            }
        }

        $store->write($jobId, $batch);
    }
}
