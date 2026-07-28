<?php

namespace App\Console\Commands;

use App\Mail\UnansweredConversationReminderMail;
use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class SendUnansweredConversationRemindersCommand extends Command
{
    protected $signature = 'inbox:send-unanswered-reminders {--minutes=60}';

    protected $description = 'Email the responsible client users when a customer has waited too long for a reply';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);
        $queued = 0;

        Conversation::query()
            ->with(['workspace.owner', 'assignedUser', 'contact', 'channelAccount'])
            ->whereIn('status', ['open', 'pending'])
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<=', $cutoff)
            ->where(function ($query) {
                $query->whereNull('unanswered_reminder_sent_at')
                    ->orWhereColumn('unanswered_reminder_sent_at', '<', 'last_inbound_at');
            })
            ->whereDoesntHave('messages', function ($query) {
                $query->where('direction', 'out')
                    ->where('status', '!=', 'failed')
                    ->whereColumn('messages.sent_at', '>=', 'conversations.last_inbound_at');
            })
            ->orderBy('id')
            ->chunkById(100, function ($conversations) use (&$queued) {
                foreach ($conversations as $conversation) {
                    $recipients = $this->recipients($conversation);
                    if ($recipients->isEmpty()) {
                        continue;
                    }

                    $lastInbound = $conversation->messages()
                        ->where('direction', 'in')
                        ->latest('sent_at')
                        ->first();
                    $customerName = $this->customerName($conversation);
                    $preview = mb_substr(trim((string) $lastInbound?->body), 0, 240);
                    $waitingMinutes = max(
                        1,
                        (int) $conversation->last_inbound_at->diffInMinutes(now())
                    );

                    foreach ($recipients as $recipient) {
                        Mail::to($recipient->email)->queue(
                            new UnansweredConversationReminderMail(
                                conversation: $conversation,
                                recipient: $recipient,
                                customerName: $customerName,
                                messagePreview: $preview,
                                waitingMinutes: $waitingMinutes,
                                conversationUrl: route('client.inbox.show', $conversation),
                            )
                        );
                        $queued++;
                    }

                    $conversation->update(['unanswered_reminder_sent_at' => now()]);
                }
            });

        $this->info("Queued {$queued} unanswered-conversation reminder email(s).");

        return self::SUCCESS;
    }

    /**
     * Notify the assigned teammate and workspace owner. If they are the same
     * user, only one email is sent.
     *
     * @return Collection<int, User>
     */
    private function recipients(Conversation $conversation): Collection
    {
        return collect([$conversation->assignedUser, $conversation->workspace?->owner])
            ->filter(fn ($user) => $user instanceof User
                && $user->status === User::STATUS_ACTIVE
                && filled($user->email))
            ->unique('id')
            ->values();
    }

    private function customerName(Conversation $conversation): string
    {
        $contact = $conversation->contact;
        $name = trim(implode(' ', array_filter([
            $contact?->first_name,
            $contact?->last_name,
        ])));

        return $name ?: ($contact?->phone_e164 ?: 'A customer');
    }
}
