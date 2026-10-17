<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactCustomFieldValue extends Model
{
    protected $guarded = [];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ContactCustomField::class, 'field_id');
    }
}
