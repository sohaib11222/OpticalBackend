<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Sessions
{
    public static function revoke(User $user): void
    {
        $user->increment('auth_version');
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
