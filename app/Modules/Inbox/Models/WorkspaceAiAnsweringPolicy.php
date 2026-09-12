<?php

namespace App\Modules\Inbox\Models;

use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceAiAnsweringPolicy extends Model
{
    public const SEGMENTS = ['omni', 'email'];

    protected $fillable = [
        'workspace_id', 'segment', 'mode', 'chatbot_id', 'schedule_json',
        'enabled_at', 'requires_review',
    ];

    protected function casts(): array
    {
        return [
            'chatbot_id' => 'integer',
            'schedule_json' => 'array',
            'enabled_at' => 'datetime',
            'requires_review' => 'boolean',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<AiChatbot, $this> */
    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(AiChatbot::class, 'chatbot_id');
    }
}
