<?php

namespace App\Services\Uazapi;

use Illuminate\Support\Facades\Cache;

class UazapiGate
{
    public function acquire(string $bucket, bool $interactive = false): callable
    {
        $prefix = 'chat-provider:'.$bucket;
        if ($interactive) {
            Cache::put($prefix.':human', true, 2);
        } else {
            Cache::put($prefix.':background', true, 2);
        }
        $guard = Cache::lock($prefix.':guard', 2);
        if (! $guard->get()) {
            throw new UazapiDeferredException;
        }
        $slot = null;
        try {
            $now = microtime(true);
            $until = (float) Cache::get($prefix.':cooldown', 0);
            if ($until > $now) {
                throw new UazapiDeferredException(max(1, (int) ceil($until - $now)), 'cooldown');
            }
            $state = Cache::get($prefix.':pace', ['next' => 0, 'count' => 0]);
            if ($state['next'] > $now) {
                throw new UazapiDeferredException;
            }
            // Weighted opportunities: four interactive slots, one background slot.
            $backgroundTurn = $state['count'] % 5 === 4;
            if (($interactive && $backgroundTurn && Cache::has($prefix.':background')) || (! $interactive && ! $backgroundTurn && Cache::has($prefix.':human'))) {
                // Move the fairness counter, but do not consume a provider request.
                Cache::put($prefix.':pace', ['next' => $now, 'count' => $state['count'] + 1], 60);
                throw new UazapiDeferredException;
            }
            for ($i = 0; $i < config('chat.concurrent_requests'); $i++) {
                $candidate = Cache::lock($prefix.':slot:'.$i, 60);
                if ($candidate->get()) {
                    $slot = $candidate;
                    break;
                }
            }
            if (! $slot) {
                throw new UazapiDeferredException;
            }
            $rate = str_starts_with($bucket, 'admin:') ? 10 : config('chat.requests_per_second');
            Cache::put($prefix.':pace', ['next' => $now + 1 / max(1, $rate), 'count' => $state['count'] + 1], 60);
        } catch (UazapiDeferredException $error) {
            Cache::add($prefix.':limited', 0, 3600);
            Cache::increment($prefix.':limited');
            throw $error;
        } finally {
            $guard->release();
        }

        return fn () => $slot->release();
    }

    public function cooldown(string $bucket, int $seconds): void
    {
        Cache::put('chat-provider:'.$bucket.':cooldown', microtime(true) + $seconds, $seconds + 1);
    }
}
