<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'username', 'timezone', 'password'])]
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
        return $this->hasMany(EventType::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'host_id');
    }

    public function googleConnection(): HasOne
    {
        return $this->hasOne(CalendarConnection::class)->where('provider', CalendarConnection::GOOGLE);
    }
}
