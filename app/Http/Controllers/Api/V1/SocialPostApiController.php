<?php

namespace App\Http\Controllers\Api\V1;

use App\Modules\Social\Services\SocialMediaRules;
use App\Modules\Social\Services\XPostContent;
use Illuminate\Validation\ValidationException;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialPostApiController extends WorkspaceScopedController
{
    /**
     * GET /api/v1/social/accounts
     */
    public function accounts(Request $request): JsonResponse
    {
        $accounts = SocialAccount::where('workspace_id', $this->workspaceId($request))
            ->where('active', true)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'network' => $a->network,
                'name' => $a->name,
                'picture_url' => $a->picture_url,
                'created_at' => $a->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $accounts]);
    }

    /**
     * POST /api/v1/social/posts
     * Schedule or immediately publish a post.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'title' => ['nullable', 'string', 'max:256'],
            'media_urls' => ['nullable', 'array', 'max:10'],
            'media_urls.*' => ['url', 'regex:/^https:\/\//i', 'max:2048'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            ...XPostContent::RULES,
        ]);

        $wsId = $this->workspaceId($request);

        // Verify all account IDs belong to this workspace
        $accountCount = SocialAccount::where('workspace_id', $wsId)
            ->whereIn('id', $validated['account_ids'])
            ->count();

        if ($accountCount !== count($validated['account_ids'])) {
            return response()->json(['error' => 'One or more account_ids are invalid.'], 422);
        }

        // X: no links, 280 weighted characters, up to 3 images or 1 video.
        // An optional `network_content.twitter` version lets only X follow them.
        $networks = SocialAccount::where('workspace_id', $wsId)->whereIn('id', $validated['account_ids'])->pluck('network')->unique();
        if (($mediaErrors = app(SocialMediaRules::class)->errors($networks, (array) ($validated['media_urls'] ?? []))) !== []) {
            throw ValidationException::withMessages(['media_urls' => $mediaErrors]);
        }
        $networkContent = app(XPostContent::class)
            ->validate($networks, $validated['body'], array_filter((array) ($validated['media_urls'] ?? [])), $validated['network_content'] ?? null);

        $post = SocialPost::create([
            'workspace_id' => $wsId,
            'body' => $validated['body'],
            'title' => $validated['title'] ?? null,
            'media_urls' => $validated['media_urls'] ?? [],
            'network_content' => $networkContent,
            'target_accounts' => $validated['account_ids'],
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'status' => ! empty($validated['scheduled_at']) ? 'scheduled' : 'publishing',
        ]);

        if (empty($validated['scheduled_at'])) {
            PublishSocialPostJob::dispatch($post->id)->onQueue('social');
        }

        return response()->json([
            'id' => $post->id,
            'status' => $post->status,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'created_at' => $post->created_at->toIso8601String(),
        ], 201);
    }
}
