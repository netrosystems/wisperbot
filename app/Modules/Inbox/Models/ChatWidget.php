<?php

namespace App\Modules\Inbox\Models;

use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'primary_color', 'position', 'launcher_text',
        'ai_enabled', 'ai_chatbot_id', 'require_prechat', 'prechat_fields',
        'offline_message', 'allowed_domains', 'working_hours_json', 'enabled',
        'identity_verification', 'identity_secret',
    ];

    protected $hidden = ['identity_secret'];

    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
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

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    /** Public theming/config surfaced to the embed script + widget UI. */
    public function publicConfig(): array
    {
        return [
            'key' => $this->widget_key,
            'title' => $this->title ?: 'Chat with us',
            'subtitle' => $this->subtitle ?: 'We typically reply in a few minutes',
            'welcome_message' => $this->welcome_message ?: 'Hi there 👋 How can we help?',
            'agent_name' => $this->agent_name ?: 'Support',
            'avatar_url' => $this->avatar_url,
            'primary_color' => $this->primary_color ?: '#ff762e',
            'position' => $this->position ?: 'bottom_right',
            'launcher_text' => $this->launcher_text,
            'require_prechat' => (bool) $this->require_prechat,
            'prechat_fields' => $this->prechat_fields ?: ['name', 'email'],
            'offline_message' => $this->offline_message,
        ];
    }
}
