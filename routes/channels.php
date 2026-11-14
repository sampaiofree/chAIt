<?php

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.user.{id}', fn ($user, $id) => config('chat.enabled') && ! $user->access_blocked_at && (int) $user->id === (int) $id && (! ($user instanceof MustVerifyEmail) || $user->hasVerifiedEmail()));
