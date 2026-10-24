<?php

namespace App\Models;

use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
}
