<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'password_changed_at', 'must_change_password', 'email_verified_at', 'google_id', 'avatar_url', 'erased_identity_hash', 'sync_version'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'sync_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if ($user->sync_version === null) {
                $user->sync_version = 1;
            }
        });

        static::updating(function (User $user) {
            if (! $user->isDirty('sync_version')) {
                $user->sync_version = max(0, (int) $user->getOriginal('sync_version')) + 1;
            }
        });
    }

    public function isOneOf(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
