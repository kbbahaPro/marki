<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\View\View;

class TeamPageController extends Controller
{
    /**
     * A team's public page: who is in it (team event types arrive in the next step).
     */
    public function show(Team $team): View
    {
        return view('public.team', [
            'team' => $team->load('members'),
        ]);
    }
}
