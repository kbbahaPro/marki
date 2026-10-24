<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'email', 'username', 'timezone', 'password', 'job_title', 'company'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'is_admin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (User $user) => Schedule::createDefaultFor($user));
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /**
     * Up to two initials, for when there is no photo.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY);
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * "Head of Sales at Acme", "Head of Sales", "Acme", or null.
     */
    public function headline(): ?string
    {
        return match (true) {
            filled($this->job_title) && filled($this->company) => __(':title at :company', ['title' => $this->job_title, 'company' => $this->company]),
            filled($this->job_title) => $this->job_title,
            filled($this->company) => $this->company,
            default => null,
        };
    }

    /**
     * Why this account cannot be deleted right now, if anything stops it.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->is_admin && ! static::where('is_admin', true)->whereKeyNot($this->id)->exists()) {
            return __('You are the only administrator. Invite another administrator before deleting your account.');
        }

        // Bookings are kept as a record for guests and the team.
        if (Booking::attendedBy($this->id)->exists()) {
            return __('Your account has bookings, which are kept as a record. Accounts with booking history cannot be deleted yet.');
        }

        return null;
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function defaultSchedule(): HasOne
    {
        return $this->hasOne(Schedule::class)->where('is_default', true);
    }

    public function eventTypes(): HasMany
    {
        // chaperone() sets each event type's user, which publicUrl() reads.
        return $this->hasMany(EventType::class)->chaperone();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'host_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members')
            ->using(TeamMember::class)
            ->withPivot('role')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function googleConnection(): HasOne
    {
        return $this->hasOne(CalendarConnection::class)->where('provider', CalendarConnection::GOOGLE);
    }
}
