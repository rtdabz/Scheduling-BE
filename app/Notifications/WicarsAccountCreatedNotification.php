<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WicarsAccountCreatedNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $loginUrl = rtrim((string) config('services.frontend_url'), '/');

        return (new MailMessage)
            ->subject('Your WICARS account is ready')
            ->greeting('Welcome to WICARS, '.$notifiable->first_name.'!')
            ->line('Your WICARS account has been created with this institutional email address: '.$notifiable->email.'.')
            ->line('You can sign in with your Gmail account by opening the link below and selecting "Continue with Google".')
            ->action('Sign in to WICARS', $loginUrl)
            ->line('Use the Gmail account that matches your institutional email address.')
            ->line('If you did not expect this account, please contact the VPAA office.');
    }
}
