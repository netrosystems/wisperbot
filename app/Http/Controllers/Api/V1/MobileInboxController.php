<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Modules\Inbox\Models\CannedReply;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileInboxController extends WorkspaceScopedController
{
    /**
     * GET /api/v1/mobile/inbox/setup
     * Single bootstrapping call: labels, canned replies, channel accounts, team members.
     */
    public function setup(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        $labels = InboxLabel::where('workspace_id', $wsId)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        $cannedReplies = CannedReply::where('workspace_id', $wsId)
            ->orderBy('shortcut')
            ->get(['id', 'shortcut', 'body']);

        $channelAccounts = ChannelAccount::where('workspace_id', $wsId)
            ->where('status', 'active')
            ->orderBy('channel')
            ->orderBy('display_name')
            ->get(['id', 'channel', 'display_name', 'phone_number_id']);

        $teamMembers = User::where('workspace_id', $wsId)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json([
            'labels' => $labels,
            'canned_replies' => $cannedReplies,
            'channel_accounts' => $channelAccounts->map(fn ($ca) => [
                'id' => $ca->id,
                'channel' => $ca->channel,
                'display_name' => $ca->display_name,
                'phone_number_id' => $ca->phone_number_id,
            ]),
            'team_members' => $teamMembers->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ]),
        ]);
    }

    /**
     * GET /api/v1/mobile/inbox/templates?conversation_uuid=xxx
     * WhatsApp approved templates for the active workspace.
     */
    public function templates(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        $templates = WhatsappTemplate::where('workspace_id', $wsId)
            ->where('status', 'APPROVED')
            ->orderBy('name')
            ->get(['id', 'name', 'language', 'category', 'components']);

        return response()->json([
            'data' => $templates->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'language' => $t->language,
                'category' => $t->category,
                'components' => $t->components,
            ]),
        ]);
    }

    /**
     * GET /api/v1/mobile/inbox/labels
     */
    public function labels(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);
        $labels = InboxLabel::where('workspace_id', $wsId)->orderBy('name')->get(['id', 'name', 'color']);

        return response()->json(['data' => $labels]);
    }

    /**
     * GET /api/v1/mobile/inbox/canned-replies?search=xxx
     */
    public function cannedReplies(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);
        $query = CannedReply::where('workspace_id', $wsId);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('shortcut', 'like', '%'.$request->search.'%')
                    ->orWhere('body', 'like', '%'.$request->search.'%');
            });
        }

        $replies = $query->orderBy('shortcut')->get(['id', 'shortcut', 'body']);

        return response()->json(['data' => $replies]);
    }

    /**
     * GET /api/v1/mobile/contacts/search?q=xxx
     */
    public function contactSearch(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);
        $q = $request->input('q', '');

        $contacts = Contact::where('workspace_id', $wsId)
            ->withCount([
                'conversations as has_messenger_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'messenger')),
                'conversations as has_instagram_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'instagram')),
                'conversations as has_telegram_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'telegram')),
                'conversations as has_ebay_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'ebay')),
                'conversations as has_amazon_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'amazon')),
                'conversations as has_webchat_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'webchat')),
                'conversations as has_email_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'email')),
                'conversations as has_whatsapp_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'whatsapp')),
            ])
            ->where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('phone_e164', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            })
            ->orderBy('first_name')
            ->limit(30)
            ->get(['id', 'uuid', 'first_name', 'last_name', 'phone_e164', 'email', 'avatar', 'custom_fields', 'source']);

        return response()->json([
            'data' => $contacts->map(fn ($c) => [
                'id' => $c->id,
                'uuid' => $c->uuid,
                'name' => Demo::name($c->name),
                'phone' => Demo::phone($c->phone_e164),
                'phone_e164' => $c->phone_e164,
                'email' => Demo::email($c->email),
                'avatar' => Demo::active() ? null : $c->avatar_url,
                'custom_fields' => $c->custom_fields,
                'source' => $c->source,
                'has_messenger_thread' => (int) ($c->has_messenger_thread ?? 0) > 0,
                'has_instagram_thread' => (int) ($c->has_instagram_thread ?? 0) > 0,
                'has_telegram_thread' => (int) ($c->has_telegram_thread ?? 0) > 0,
                'has_ebay_thread' => (int) ($c->has_ebay_thread ?? 0) > 0,
                'has_amazon_thread' => (int) ($c->has_amazon_thread ?? 0) > 0,
                'has_webchat_thread' => (int) ($c->has_webchat_thread ?? 0) > 0,
                'has_email_thread' => (int) ($c->has_email_thread ?? 0) > 0,
                'has_whatsapp_thread' => (int) ($c->has_whatsapp_thread ?? 0) > 0,
            ]),
        ]);
    }

    /**
     * GET /api/v1/mobile/contacts/{id}
     */
    public function contact(Request $request, int $id): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        $contact = Contact::where('workspace_id', $wsId)->findOrFail($id);

        $conversations = $contact->conversations()
            ->with(['channelAccount', 'assignedUser'])
            ->orderByDesc('last_message_at')
            ->limit(10)
            ->get(['id', 'uuid', 'status', 'channel_account_id', 'assigned_user_id', 'assigned_to', 'last_message_at', 'unread_count']);

        return response()->json([
            'id' => $contact->id,
            'name' => Demo::name($contact->name),
            'phone' => Demo::phone($contact->phone_e164),
            'email' => Demo::email($contact->email),
            'avatar' => Demo::active() ? null : $contact->avatar_url,
            'custom_fields' => Demo::active()
                ? Demo::maskArrayValues($contact->custom_fields ?? [])
                : ($contact->custom_fields ?? []),
            'created_at' => $contact->created_at->toIso8601String(),
            'conversations' => $conversations->map(fn ($c) => [
                'id' => $c->id,
                'uuid' => $c->uuid,
                'status' => $c->status,
                'channel' => $c->channelAccount?->channel,
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'unread_count' => $c->unread_count,
                'assigned_user_id' => $c->assigned_user_id,
                'assigned_to' => $c->assigned_to,
                'assigned_user' => $c->assignedUser ? [
                    'id' => $c->assignedUser->id,
                    'name' => $c->assignedUser->name,
                    'avatar' => $c->assignedUser->avatar ?? null,
                ] : null,
            ]),
        ]);
    }
}
