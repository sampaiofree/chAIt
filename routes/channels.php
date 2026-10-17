<?php

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.user.{id}', fn ($user, $id) => config('chat.enabled') && (int) $user->id === (int) $id && (! ($user instanceof MustVerifyEmail) || $user->hasVerifiedEmail()));
