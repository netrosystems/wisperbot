<?php

namespace App\Modules\Social\Models;

use App\Models\Concerns\ExcludesDeletedWorkspaces;
use Illuminate\Database\Eloquent\Model;

class SocialPost extends Model
{
    use ExcludesDeletedWorkspaces;

    protected $table = 'social_media_posts';

    protected $fillable = ['workspace_id', 'title', 'body', 'media_urls', 'network_content', 'target_accounts', 'status', 'scheduled_at', 'timezone', 'published_at', 'provider_post_id', 'post_url', 'publish_results', 'ai_generated', 'ai_prompt'];

    protected function casts(): array
    {
        return [
            'media_urls' => 'array',
            'network_content' => 'array',
            'target_accounts' => 'array',
            'publish_results' => 'array',
            'ai_generated' => 'boolean',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * What to publish to one network: its customised version when the client
     * made one, otherwise the shared body and media.
     *
     * @return array{body: string, media_urls: list<string>}
     */
    public function contentFor(string $network): array
    {
        $custom = $this->network_content[$network] ?? null;
        if (is_array($custom) && isset($custom['body'])) {
            return [
                'body' => (string) $custom['body'],
                'media_urls' => array_values(array_filter((array) ($custom['media_urls'] ?? []))),
            ];
        }

        return [
            'body' => (string) $this->body,
            'media_urls' => array_values(array_filter((array) ($this->media_urls ?? []))),
        ];
    }

    public function accountLinks()
    {
        return $this->hasMany(SocialPostAccount::class, 'post_id');
    }

    public function accounts()
    {
        return $this->belongsToMany(SocialAccount::class, 'social_media_post_accounts', 'post_id', 'social_account_id');
    }
}
