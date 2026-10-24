<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Membership of a user in a team, with their role.
 */
class TeamMember extends Pivot
{
    protected $table = 'team_members';

    public $incrementing = true;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
        ];
    }
}
