<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Agent = 'agent';

    public function weight(): int
    {
        return match ($this) {
            self::Owner => 400,
            self::Admin => 300,
            self::Manager => 200,
            self::Agent => 100,
        };
    }

    public function atLeast(self $required): bool
    {
        return $this->weight() >= $required->weight();
    }
}
