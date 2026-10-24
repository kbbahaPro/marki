<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->using(TeamMember::class)
            ->withPivot('role')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    /**
     * Team admins manage the team; global admins can manage every team.
     */
    public function isManagedBy(User $user): bool
    {
        return $user->is_admin
            || $this->members()->whereKey($user->id)->wherePivot('role', TeamRole::Admin->value)->exists();
    }

    public function publicUrl(): string
    {
        return route('public.team', $this);
    }

    /**
     * Why this team cannot be deleted right now, if anything stops it.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->eventTypes()->exists()) {
            return __('Delete the team’s event types first.');
        }

        // Bookings are kept as a record, and they point at the team's deleted event types.
        if (Booking::whereIn('event_type_id', $this->eventTypes()->onlyTrashed()->select('id'))->exists()) {
            return __('This team has bookings, which are kept as a record. Teams with booking history cannot be deleted yet.');
        }

        return null;
    }

    public function eventTypes(): HasMany
    {
        // chaperone() sets each event type's team, which publicUrl() reads.
        return $this->hasMany(EventType::class)->chaperone();
    }
}
