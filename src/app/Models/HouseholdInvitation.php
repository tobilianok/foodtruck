<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class HouseholdInvitation extends Model
{
    public const VALIDITY_DAYS = 7;

    protected $fillable = ['household_id', 'token_hash', 'created_by', 'expires_at', 'used_at', 'used_by'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    /** Crée une invitation et renvoie [invitation, jeton en clair]. Le jeton n'est jamais stocké. */
    public static function issue(Household $household, User $creator): array
    {
        $token = Str::random(48);

        $invitation = static::create([
            'household_id' => $household->id,
            'token_hash' => hash('sha256', $token),
            'created_by' => $creator->id,
            'expires_at' => now()->addDays(self::VALIDITY_DAYS),
        ]);

        return [$invitation, $token];
    }

    public static function findByToken(?string $token): ?self
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        return static::where('token_hash', hash('sha256', $token))->first();
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        if ($this->used_at !== null) {
            return 'utilisée';
        }

        return $this->expires_at->isFuture() ? 'en attente' : 'expirée';
    }
}
