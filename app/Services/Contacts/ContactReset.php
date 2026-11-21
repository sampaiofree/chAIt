<?php

namespace App\Services\Contacts;

use App\Services\Chat\WhatsAppJid;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContactReset
{
    // Transaction-level identity lock also covers contacts which do not exist yet.
    public function lock(int $workspaceId, string $external): void
    {
        $jids = app(WhatsAppJid::class);
        if ($phone = $jids->phone($external)) {
            $external = app(ContactPhoneIdentity::class)->variants($phone)[0].'@s.whatsapp.net';
        }
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['lead:'.$workspaceId.':'.$external]);
    }

    public function cutoff(int $workspaceId, string $external): ?Carbon
    {
        $value = DB::table('contact_resets')->where('workspace_id', $workspaceId)->where('external_id', $external)->value('reset_at');

        return $value ? Carbon::parse($value) : null;
    }
}
