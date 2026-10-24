<?php

namespace App\Models;

use App\Notifications\ResetAccountPassword;
use App\Notifications\VerifyAccount;
use App\Services\EmailVerification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'auth_version'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'auth_version' => 'integer'];
    }

    public function memberships()
    {
        return $this->hasMany(ShopMembership::class);
    }

    public function sendEmailVerificationNotification()
    {
        if (EmailVerification::required($this)) {
            $this->notify(new VerifyAccount);
        }
    }

    public function canUseVerifiedFeatures(): bool
    {
        return $this->hasVerifiedEmail() || ! EmailVerification::required($this);
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetAccountPassword($token));
    }

    public function platformCan(string $permission): bool
    {
        return $this->account_type === 'admin' && Role::where('name', 'system_admin')->whereHas('permissions', fn ($q) => $q->where('name', $permission))->exists();
    }
}
