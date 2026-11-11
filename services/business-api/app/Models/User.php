<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, \Laravel\Sanctum\HasApiTokens, Notifiable;

    /**
     * Changing user role must invalidate prior browser grants, including on
     * file/Redis sessions. PR #76 independently revokes bearer credentials.
     */
    protected static function booted(): void
    {
        static::updating(function (self $account): void {
            if ($account->isDirty('role')) {
                $account->auth_epoch = (int) $account->auth_epoch + 1;
            }
        });
        static::updated(function (self $account): void {
            if (! $account->wasChanged('role')) {
                return;
            }

            // A role change is a security epoch boundary: old browser sessions
            // and existing native bearer tokens must not inherit new privileges.
            // Callers changing roles must hold a row lock in a transaction.
            $account->tokens()->delete();
            DB::table('sessions')->where('user_id', $account->id)->delete();
            DB::table('users')->where('id', $account->id)->update(['remember_token' => Str::random(60)]);
        });
    }

    protected $attributes = ['role' => 'customer', 'locale' => 'en', 'account_status' => 'active', 'auth_epoch' => 0];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'auth_epoch',
        'suspended_at',
    ];

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
            'auth_epoch' => 'integer',
            'suspended_at' => 'datetime',
        ];
    }
}
