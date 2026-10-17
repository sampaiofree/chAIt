<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalWebhookReceipt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['warnings' => 'array'];
    }
}
