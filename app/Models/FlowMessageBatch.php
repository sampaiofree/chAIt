<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowMessageBatch extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return ['entries' => 'array', 'due_at' => 'datetime', 'cutoff_at' => 'datetime', 'sealed' => 'boolean', 'started_at' => 'datetime'];
    }
}
