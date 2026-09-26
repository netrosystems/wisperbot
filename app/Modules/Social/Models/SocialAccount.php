<?php

namespace App\Modules\Social\Models;

use App\Models\Concerns\ExcludesDeletedWorkspaces;
use App\Modules\Social\Services\SocialTokenRefresher;
use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed>|null $meta */
class SocialAccount extends Model
{
    use ExcludesDeletedWorkspaces;

    protected $table = 'social_media_accounts';

    protected $fillable = ['workspace_id', 'network', 'account_id', 'name', 'picture_url', 'access_token', 'refresh_token', 'token_expires_at', 'scopes', 'meta', 'active'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'meta' => 'array',
            'active' => 'boolean',
            'token_expires_at' => 'datetime',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    public function posts()
    {
        return $this->belongsToMany(SocialPost::class, 'social_media_post_accounts', 'social_account_id', 'post_id');
    }

    public function isTokenExpired(): bool
    {
        if ($this->refreshesOnUse()) {
            return false;
        }

        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    /**
     * YouTube, TikTok, X and (when LinkedIn grants one) LinkedIn access tokens
     * are renewed with the refresh token before use, so a stale access token
     * alone does not mean "reconnect".
     */
    public function refreshesOnUse(): bool
    {
        return SocialTokenRefresher::renews($this);
    }

    /** Days left before a connection that cannot be renewed must be reconnected. */
    public function daysUntilReconnect(): ?int
    {
        if ($this->refreshesOnUse() || ! $this->token_expires_at || $this->token_expires_at->isPast()) {
            return null;
        }

        return (int) max(0, ceil(now()->diffInSeconds($this->token_expires_at) / 86400));
    }
}
