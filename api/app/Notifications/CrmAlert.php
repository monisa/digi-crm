<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One alert: always stored for the bell, emailed too when the type allows it
 * and the person has not switched that email off. Sent through the queue, so
 * the cron worker delivers emails without slowing the app down.
 */
class CrmAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public string $link,
        public bool $email = false,
    ) {}

    /** Bell alerts are saved straight away; only emails wait for the queue worker. */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function via(object $notifiable): array
    {
        $wantsMail = $this->email && method_exists($notifiable, 'wantsEmail') ? $notifiable->wantsEmail($this->type) : $this->email;
        $channels = method_exists($notifiable, 'getKey') && $notifiable->getKey() ? ['database'] : [];

        return $wantsMail ? [...$channels, 'mail'] : $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'category' => config("crm.notification_types.{$this->type}.1", 'other'),
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title.' · DigiFox Pro CRM')
            ->greeting('Hello'.(isset($notifiable->name) ? ' '.strtok($notifiable->name, ' ') : '').',')
            ->line($this->body)
            ->action('Open in the CRM', rtrim(config('app.frontend_url'), '/').$this->link)
            ->line('You can switch these emails off under your name → Notifications.');
    }
}
