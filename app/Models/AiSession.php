<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSession extends Model
{
    protected $guarded = [];

    protected $hidden = ['cleanup_api_key'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'ended_at' => 'datetime', 'cleanup_completed_at' => 'datetime', 'cleanup_api_key' => 'encrypted'];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(FlowExecution::class, 'flow_execution_id');
    }
}
