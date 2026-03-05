<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'flow_id',
        'type',
        'active',
        'status',
        'container_id',
        'meta',
        'lock',
        'actors',
        'events',
        'code_snapshot',
        'graph_snapshot',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'active' => 'boolean',
        'lock' => 'string',
        'actors' => 'array',
        'events' => 'array',
        'code_snapshot' => 'string',
        'graph_snapshot' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FlowLog::class);
    }
}
