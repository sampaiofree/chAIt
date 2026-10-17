<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowAiRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['provider_items' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
