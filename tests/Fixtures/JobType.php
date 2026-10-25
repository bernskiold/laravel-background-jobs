<?php

namespace Bernskiold\LaravelBackgroundJobs\Tests\Fixtures;

use Bernskiold\LaravelBackgroundJobs\Contracts\BackgroundJobType;

enum JobType: string implements BackgroundJobType
{
    case ImportReports = 'import_reports';
    case ExportReports = 'export_reports';

    public function label(): string
    {
        return match ($this) {
            self::ImportReports => 'Import Reports',
            self::ExportReports => 'Export Reports',
        };
    }
}
