<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Queued so account creation does not hold the HTTP response open for the
// SMTP session. A send to smtp.gmail.com measured ~4s of round trips, which
// was ~85% of the POST /api/user request time.
//
// The email carries a one-time setup link, never a password: the user chooses
// their own on the login page, and the VPAA never sees it.
class AccountInvitationNotification extends Notification implements ShouldQueue
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
            'invite' => 1,
        ]);
        $hours = intdiv((int) config('auth.passwords.invites.expire'), 60);

        return (new MailMessage)
            ->subject('Set up your WICARS account')
            ->greeting('Welcome to WICARS, '.$notifiable->first_name.'!')
            ->line('The VPAA office has created a WICARS account for you.')
            ->line('Your username is: '.$notifiable->username)
            ->line('Open the link below to choose your password.')
            ->action('Set up my account', $url)
            ->line("This link can be used once and expires in {$hours} hours.")
            ->line('You can also sign in with "Continue with Google" using this email address.')
            ->line('If you did not expect this account, please contact the VPAA office.');
    }
}
