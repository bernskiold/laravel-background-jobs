<?php

namespace Bernskiold\LaravelBackgroundJobs\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class BackgroundJobFailed extends BackgroundJobNotification
{
    public function toMail(mixed $notifiable): MailMessage
    {
        $type = $this->backgroundJob->typeLabel();

        $message = (new MailMessage)
            ->subject(__('[:type] Background job failed', ['type' => $type]))
            ->greeting(__(':type failed', ['type' => $type]))
            ->line(__('Hi,'))
            ->line(__('The background job that you started:target has failed.', ['target' => $this->target()]));

        return $this->withAction($message, __('Inspect Background Job'));
    }
}
