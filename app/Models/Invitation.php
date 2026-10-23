<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['email', 'token_hash', 'is_admin', 'invited_by', 'expires_at'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Create an invitation, replacing any pending one for the same address.
     *
     * @return array{0: self, 1: string} The invitation and its raw token.
     */
    public static function issue(string $email, ?User $inviter, bool $isAdmin = false): array
    {
        $token = Str::random(64);

        static::query()->pending()->where('email', $email)->delete();

        $invitation = static::create([
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'is_admin' => $isAdmin,
            'invited_by' => $inviter?->id,
            'expires_at' => now()->addDays(config('booking.invitations.expire_days')),
        ]);

        return [$invitation, $token];
    }

    public static function findPendingByToken(string $token): ?self
    {
        return static::query()
            ->pending()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
    }

    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
