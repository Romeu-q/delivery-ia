<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'conversation_id', 'role', 'content', 'meta', 'status',
        'model', 'prompt_tokens', 'output_tokens', 'cost_usd', 'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'cost_usd' => 'float',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
