<?php

namespace App\Modules\Inbox\Models;

use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Services\WeeklySchedule;
use App\Modules\Shared\Models\ChannelAccount;
use App\Services\PusherPublicConfig;
use App\Services\StorageManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A website live-chat widget. Owns one `webchat` channel_account and stores the
 * theming + behaviour served to the embeddable script and used by the inbox.
 */
class ChatWidget extends Model
{
    protected $table = 'chat_widgets';

    protected $fillable = [
        'workspace_id', 'channel_account_id', 'widget_key', 'name',
        'title', 'subtitle', 'welcome_message', 'agent_name', 'avatar_url',
        'primary_color', 'position', 'launcher_text', 'footer_company_name',
        'launcher_logo_path', 'launcher_logo_disk',
        'ai_enabled', 'ai_chatbot_id', 'ai_schedule_json', 'require_prechat', 'prechat_fields',
        'offline_message', 'allowed_domains', 'working_hours_json', 'enabled',
        'identity_verification', 'identity_secret',
    ];

    protected $hidden = ['identity_secret'];

    protected $appends = ['launcher_logo_url'];

    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
            'ai_schedule_json' => 'array',
            'require_prechat' => 'boolean',
            'enabled' => 'boolean',
            'identity_verification' => 'boolean',
            'prechat_fields' => 'array',
            'allowed_domains' => 'array',
            'working_hours_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->widget_key)) {
                $model->widget_key = Str::random(32);
            }
            if (empty($model->identity_secret)) {
                $model->identity_secret = Str::random(48);
            }
        });
    }

    /** @return BelongsTo<ChannelAccount, $this> */
    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<AiChatbot, $this> */
    public function aiChatbot(): BelongsTo
    {
        return $this->belongsTo(AiChatbot::class, 'ai_chatbot_id');
    }

    public function hasActiveAiChatbot(): bool
    {
        if (! $this->ai_enabled || ! $this->ai_chatbot_id) {
            return false;
        }

        $chatbot = $this->relationLoaded('aiChatbot')
            ? $this->aiChatbot
            : $this->aiChatbot()->first();

        return (bool) $chatbot
            && $chatbot->enabled
            && (int) $chatbot->workspace_id === (int) $this->workspace_id;
    }

    public function shouldAiAnswerNow(?CarbonInterface $at = null): bool
    {
        if (! $this->hasActiveAiChatbot()) {
            return false;
        }

        $schedule = $this->ai_schedule_json;
        if (empty($schedule['enabled'])) {
            return true;
        }

        if (($schedule['mode'] ?? null) === 'permanent') {
            return true;
        }
        if (($schedule['mode'] ?? null) === 'scheduled') {
            return app(WeeklySchedule::class)->contains($schedule, $at);
        }

        $timezone = (string) ($schedule['timezone'] ?? '');
        $mode = (string) ($schedule['mode'] ?? '');
        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)
            || ! in_array($mode, ['outside_hours', 'inside_hours'], true)) {
            return false;
        }

        $now = CarbonImmutable::instance($at ?? now())->setTimezone($timezone);
        $day = strtolower($now->format('D'));
        $hours = $schedule['schedule'][$day] ?? null;

        // A disabled day means the business is closed all day. AI therefore
        // runs all day in outside-hours mode and rests in inside-hours mode.
        if (! is_array($hours) || empty($hours['enabled'])) {
            return $mode === 'outside_hours';
        }

        $start = $this->scheduleMinutes($hours['start'] ?? null);
        $end = $this->scheduleMinutes($hours['end'] ?? null);
        if ($start === null || $end === null || $start >= $end) {
            return false;
        }

        $current = ((int) $now->format('H') * 60) + (int) $now->format('i');
        $inside = $current >= $start && $current < $end;

        return $mode === 'inside_hours' ? $inside : ! $inside;
    }

    private function scheduleMinutes(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $value));

        return ($hours * 60) + $minutes;
    }

    public function getLauncherLogoUrlAttribute(): ?string
    {
        if (! $this->launcher_logo_path || ! $this->canUseCustomLauncherLogo()) {
            return null;
        }

        $disk = $this->launcher_logo_disk ?: app(StorageManager::class)->diskName();

        return $this->browserSafePublicUrl(
            Storage::disk($disk)->url($this->launcher_logo_path)
        );
    }

    private function canUseCustomLauncherLogo(): bool
    {
        $plan = $this->workspace?->client?->effectivePlan()
            ?: $this->workspace?->owner?->effectiveSubscription()?->plan;

        return (bool) $plan?->hasFeature('white_label');
    }

    /** Public theming/config surfaced to the embed script + widget UI. */
    public function publicConfig(): array
    {
        $launcherLogoUrl = $this->launcher_logo_url
            ?: $this->browserSafePublicUrl(url('/wisperbot-icon-white.svg'));
        $teamMembers = $this->publicTeamMembers();

        return [
            'key' => $this->widget_key,
            'title' => $this->title ?: 'Chat with us',
            'subtitle' => $this->subtitle ?: 'We typically reply in a few minutes',
            'welcome_message' => $this->welcome_message ?: 'Hi there 👋 How can we help?',
            'agent_name' => $this->agent_name ?: 'Support',
            // Keep the agent/header avatar aligned with the launcher branding.
            // Updating the paid custom icon therefore updates both surfaces.
            'avatar_url' => $launcherLogoUrl,
            'primary_color' => $this->primary_color ?: '#ff762e',
            'position' => $this->position ?: 'bottom_right',
            'launcher_text' => $this->launcher_text,
            // Every plan can use its own brand in the embedded widget. Existing
            // widgets retain the familiar WisperBot fallback until edited.
            'footer_company_name' => $this->footer_company_name ?: 'WisperBot',
            // The product icon remains the default for every free widget.
            // A custom launcher mark is only exposed for white-label plans.
            'launcher_logo_url' => $launcherLogoUrl,
            'team_members' => $teamMembers,
            'team_member_count' => count($teamMembers),
            // Only expose whether AI is active; never expose the internal bot id.
            'ai_enabled' => $this->shouldAiAnswerNow(),
            'require_prechat' => (bool) $this->require_prechat,
            'prechat_fields' => $this->prechat_fields ?: ['name', 'email'],
            'offline_message' => $this->offline_message,
            'realtime' => app(PusherPublicConfig::class)->widget(),
        ];
    }

    /**
     * Storage's local disk URL is based on APP_URL, which can remain http://
     * behind a TLS-terminating proxy. Never send that mixed-content URL to an
     * HTTPS widget request. External storage/CDN hosts are left untouched.
     */
    private function browserSafePublicUrl(?string $url): ?string
    {
        if (! $url || ! str_starts_with(strtolower($url), 'http://')) {
            return $url;
        }

        $assetHost = parse_url($url, PHP_URL_HOST);
        $requestHost = request()->getHost();
        $shouldUseHttps = request()->isSecure() || app()->environment('production');

        if ($shouldUseHttps && $assetHost && strcasecmp($assetHost, $requestHost) === 0) {
            return 'https://'.substr($url, strlen('http://'));
        }

        return $url;
    }

    /**
     * Small, public-safe team presence summary for the widget header.
     *
     * @return array<int, array{name:string,avatar_url:?string}>
     */
    private function publicTeamMembers(): array
    {
        $workspace = $this->workspace()
            ->with(['owner', 'members', 'users'])
            ->first();

        if (! $workspace) {
            return [];
        }

        return collect([$workspace->owner])
            ->merge($workspace->members)
            ->merge($workspace->users)
            ->filter(fn ($user) => $user && $user->status === 'active')
            ->unique('id')
            ->take(5)
            ->map(fn ($user) => [
                'name' => $user->name,
                'avatar_url' => $user->avatarUrl(),
            ])
            ->values()
            ->all();
    }
}
