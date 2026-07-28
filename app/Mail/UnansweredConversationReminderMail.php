<?php

namespace App\Mail;

use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UnansweredConversationReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly User $recipient,
        public readonly string $customerName,
        public readonly string $messagePreview,
        public readonly int $waitingMinutes,
        public readonly string $conversationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Customer reply waiting — {$this->customerName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.unanswered-conversation-reminder',
        );
    }
}
