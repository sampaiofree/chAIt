<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sequence extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['draft' => 'array', 'is_active' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SequenceVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(SequenceVersion::class, 'published_version_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(SequenceEnrollment::class);
    }
}
