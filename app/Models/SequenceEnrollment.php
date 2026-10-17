<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenceEnrollment extends Model
{
    public const TERMINAL = ['completed', 'replaced', 'failed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'running_since' => 'datetime', 'enrolled_at' => 'datetime',
            'finished_at' => 'datetime', 'blocked_reasons' => 'array'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(SequenceVersion::class, 'sequence_version_id');
    }

    public function nextStep(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class, 'next_step_id');
    }
}
