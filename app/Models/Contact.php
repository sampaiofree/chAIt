<?php

namespace App\Models;

use App\Services\Chat\WhatsAppJid;
use App\Services\Contacts\InternationalPhone;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (Contact $contact): void {
            if ($contact->isDirty('phone')) {
                $contact->phone = app(InternationalPhone::class)->normalize($contact->phone) ?? $contact->phone;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'profile' => 'array',
        ];
    }

    public function displayPhone(?string $externalChatId = null): ?string
    {
        $jids = app(WhatsAppJid::class);
        if (filled($this->phone)) {
            return $jids->phone($this->phone) ?? $this->phone;
        }

        return $jids->phone($externalChatId ?? $this->whatsAppAddress() ?? '');
    }

    public function whatsAppAddress(): ?string
    {
        $jids = app(WhatsAppJid::class);
        foreach ($this->channels->sortBy('id') as $channel) {
            if ($channel->workspace_id === $this->workspace_id && $channel->type === 'whatsapp' && $jids->isIndividual($channel->external_id)) {
                return $channel->external_id;
            }
        }
        $phone = app(InternationalPhone::class)->normalize($this->phone);

        return $phone ? $phone.'@s.whatsapp.net' : null;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(ContactChannel::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag')->withPivot('workspace_id')->withTimestamps();
    }

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(ContactCustomFieldValue::class);
    }
}
