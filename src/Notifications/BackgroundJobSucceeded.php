<?php

namespace Bernskiold\LaravelBackgroundJobs\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class BackgroundJobSucceeded extends BackgroundJobNotification
{
    public function toMail(mixed $notifiable): MailMessage
    {
        $type = $this->backgroundJob->typeLabel();

        $message = (new MailMessage)
            ->subject(__('[:type] Background job completed successfully', ['type' => $type]))
            ->greeting(__(':type successfully completed', ['type' => $type]))
            ->line(__('Hi,'))
            ->line(__('The background job that you started:target has been completed successfully.', ['target' => $this->target()]));

        return $this->withAction($message, __('View Background Job'));
    }
}
