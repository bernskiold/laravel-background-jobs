<?php

use Bernskiold\LaravelBackgroundJobs\BackgroundJobRun;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Bernskiold\LaravelBackgroundJobs\Notifications\BackgroundJobRunCompleted;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\JobType;
use Bernskiold\LaravelBackgroundJobs\Tests\Fixtures\Report;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->owner = $this->user();

    /**
     * Make a settled run with the outcomes that you give, and render its
     * email. The text is the feature here, so the tests check what the user
     * reads, and not the counters.
     *
     * @param  list<string>  $failureReasons
     */
    $this->render = function (int $succeeded, int $failed, array $meta = [], ?Report $report = null, array $failureReasons = []): MailMessage {
        $run = BackgroundJobRun::start(
            type: JobType::ImportReports,
            jobable: $report,
            meta: $meta + ['item_label' => 'audience'],
            userId: $this->owner->id,
        );

        $total = $succeeded + $failed;

        $children = collect($total > 0 ? range(1, $total) : [])
            ->map(fn (int $i) => $run->addChild(null, ['label' => "Audience {$i}"]));

        $run->seal();

        $children->take($succeeded)->each->finish();
        $children->skip($succeeded)->values()->each(
            fn (BackgroundJob $child, int $i) => $child->fail($failureReasons[$i] ?? 'The aggregation stopped.')
        );

        return (new BackgroundJobRunCompleted($run->job->refresh()))->toMail($this->owner);
    };

    $this->body = fn (MailMessage $mail): string => collect($mail->introLines)->implode(' ');
});

describe('all succeeded', function () {
    it('says completed without errors', function () {
        $mail = ($this->render)(succeeded: 40, failed: 0);

        expect($mail->subject)->toBe('[Import Reports] Completed')
            ->and(($this->body)($mail))->toContain('All 40 audiences completed successfully')
            ->and(($this->body)($mail))->not->toContain('•');
    });
});

describe('some failed', function () {
    it('says completed with errors and gives both counts', function () {
        $mail = ($this->render)(succeeded: 38, failed: 2);

        expect($mail->subject)->toBe('[Import Reports] Completed with errors')
            ->and(($this->body)($mail))->toContain('38 of 40 audiences completed')
            ->and(($this->body)($mail))->toContain('2 failed');
    });

    it('names each failed item with its reason', function () {
        $mail = ($this->render)(succeeded: 1, failed: 2, failureReasons: ['Missing respondents', 'Timed out']);

        expect(($this->body)($mail))->toContain('Audience 2: Missing respondents')
            ->and(($this->body)($mail))->toContain('Audience 3: Timed out');
    });

    it('shortens a long list of failures', function () {
        $mail = ($this->render)(succeeded: 1, failed: 12);

        expect(($this->body)($mail))->toContain('…and 2 more');
    });
});

describe('a run of one', function () {
    it('does not say "All 1"', function (int $succeeded, int $failed, string $expected) {
        $body = ($this->body)(($this->render)(succeeded: $succeeded, failed: $failed));

        expect($body)->toContain($expected)
            ->and($body)->not->toContain('All 1');
    })->with([
        'succeeded' => [1, 0, 'The audience completed successfully'],
        'failed' => [0, 1, 'The audience failed'],
    ]);
});

describe('all failed', function () {
    it('says failed', function () {
        $mail = ($this->render)(succeeded: 0, failed: 3);

        expect($mail->subject)->toBe('[Import Reports] Failed')
            ->and(($this->body)($mail))->toContain('All 3 audiences failed');
    });
});

describe('skipped items', function () {
    it('reports skipped items separately from failed items', function () {
        $mail = ($this->render)(
            succeeded: 2,
            failed: 0,
            meta: ['skipped' => [['reason' => 'No data'], ['reason' => 'No data']]],
        );

        expect(($this->body)($mail))->toContain('2 audiences were skipped')
            ->and(($this->body)($mail))->toContain('All 2 audiences completed successfully')
            ->and($mail->subject)->not->toContain('errors');
    });

    it('explains a run where all items were skipped', function () {
        $mail = ($this->render)(succeeded: 0, failed: 0, meta: ['skipped' => [['reason' => 'No data']]]);

        expect(($this->body)($mail))->toContain('No audiences were eligible');
    });
});

describe('the target', function () {
    it('names the model of the run', function () {
        $mail = ($this->render)(succeeded: 2, failed: 0, report: Report::query()->create(['name' => 'Q3 Report']));

        expect(($this->body)($mail))->toContain('for Q3 Report');
    });

    it('leaves out the target for a run without a model', function () {
        $mail = ($this->render)(succeeded: 2, failed: 0);

        expect(($this->body)($mail))->not->toContain(' for .');
    });
});

it('takes its noun from the run', function () {
    $run = BackgroundJobRun::start(
        type: JobType::ExportReports,
        meta: ['item_label' => 'export'],
        userId: $this->owner->id,
    );

    $children = collect(range(1, 3))->map(fn () => $run->addChild(null));
    $run->seal();
    $children->each->finish();

    $mail = (new BackgroundJobRunCompleted($run->job->refresh()))->toMail($this->owner);

    expect(($this->body)($mail))->toContain('All 3 exports completed successfully')
        ->and(($this->body)($mail))->not->toContain('audience')
        ->and($mail->subject)->toContain('Export Reports');
});
