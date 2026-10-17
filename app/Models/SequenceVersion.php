<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SequenceVersion extends Model
{
    protected $guarded = [];

    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class)->orderBy('position');
    }
}
