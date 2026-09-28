<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Rôle dans l'application (administration générale). */
    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'membre';

    /** Rôle dans le foyer (qui peut modifier membres, appareils, budget, invitations). */
    public const HOUSEHOLD_ADMIN = 'admin';

    public const HOUSEHOLD_MEMBER = 'membre';

    protected $fillable = [
        'authentik_sub',
        'name',
        'username',
        'email',
        'role',
        'last_login_at',
        'household_id',
        'household_role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** Fiche membre du foyer liée à ce compte (pour les portions). */
    public function member(): HasOne
    {
        return $this->hasOne(HouseholdMember::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isHouseholdAdmin(): bool
    {
        return $this->household_id !== null && $this->household_role === self::HOUSEHOLD_ADMIN;
    }

    public function firstName(): string
    {
        return strtok($this->name, ' ') ?: $this->name;
    }
}
