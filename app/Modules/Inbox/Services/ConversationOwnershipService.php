<?php

namespace App\Modules\Inbox\Services;

use App\Events\ConversationActivityCreated;
use App\Events\ConversationOwnershipChanged;
use App\Events\WidgetHandoffUpdated;
use App\Models\User;
use App\Modules\Inbox\Exceptions\ConversationOwnershipException;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Carbon\CarbonInterface;
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
        $activity = null;
        $updated = $this->synchronized($conversation, fn () => DB::transaction(function () use ($conversation, $user, &$activity) {
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
                $activity = $this->activity($locked, $user, 'conversation.joined');
            }

            return $locked->fresh(['joinedUser', 'channelAccount']);
        }));

        $updated = $this->broadcast($updated);
        $this->broadcastActivity($activity);

        return $updated;
    }

    public function leave(Conversation $conversation, User $user): Conversation
    {
        $activity = null;
        $updated = $this->synchronized($conversation, fn () => DB::transaction(function () use ($conversation, $user, &$activity) {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->assertMember($locked, $user);
            if (! $locked->joined_user_id) {
                return $locked->fresh(['joinedUser', 'channelAccount']);
            }
            if ((int) $locked->joined_user_id !== (int) $user->id && ! $user->isClientAdministrator()) {
                throw new ConversationOwnershipException('Only the joined agent or an administrator can leave this chat.', 403);
            }
            $leavingUser = User::query()->find($locked->joined_user_id);
            $locked->update(['assigned_user_id' => null, 'joined_user_id' => null, 'joined_at' => null]);
            $activity = $this->activity(
                $locked,
                $user,
                'conversation.left',
                $leavingUser && (int) $leavingUser->id !== (int) $user->id
                    ? "{$user->name} removed {$leavingUser->name} from the chat"
                    : "{$user->name} left the chat",
                $leavingUser ? ['subject' => $this->actorSnapshot($leavingUser)] : [],
            );

            return $locked->fresh(['joinedUser', 'channelAccount']);
        }));

        $updated = $this->broadcast($updated);
        $this->broadcastActivity($activity);

        return $updated;
    }

    public function takeover(Conversation $conversation, User $user): Conversation
    {
        $activity = null;
        $updated = $this->synchronized($conversation, fn () => DB::transaction(function () use ($conversation, $user, &$activity) {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->assertMember($locked, $user);
            if ($locked->status === 'resolved') {
                throw new ConversationOwnershipException('Reopen the conversation before taking it over.');
            }
            if (! $locked->joined_user_id) {
                $updated = $this->joinLocked($locked, $user);
                $activity = $this->activity($locked, $user, 'conversation.joined');

                return $updated;
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

            $locked->loadMissing('joinedUser');
            $previous = $locked->joinedUser;
            $updated = $this->joinLocked($locked, $user);
            $activity = $this->activity(
                $locked,
                $user,
                'conversation.transferred',
                $previous
                    ? "{$user->name} took over the chat from {$previous->name}"
                    : "{$user->name} took over the chat",
                $previous ? ['previous_actor' => $this->actorSnapshot($previous)] : [],
            );

            return $updated;
        }));

        $updated = $this->broadcast($updated);
        $this->broadcastActivity($activity);

        return $updated;
    }

    public function assign(Conversation $conversation, ?User $assignee, User $actor): Conversation
    {
        $activity = null;
        $updated = $this->synchronized($conversation, fn () => DB::transaction(function () use ($conversation, $assignee, $actor, &$activity) {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->assertMember($locked, $actor);
            if ($assignee) {
                $this->assertMember($locked, $assignee);
            }

            $assigneeId = $assignee?->id;
            if ((int) $locked->assigned_user_id === (int) $assigneeId) {
                return $locked->fresh(['joinedUser', 'channelAccount']);
            }

            $updates = ['assigned_user_id' => $assigneeId];
            if ($assignee) {
                $updates += ['assigned_to' => 'human', 'ai_paused_at' => now(), 'ai_pause_reason' => 'assigned'];
            }
            if ((int) $locked->joined_user_id !== (int) $assigneeId) {
                $updates += ['joined_user_id' => null, 'joined_at' => null];
            }
            $locked->update($updates);

            $activity = $this->activity(
                $locked,
                $actor,
                $assignee ? 'conversation.assigned' : 'conversation.unassigned',
                $assignee
                    ? "{$actor->name} assigned the chat to {$assignee->name}"
                    : "{$actor->name} unassigned the chat",
                $assignee ? ['subject' => $this->actorSnapshot($assignee)] : [],
            );

            return $locked->fresh(['joinedUser', 'channelAccount']);
        }));

        $this->broadcastActivity($activity);

        return $updated;
    }

    public function changeStatus(Conversation $conversation, string $status, User $actor): Conversation
    {
        if ($status === 'resolved') {
            return $this->resolve($conversation, $actor);
        }

        $activity = null;
        $updated = $this->synchronized($conversation, fn () => DB::transaction(function () use ($conversation, $status, $actor, &$activity) {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->assertMember($locked, $actor);
            if ($locked->status === $status) {
                return $locked->fresh(['joinedUser', 'channelAccount']);
            }

            $previousStatus = $locked->status;
            $locked->update(['status' => $status, 'resolved_at' => null]);
            $type = match ($status) {
                'pending' => 'conversation.pending',
                'snoozed' => 'conversation.snoozed',
                'open' => 'conversation.reopened',
                default => null,
            };
            if ($type) {
                $body = match ($type) {
                    'conversation.pending' => "{$actor->name} marked the chat as pending",
                    'conversation.snoozed' => "{$actor->name} snoozed the chat",
                    default => "{$actor->name} reopened the chat",
                };
                $activity = $this->activity($locked, $actor, $type, $body, ['previous_status' => $previousStatus]);
            }

            return $locked->fresh(['joinedUser', 'channelAccount']);
        }));

        $this->broadcastActivity($activity);

        return $updated;
    }

    public function resolve(Conversation $conversation, User $actor): Conversation
    {
        $activity = null;
        $updated = $this->synchronized($conversation, fn () => DB::transaction(function () use ($conversation, $actor, &$activity) {
            $locked = Conversation::query()->with('channelAccount')->lockForUpdate()->findOrFail($conversation->id);
            $this->assertMember($locked, $actor);
            if ($locked->status === 'resolved') {
                return $locked->fresh(['joinedUser', 'channelAccount']);
            }
            $locked->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'unread_count' => 0,
                'assigned_user_id' => null,
                'joined_user_id' => null,
                'joined_at' => null,
                'handover_at' => null,
                'assigned_to' => $this->initialHandler($locked),
                'ai_paused_at' => null,
                'ai_pause_reason' => null,
            ]);
            $activity = $this->activity($locked, $actor, 'conversation.resolved');

            return $locked->fresh(['joinedUser', 'channelAccount']);
        }));

        $updated = $this->broadcast($updated);
        $this->broadcastActivity($activity);

        return $updated;
    }

    public function releaseUser(int $userId, ?int $workspaceId = null, ?User $actor = null): void
    {
        $releasedUser = User::query()->findOrFail($userId);
        Conversation::query()
            ->where('joined_user_id', $userId)
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->get()
            ->each(function (Conversation $conversation) use ($userId, $releasedUser, $actor): void {
                $activity = null;
                $updated = $this->synchronized($conversation, function () use ($conversation, $userId, $releasedUser, $actor, &$activity): Conversation {
                    return DB::transaction(function () use ($conversation, $userId, $releasedUser, $actor, &$activity): Conversation {
                        $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
                        if ((int) $locked->joined_user_id !== $userId) {
                            return $locked->fresh(['joinedUser', 'channelAccount']);
                        }

                        $locked->update(['assigned_user_id' => null, 'joined_user_id' => null, 'joined_at' => null]);
                        $name = $releasedUser->name;
                        $activity = $this->activity(
                            $locked,
                            $actor,
                            'conversation.left',
                            $actor ? "{$actor->name} removed {$name} from the chat" : "{$name} left the chat",
                            ['subject' => $this->actorSnapshot($releasedUser)],
                        );

                        return $locked->fresh(['joinedUser', 'channelAccount']);
                    });
                });
                $this->broadcast($updated);
                $this->broadcastActivity($activity);
            });
    }

    /**
     * Clear stale ownership when an old resolved thread receives a new inbound.
     *
     * @return array<string, mixed>
     */
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
            'first_response_at' => null,
            'unanswered_reminder_sent_at' => null,
        ];
    }

    /**
     * Apply inbound conversation state before MessageReceived listeners run.
     *
     * @param  array<string, mixed>  $updates
     */
    public function prepareInbound(
        Conversation $conversation,
        ?CarbonInterface $receivedAt = null,
        int $unreadIncrement = 0,
        array $updates = [],
    ): bool {
        $reopened = false;
        $activity = null;
        $updated = $this->synchronized($conversation, function () use (
            $conversation,
            $receivedAt,
            $unreadIncrement,
            $updates,
            &$reopened,
            &$activity,
        ): Conversation {
            return DB::transaction(function () use (
                $conversation,
                $receivedAt,
                $unreadIncrement,
                $updates,
                &$reopened,
                &$activity,
            ): Conversation {
                $locked = Conversation::query()
                    ->with('channelAccount')
                    ->lockForUpdate()
                    ->findOrFail($conversation->id);

                if ($receivedAt) {
                    if (! $locked->last_message_at || $receivedAt->greaterThan($locked->last_message_at)) {
                        $updates['last_message_at'] = $receivedAt;
                    }
                    if (! $locked->last_inbound_at || $receivedAt->greaterThan($locked->last_inbound_at)) {
                        $updates['last_inbound_at'] = $receivedAt;
                    }
                }
                if ($unreadIncrement > 0) {
                    $updates['unread_count'] = (int) $locked->unread_count + $unreadIncrement;
                }
                if ($locked->status === 'resolved') {
                    $reopened = true;
                    $updates = array_merge($updates, $this->reopenUpdates($locked));
                    $activity = $this->activity(
                        $locked,
                        null,
                        'conversation.reopened',
                        'Chat reopened after a new customer message',
                        ['previous_status' => 'resolved'],
                    );
                }
                if ($updates !== []) {
                    $locked->update($updates);
                }

                return $locked->fresh(['joinedUser', 'channelAccount']);
            });
        });

        $conversation->setRawAttributes($updated->getAttributes(), true);
        $conversation->setRelations($updated->getRelations());

        if ($reopened) {
            $this->broadcast($updated);
            $this->broadcastActivity($activity);
        }

        return $reopened;
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

    /** @param array<string, mixed> $context */
    private function activity(
        Conversation $conversation,
        ?User $actor,
        string $type,
        ?string $body = null,
        array $context = [],
    ): Message {
        $body ??= $type === 'conversation.resolved'
            ? "Resolved by {$actor?->name}"
            : "{$actor?->name} joined the chat";
        $activity = ['type' => $type];
        if ($actor) {
            $activity['actor'] = $this->actorSnapshot($actor);
        }
        $activity = array_merge($activity, $context);

        return $conversation->messages()->create([
            'direction' => 'system',
            'channel' => $conversation->channelAccount()->value('channel') ?? 'system',
            'type' => 'event',
            'body' => $body,
            'payload' => ['activity' => $activity],
            'status' => 'delivered',
            'sent_by' => 'system',
            'user_id' => $actor?->id,
            'sent_at' => now(),
        ]);
    }

    /** @return array{id:int,name:string} */
    private function actorSnapshot(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name];
    }

    private function broadcastActivity(?Message $activity): void
    {
        if ($activity) {
            ConversationActivityCreated::dispatch($activity->load(['conversation.channelAccount', 'sender']));
        }
    }

    /** @return array{id:int,name:string,avatar:mixed}|null */
    private function publicUser(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar] : null;
    }

    public function synchronized(Conversation $conversation, callable $callback): mixed
    {
        return Cache::lock('conversation-ai-reply:'.$conversation->id, 150)->block(30, $callback);
    }
}
