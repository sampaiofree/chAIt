<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowExecution extends Model
{
    protected $guarded = [];

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
