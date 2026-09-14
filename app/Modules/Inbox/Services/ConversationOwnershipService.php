<?php

namespace App\Modules\Inbox\Services;

use App\Events\ConversationOwnershipChanged;
use App\Events\WidgetHandoffUpdated;
use App\Models\User;
use App\Modules\Inbox\Exceptions\ConversationOwnershipException;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConversationOwnershipService
{
    public function __construct(
        private readonly TeamAvailabilityService $availability,
        private readonly SegmentAiPolicyService $aiPolicy,
    ) {}

    public function join(Conversation $conversation, User $user): Conversation
    {
        $updated = $this->synchronized($conversation, fn () => DB::transaction(function () use ($conversation, $user) {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->assertMember($locked, $user);

            if ($locked->status === 'resolved') {
                throw new ConversationOwnershipException('Reopen the conversation before joining it.');
            }
            if ($locked->joined_user_id && (int) $locked->joined_user_id !== (int) $user->id) {
                $locked->load('joinedUser');
                throw new ConversationOwnershipException('This chat has already been joined.', 409, [
                    'joined_user' => $this->publicUser($locked->joinedUser),
                ]);
            }
            if (! $locked->joined_user_id) {
                $locked->update([
                    'assigned_user_id' => $user->id,
                    'joined_user_id' => $user->id,
                    'joined_at' => now(),
                    'assigned_to' => 'human',
                    'ai_paused_at' => now(),
                    'ai_pause_reason' => 'joined',
                    'status' => 'open',
                ]);
            }

            return $locked->fresh(['joinedUser', 'channelAccount']);
        }));

        return $this->broadcast($updated);
    }

    public function leave(Conversation $conversation, User $user): Conversation
    {
        $updated = $this->synchronized($conversation, fn () => DB::transaction(function () use ($conversation, $user) {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->assertMember($locked, $user);
            if (! $locked->joined_user_id) {
                return $locked->fresh(['joinedUser', 'channelAccount']);
            }
            if ($locked->joined_user_id && (int) $locked->joined_user_id !== (int) $user->id && ! $user->isClientAdministrator()) {
                throw new ConversationOwnershipException('Only the joined agent or an administrator can leave this chat.', 403);
            }
            $locked->update(['assigned_user_id' => null, 'joined_user_id' => null, 'joined_at' => null]);

            return $locked->fresh(['joinedUser', 'channelAccount']);
        }));

        return $this->broadcast($updated);
    }

    public function takeover(Conversation $conversation, User $user): Conversation
    {
        $updated = $this->synchronized($conversation, fn () => DB::transaction(function () use ($conversation, $user) {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->assertMember($locked, $user);
            if ($locked->status === 'resolved') {
                throw new ConversationOwnershipException('Reopen the conversation before taking it over.');
            }
            if (! $locked->joined_user_id) {
                return $this->joinLocked($locked, $user);
            }
            if ((int) $locked->joined_user_id === (int) $user->id) {
                return $locked->fresh(['joinedUser', 'channelAccount']);
            }
            if (! $user->isClientAdministrator()) {
                if (! $this->availability->isAvailable((int) $locked->workspace_id, $user)) {
                    throw new ConversationOwnershipException('You must be currently available to take over this chat.', 403);
                }
                if ($this->availability->isAvailable((int) $locked->workspace_id, (int) $locked->joined_user_id)) {
                    throw new ConversationOwnershipException('The joined agent is still available. Ask them or an administrator to transfer the chat.', 403);
                }
            }

            return $this->joinLocked($locked, $user);
        }));

        return $this->broadcast($updated);
    }

    public function resolve(Conversation $conversation): Conversation
    {
        $updated = $this->synchronized($conversation, fn () => DB::transaction(function () use ($conversation) {
            $locked = Conversation::query()->with('channelAccount')->lockForUpdate()->findOrFail($conversation->id);
            $locked->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'assigned_user_id' => null,
                'joined_user_id' => null,
                'joined_at' => null,
                'handover_at' => null,
                'assigned_to' => $this->initialHandler($locked),
                'ai_paused_at' => null,
                'ai_pause_reason' => null,
            ]);

            return $locked->fresh(['joinedUser', 'channelAccount']);
        }));

        return $this->broadcast($updated);
    }

    public function releaseUser(int $userId, ?int $workspaceId = null): void
    {
        Conversation::query()
            ->where('joined_user_id', $userId)
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->get()
            ->each(function (Conversation $conversation): void {
                $updated = $this->synchronized($conversation, function () use ($conversation): Conversation {
                    $conversation->refresh();
                    $conversation->update(['assigned_user_id' => null, 'joined_user_id' => null, 'joined_at' => null]);

                    return $conversation->fresh(['joinedUser', 'channelAccount']);
                });
                $this->broadcast($updated);
            });
    }

    /** Clear stale ownership when an old resolved thread receives a new inbound. */
    public function reopenUpdates(Conversation $conversation): array
    {
        if ($conversation->status !== 'resolved') {
            return ['status' => $conversation->status];
        }

        return [
            'status' => 'open',
            'resolved_at' => null,
            'assigned_user_id' => null,
            'joined_user_id' => null,
            'joined_at' => null,
            'handover_at' => null,
            'assigned_to' => $this->initialHandler($conversation),
            'ai_paused_at' => null,
            'ai_pause_reason' => null,
        ];
    }

    public function prepareInbound(Conversation $conversation): void
    {
        if ($conversation->status !== 'resolved') {
            return;
        }
        $updated = $this->synchronized($conversation, function () use ($conversation): Conversation {
            $conversation->refresh();
            if ($conversation->status === 'resolved') {
                $conversation->update($this->reopenUpdates($conversation));
            }

            return $conversation->fresh(['joinedUser', 'channelAccount']);
        });
        $this->broadcast($updated);
    }

    public function assertCanReply(Conversation $conversation, User $user): void
    {
        if ((int) $conversation->joined_user_id !== (int) $user->id) {
            throw new ConversationOwnershipException('Join this chat before replying.', 409, [
                'joined_user' => $this->publicUser($conversation->joinedUser),
            ]);
        }
    }

    private function joinLocked(Conversation $conversation, User $user): Conversation
    {
        $conversation->update([
            'assigned_user_id' => $user->id,
            'joined_user_id' => $user->id,
            'joined_at' => now(),
            'assigned_to' => 'human',
            'ai_paused_at' => now(),
            'ai_pause_reason' => 'joined',
            'status' => 'open',
        ]);

        return $conversation->fresh(['joinedUser', 'channelAccount']);
    }

    private function assertMember(Conversation $conversation, User $user): void
    {
        abort_unless($conversation->workspace?->isAccessibleBy($user), 403);
    }

    private function initialHandler(Conversation $conversation): string
    {
        $widget = ChatWidget::query()->where('channel_account_id', $conversation->channel_account_id)->first();

        if ($widget) {
            return $widget->hasActiveAiChatbot() ? 'bot' : 'human';
        }

        $account = $conversation->relationLoaded('channelAccount')
            ? $conversation->channelAccount
            : $conversation->channelAccount()->first();

        return $account ? $this->aiPolicy->initialHandler($account) : 'human';
    }

    private function broadcast(Conversation $conversation): Conversation
    {
        ConversationOwnershipChanged::dispatch($conversation);
        $widget = ChatWidget::query()->where('channel_account_id', $conversation->channel_account_id)->first();
        if ($widget) {
            WidgetHandoffUpdated::dispatch($conversation->id, app(WidgetPayloadBuilder::class)->handoff($widget, $conversation));
        }

        return $conversation;
    }

    private function publicUser(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar] : null;
    }

    public function synchronized(Conversation $conversation, callable $callback): mixed
    {
        return Cache::lock('conversation-ai-reply:'.$conversation->id, 150)->block(30, $callback);
    }
}
