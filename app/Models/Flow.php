<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['graph' => 'array', 'is_active' => 'boolean'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FlowFolder::class, 'folder_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'published_version_id');
    }
}
