<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowStorage extends Model
{
    /** @use HasFactory<\Database\Factories\FlowStorageFactory> */
    use HasFactory;

    protected $fillable = [
        'flow_id',
        'environment',
        'content',
    ];

    protected $casts = [
        'content' => 'array',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
