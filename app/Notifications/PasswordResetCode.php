<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the 6-digit code the app asks for on its "Forgot password" screen.
 *
 * A code rather than a link because there is no web front end to land a link
 * on; the person types it into the app. Sent synchronously (not queued) so the
 * request fails loudly in the log if mail isn't configured on the server.
 */
class PasswordResetCode extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly int $expiresInMinutes,
        private readonly string $lang,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->lang === 'fr') {
            return (new MailMessage)
                ->subject('Votre code de réinitialisation')
                ->greeting('Bonjour,')
                ->line('Voici votre code pour réinitialiser votre mot de passe :')
                ->line("**{$this->code}**")
                ->line("Il expire dans {$this->expiresInMinutes} minutes.")
                ->line("Si vous n'avez rien demandé, ignorez cet e-mail. Votre mot de passe ne change pas.")
                ->salutation(config('app.name'));
        }

        return (new MailMessage)
            ->subject('Your password reset code')
            ->greeting('Hello,')
            ->line('Here is your code to reset your password:')
            ->line("**{$this->code}**")
            ->line("It expires in {$this->expiresInMinutes} minutes.")
            ->line("If you didn't ask for this, ignore this email. Your password won't change.")
            ->salutation(config('app.name'));
    }
}
