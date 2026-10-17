<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UazapiInbox extends Model
{
    protected $table = 'uazapi_inbox';

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'datetime', 'dispatched_at' => 'datetime'];
    }

    public function removeFiles(): void
    {
        foreach (data_get($this->payload, 'prepared_input.content', []) as $part) {
            if (isset($part['local_file']['path'])) {
                Storage::disk('local')->delete($part['local_file']['path']);
            }
        }
    }

    public function consumed(): void
    {
        DB::transaction(function (): void {
            $current = self::whereKey($this->id)->lockForUpdate()->first();
            if (! $current) {
                return;
            }
            $current->consumers = max(0, $current->consumers - 1);
            if ($current->consumers === 0 && $current->status === 'processed') {
                $current->removeFiles();
                $current->payload = null;
            }
            $current->save();
        });
    }
}
