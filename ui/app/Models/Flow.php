<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Flow extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'code',
        'code_updated_at',
        'status',
        'container_id',
        'entrypoint',
        'image',
        'timezone',
        'active_chat_conversation_id',
        'last_started_at',
        'last_finished_at',
        'archived_at',
    ];

    protected $casts = [
        'code_updated_at' => 'datetime',
        'last_started_at' => 'datetime',
        'last_finished_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    protected $hidden = [
        'webhook_token_secret',
    ];

    protected static function booted(): void
    {
        static::creating(static function (Flow $flow): void {
            if (empty($flow->slug)) {
                $flow->slug = Str::slug($flow->name) ?: Str::uuid()->toString();
            }

            if (empty($flow->webhook_token_secret)) {
                $flow->webhook_token_secret = Str::random(40);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(FlowRun::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FlowLog::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(FlowHistory::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AgentConversation::class);
    }

    public function activeChatConversation(): BelongsTo
    {
        return $this->belongsTo(
            AgentConversation::class,
            'active_chat_conversation_id',
            'id',
        );
    }

    public function activeRun(string $type): ?FlowRun
    {
        return $this->runs()
            ->where('type', $type)
            ->where('active', true)
            ->latest()
            ->first();
    }

    public function hasActiveDeploys(): bool
    {
        return $this->runs()->where('active', true)->exists();
    }

    public function hadProductionDeploy(): bool
    {
        return $this->runs()->where('type', 'production')->exists();
    }

    public function scopeForUser(Builder $query, User $user): void
    {
        if ($user->can('view-all', self::class)) {
            return;
        }

        $query->where('user_id', $user->id);
    }
}
