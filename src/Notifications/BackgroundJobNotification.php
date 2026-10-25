<?php

namespace Bernskiold\LaravelBackgroundJobs\Notifications;

use Bernskiold\LaravelBackgroundJobs\Models\BackgroundJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The base of the notifications about a background job.
 *
 * The queue and the channels come from the "background-jobs.notifications"
 * config.
 */
abstract class BackgroundJobNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public BackgroundJob $backgroundJob,
    ) {
        $this->onQueue(config('background-jobs.notifications.queue'));
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return config('background-jobs.notifications.channels', ['mail']);
    }

    abstract public function toMail(mixed $notifiable): MailMessage;

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'background_job_id' => $this->backgroundJob->getKey(),
            'type' => $this->backgroundJob->type?->value,
            'status' => $this->backgroundJob->status->value,
            'url' => $this->backgroundJob->url(),
        ];
    }

    /**
     * Add the action button when the job has a page.
     */
    protected function withAction(MailMessage $message, string $label): MailMessage
    {
        $url = $this->backgroundJob->url();

        if ($url === null) {
            return $message;
        }

        return $message->action($label, $url);
    }

    /**
     * The name of the model of the job, for example " for Study 2026". The
     * value is empty when the job has no model.
     */
    protected function target(): string
    {
        $name = $this->backgroundJob->jobable?->getAttribute('name');

        return $name ? ' '.__('for :name', ['name' => $name]) : '';
    }
}
