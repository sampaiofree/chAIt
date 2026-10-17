<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowMenuWait extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['transport' => 'array', 'retry_queue' => 'array', 'sent_at' => 'datetime', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
