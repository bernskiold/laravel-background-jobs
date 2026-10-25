<?php

use Bernskiold\LaravelBackgroundJobs\BackgroundJobs;
use Bernskiold\LaravelBackgroundJobs\Exceptions\InvalidBackgroundJobType;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\NotAJobType;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\OtherJobType;

it('resolves a value to a case of a configured enum', function () {
    expect(BackgroundJobs::resolveType('import_reports'))->toBe(JobType::ImportReports);
});

it('resolves a value to a case of a registered enum', function () {
    BackgroundJobs::registerTypes(OtherJobType::class);

    expect(BackgroundJobs::resolveType('send_newsletter'))->toBe(OtherJobType::SendNewsletter);
});

it('gives null for an unknown value', function () {
    expect(BackgroundJobs::resolveType('removed_type'))->toBeNull()
        ->and(BackgroundJobs::resolveType(null))->toBeNull();
});

it('gives a case back as it is', function () {
    expect(BackgroundJobs::resolveType(JobType::ExportReports))->toBe(JobType::ExportReports);
});

it('lists the cases of all the enums', function () {
    BackgroundJobs::registerTypes(OtherJobType::class, OtherJobType::class);

    expect(BackgroundJobs::types()->all())->toBe([
        JobType::ImportReports,
        JobType::ExportReports,
        OtherJobType::SendNewsletter,
    ]);
});

it('refuses an enum that does not implement the contract', function () {
    BackgroundJobs::registerTypes(NotAJobType::class);
})->throws(InvalidBackgroundJobType::class);

it('refuses a configured class that is not a type enum', function () {
    config(['background-jobs.types' => [stdClass::class]]);

    BackgroundJobs::types();
})->throws(InvalidBackgroundJobType::class);
