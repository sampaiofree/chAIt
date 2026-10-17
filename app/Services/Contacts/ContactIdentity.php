<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class ContactIdentity
{
    /** Call inside the caller's transaction; the returned contact is locked. */
    public function locked(int $workspaceId, string $phone): Contact
    {
        DB::table('contacts')->insertOrIgnore([
            'workspace_id' => $workspaceId, 'phone' => $phone,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Contact::where('workspace_id', $workspaceId)->where('phone', $phone)->lockForUpdate()->firstOrFail();
    }
}
