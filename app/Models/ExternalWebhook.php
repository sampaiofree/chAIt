<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalWebhook extends Model
{
    protected $guarded = [];

    protected $hidden = ['endpoint_key', 'sample_payload'];

    protected function casts(): array
    {
        return [
            'mappings' => 'array', 'tag_rules' => 'array', 'payload_fields' => 'array',
            'sample_payload' => 'encrypted', 'sample_received_at' => 'datetime', 'capture_until' => 'datetime',
        ];
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExternalWebhookReceipt::class);
    }
}
