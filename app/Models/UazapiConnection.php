<?php

namespace App\Models;

use Database\Factories\UazapiConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UazapiConnection extends Model
{
    /** @use HasFactory<UazapiConnectionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['instance_token' => 'encrypted', 'connected_at' => 'datetime', 'provider_checked_at' => 'datetime', 'chat_import_dispatched_at' => 'datetime', 'chat_last_reconciled_at' => 'datetime', 'chat_last_webhook_at' => 'datetime', 'chat_import_offset' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }
}
