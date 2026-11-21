<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class ContactIdentity
{
    public function findLocked(int $workspaceId, string $phone): ?Contact
    {
        $variants = app(ContactPhoneIdentity::class)->variants($phone);
        app(ContactReset::class)->lock($workspaceId, $phone.'@s.whatsapp.net');
        $contacts = Contact::where('workspace_id', $workspaceId)->whereIn('phone', $variants)->orderBy('id')->lockForUpdate()->get();

        return $contacts->firstWhere('phone', $phone) ?? $contacts->first();
    }

    /** Call inside the caller's transaction; the returned contact is locked. */
    public function locked(int $workspaceId, string $phone): Contact
    {
        if ($contact = $this->findLocked($workspaceId, $phone)) {
            return $contact;
        }
        $canonical = app(ContactPhoneIdentity::class)->variants($phone)[0];
        DB::table('contacts')->insertOrIgnore([
            'workspace_id' => $workspaceId, 'phone' => $canonical,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Contact::where('workspace_id', $workspaceId)->where('phone', $canonical)->lockForUpdate()->firstOrFail();
    }
}
