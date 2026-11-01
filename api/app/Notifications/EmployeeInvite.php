<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Welcome email for a new employee login, with a link to choose a password. */
class EmployeeInvite extends Notification
{
    use Queueable;

    public function __construct(private string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/').'/reset-password?token='.$this->token.'&email='.urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Your DigiFox Pro CRM login')
            ->greeting("Hello {$notifiable->name},")
            ->line('An account has been created for you on the DigiFox Pro CRM.')
            ->line('Use the button below to choose your password. The link is valid for 60 minutes; after that, use "Forgot password" on the login page.')
            ->action('Set my password', $url)
            ->line('Your login email is '.$notifiable->email.'.');
    }
}
