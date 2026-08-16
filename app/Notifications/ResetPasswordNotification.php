<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('services.frontend_url'), '/').'/?'.http_build_query([
            'reset_token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your WICARS password')
            ->line('A password reset was requested for your WICARS account.')
            ->action('Reset Password', $url)
            ->line('This link expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If you did not request this, no action is required.');
    }
}
