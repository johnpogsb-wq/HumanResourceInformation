<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The sign-in code, in an email.
 *
 * Deliberately not queued. Everything else about a notification is better on a
 * queue, and this one is the exception: the person is sitting on the challenge
 * screen waiting for it, and a queue that is not running — which on a fresh
 * clone it is not — would leave them looking at a field no code ever arrives
 * for, with nothing on screen saying why.
 */
class LoginOtp extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly int $ttlSeconds,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = max(1, (int) ceil($this->ttlSeconds / 60));

        return (new MailMessage)
            ->subject('Your PrimePower sign-in code')
            ->greeting('Sign-in code')
            /*
             * The code is on its own line and nothing else is, because this
             * email is read on a phone in a hurry and the one thing it is for
             * should not have to be found.
             */
            ->line('Enter this code to finish signing in:')
            ->line('**'.$this->code.'**')
            ->line("It expires in {$minutes} minute(s) and works once.")
            /*
             * Said plainly rather than left implied. Somebody who gets this
             * without having tried to sign in has learnt that their password
             * is known to someone else, and that is the moment the message is
             * worth anything at all.
             */
            ->line('If you did not try to sign in, somebody else has your password — change it as soon as you can.')
            ->salutation('PrimePower Manpower HRIS');
    }
}
