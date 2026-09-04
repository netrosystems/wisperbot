<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AiCreditsThresholdNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $threshold,
        public readonly int $used,
        public readonly int $allowance,
        public readonly string $resetsAt,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ai_credits_threshold',
            'threshold' => $this->threshold,
            'used' => $this->used,
            'allowance' => $this->allowance,
            'resets_at' => $this->resetsAt,
            'message' => $this->message(),
            'url' => route('client.subscription.show'),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->threshold >= 100 ? 'WisperBot AI credits exhausted' : 'WisperBot AI credits are running low')
            ->line($this->message())
            ->action('Review AI usage', route('client.subscription.show'));
    }

    private function message(): string
    {
        return $this->threshold >= 100
            ? "Your organization has used all {$this->allowance} managed AI credits for this period."
            : "Your organization has used at least 80% of its managed AI credits ({$this->used} of {$this->allowance}).";
    }
}
