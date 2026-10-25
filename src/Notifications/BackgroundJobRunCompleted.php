<?php

namespace Bernskiold\LaravelBackgroundJobs\Notifications;

use Bernskiold\LaravelBackgroundJobs\Enums\BackgroundJobStatus;
use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * The one email for a bulk run. It replaces one email for each item.
 *
 * The subject line must show the outcome, because that is the reason to send
 * one email and not forty. Thus each of the three outcomes has its own
 * subject.
 *
 * The text does not refer to one job type. The noun comes from the
 * "item_label" item of the meta of the run, for example "audience".
 */
class BackgroundJobRunCompleted extends BackgroundJobNotification
{
    /**
     * The maximum number of failures that the email names.
     */
    protected const MAX_LISTED_FAILURES = 10;

    public function toMail(mixed $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject())
            ->greeting($this->greeting())
            ->line(__('Hi,'))
            ->line($this->summary());

        if ($skipped = $this->skippedLine()) {
            $message->line($skipped);
        }

        foreach ($this->failureLines() as $line) {
            $message->line($line);
        }

        return $this->withAction($message, __('View Run'));
    }

    protected function subject(): string
    {
        $type = $this->backgroundJob->typeLabel();

        return match (true) {
            $this->allFailed() => __('[:type] Failed', ['type' => $type]),
            $this->anyFailed() => __('[:type] Completed with errors', ['type' => $type]),
            default => __('[:type] Completed', ['type' => $type]),
        };
    }

    protected function greeting(): string
    {
        $type = $this->backgroundJob->typeLabel();

        return match (true) {
            $this->allFailed() => __(':type failed', ['type' => $type]),
            $this->anyFailed() => __(':type completed with errors', ['type' => $type]),
            default => __(':type completed', ['type' => $type]),
        };
    }

    /**
     * The main sentence. It must give the counts, because "completed" alone
     * does not tell the user anything new.
     */
    protected function summary(): string
    {
        $expected = (int) $this->backgroundJob->children_expected;
        $succeeded = $this->backgroundJob->children_succeeded;
        $failed = $this->backgroundJob->children_failed;
        $items = $this->itemLabel($expected);
        $target = $this->target();

        if ($expected === 0) {
            return __('No :items were eligible for processing:target.', [
                'items' => $this->itemLabel(2),
                'target' => $target,
            ]);
        }

        // "All" is only correct for more than one item. Otherwise the text
        // is "All 1 audience completed".
        if ($this->allFailed()) {
            return trans_choice(
                '{1} The :item failed:target.|[2,*] All :count :items failed:target.',
                $expected,
                ['item' => $items, 'items' => $items, 'target' => $target]
            );
        }

        if ($this->anyFailed()) {
            return __(':succeeded of :expected :items completed:target. :failed failed.', [
                'succeeded' => $succeeded,
                'expected' => $expected,
                'items' => $items,
                'target' => $target,
                'failed' => $failed,
            ]);
        }

        return trans_choice(
            '{1} The :item completed successfully:target.|[2,*] All :count :items completed successfully:target.',
            $expected,
            ['item' => $items, 'items' => $items, 'target' => $target]
        );
    }

    /**
     * The email gives skipped items separately from failed items. A skipped
     * item never ran, so it is not correct to count it as a failure.
     */
    protected function skippedLine(): ?string
    {
        $skipped = count((array) ($this->backgroundJob->meta['skipped'] ?? []));

        if ($skipped === 0) {
            return null;
        }

        return trans_choice(
            '{1} 1 :item was skipped because no data was available for it.|[2,*] :count :items were skipped because no data was available for them.',
            $skipped,
            [
                'item' => $this->itemLabel(1),
                'items' => $this->itemLabel(2),
            ]
        );
    }

    /**
     * Name the items that failed. Then the user does not have to look for the
     * 2 of 40 that failed.
     *
     * @return list<string>
     */
    protected function failureLines(): array
    {
        if (! $this->anyFailed()) {
            return [];
        }

        $failures = $this->backgroundJob->children()
            ->where('status', BackgroundJobStatus::Failed)
            ->with('jobable')
            ->limit(self::MAX_LISTED_FAILURES)
            ->get();

        if ($failures->isEmpty()) {
            return [];
        }

        $lines = $failures
            ->map(fn (BackgroundJob $child) => '• '.$this->describeFailure($child))
            ->values()
            ->all();

        $remaining = $this->backgroundJob->children_failed - $failures->count();

        if ($remaining > 0) {
            $lines[] = __('…and :count more. Open the run to see them all.', ['count' => $remaining]);
        }

        return $lines;
    }

    protected function describeFailure(BackgroundJob $child): string
    {
        $name = $child->label();

        if (! $child->failure_reason) {
            return $name;
        }

        return $name.': '.Str::limit($child->failure_reason, 200);
    }

    protected function itemLabel(int $count): string
    {
        $label = $this->backgroundJob->meta['item_label'] ?? 'item';

        return trans_choice("{1} {$label}|[2,*] {$label}s", $count);
    }

    protected function allFailed(): bool
    {
        return (int) $this->backgroundJob->children_expected > 0
            && $this->backgroundJob->children_succeeded === 0;
    }

    protected function anyFailed(): bool
    {
        return $this->backgroundJob->children_failed > 0;
    }
}
