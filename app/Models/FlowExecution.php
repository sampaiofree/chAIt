<?php

namespace App\Models;

use App\Services\Flows\FlowAssetCleanup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class FlowExecution extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updated(function (self $execution) {
            if ($execution->wasChanged('status')) {
                DB::afterCommit(fn () => app(FlowAssetCleanup::class)->run($execution->flow_id));
            }
        });
    }

    protected function casts(): array
    {
        return ['context' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'flow_version_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(UazapiConnection::class, 'uazapi_connection_id');
    }
}
