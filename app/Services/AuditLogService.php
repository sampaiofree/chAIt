<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        Workspace $workspace,
        string $action,
        ?User $user = null,
        ?Model $auditable = null,
        ?string $description = null,
        ?array $payload = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'description' => $description,
            'payload' => $payload,
        ]);
    }
}
