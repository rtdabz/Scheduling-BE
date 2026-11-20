<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One-time code proving the VPAA owns the Gmail address they are linking. Sent synchronously so
 * a mail failure is reported to the VPAA instead of silently sitting in the queue.
 */
class RecoveryEmailVerificationNotification extends Notification
{
    public function __construct(
        public readonly string $code,
        private readonly int $expiresInMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your TCC Scheduling recovery email')
            ->line('Use this code in Account Settings to link this Gmail address to the VPAA account:')
            ->line("**{$this->code}**")
            ->line("The code expires in {$this->expiresInMinutes} minutes.")
            ->line('Once linked, password reset links for the VPAA account are sent to this address.')
            ->line('If you did not request this, ignore this email. Nothing changes until the code is entered.');
    }
}
