<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Workspace extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Days an owner can restore a deleted workspace before it is erased. */
    public const RESTORE_DAYS = 30;

    private const DELETED_IDS_CACHE_KEY = 'workspaces.deleted_ids';

    /**
     * Per-process copy of the deleted ids, re-read at most every 30 seconds.
     *
     * @var list<int>|null
     */
    private static ?array $deletedIdsMemo = null;

    private static int $deletedIdsMemoUntil = 0;

    private static bool $softDeletesReady = false;

    protected $table = 'workspaces';

    protected $fillable = [
        'owner_id',
        'client_id',
        'name',
        'default_locale',
        'currency_code',
        'deleted_by_user_id',
    ];

    protected $attributes = [
        'default_locale' => 'en',
    ];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    /** When a deleted workspace is permanently erased. */
    public function purgeAfter(): ?CarbonInterface
    {
        return $this->deleted_at?->copy()->addDays(self::RESTORE_DAYS);
    }

    /**
     * Ids of deleted (not yet erased) workspaces. Records of these workspaces
     * are excluded from activity lookups (ExcludesDeletedWorkspaces), so
     * inbound webhooks, widgets and schedulers stop for them.
     *
     * @return list<int>
     */
    public static function deletedIds(): array
    {
        if (self::$deletedIdsMemo !== null && time() < self::$deletedIdsMemoUntil) {
            return self::$deletedIdsMemo;
        }

        // Older data migrations query gated models before `deleted_at` exists.
        if (! self::$softDeletesReady) {
            if (! Schema::hasColumn('workspaces', 'deleted_at')) {
                return [];
            }
            self::$softDeletesReady = true;
        }

        self::$deletedIdsMemo = array_map('intval', Cache::rememberForever(
            self::DELETED_IDS_CACHE_KEY,
            fn () => static::onlyTrashed()->pluck('id')->all(),
        ));
        self::$deletedIdsMemoUntil = time() + 30;

        return self::$deletedIdsMemo;
    }

    /** Call after a workspace is deleted, restored or erased. */
    public static function forgetDeletedIds(): void
    {
        Cache::forget(self::DELETED_IDS_CACHE_KEY);
        self::$deletedIdsMemo = null;
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Users who are members of this workspace (via pivot).
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Users whose primary workspace is this one. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'workspace_id');
    }

    /** Whether the given user can access this workspace (owner or member). */
    public function isAccessibleBy(User $user): bool
    {
        if ($this->owner_id === $user->id) {
            return true;
        }

        return $this->members()->where('user_id', $user->id)->exists();
    }
}
