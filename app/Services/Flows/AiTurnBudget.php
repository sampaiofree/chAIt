<?php

namespace App\Services\Flows;

class AiTurnBudget
{
    private float $started;

    private int $calls = 0;

    public function __construct()
    {
        $this->started = hrtime(true) / 1e9;
    }

    public function timeout(float $maximum): float
    {
        $remaining = 150 - (hrtime(true) / 1e9 - $this->started);
        if ($remaining <= 0) {
            throw new AiTurnLimitException('ai_turn_time_limit');
        }

        return min($maximum, $remaining);
    }

    public function toolCall(): void
    {
        $this->timeout(150);
        if (++$this->calls > 20) {
            throw new AiTurnLimitException('ai_turn_action_limit');
        }
    }
}
