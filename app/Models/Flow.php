<?php

namespace App\Models;

use App\Services\Flows\FlowAssetCleanup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flow extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $flow) {
            if ($flow->isDirty('graph')) {
                $cleanup = app(FlowAssetCleanup::class);
                $ids = $cleanup->ids($flow->graph);
                $available = FlowAsset::where('flow_id', $flow->id)->whereIn('id', $ids)->pluck('id')->all();
                abort_if(array_diff($ids, $available) !== [], 409, 'Uma mídia foi removida. Recarregue o editor.');
                $cleanup->request($flow, array_diff($cleanup->ids($flow->getOriginal('graph') ?? []), $ids));
            }
        });
        static::deleting(function (self $flow) {
            app(FlowAssetCleanup::class)->request($flow, FlowAsset::where('flow_id', $flow->id)->pluck('id')->all());
        });
    }

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
