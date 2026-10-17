<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowDraftSession extends Model
{
    protected $guarded = [];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    protected function casts(): array
    {
        return ['graph' => 'array'];
    }
}
