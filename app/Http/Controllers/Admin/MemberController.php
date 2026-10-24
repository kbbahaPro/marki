<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MemberController extends Controller
{
    /**
     * List team members and pending invitations.
     */
    public function index(): View
    {
        return view('admin.members', [
            'members' => User::query()->orderBy('name')->get(),
            'invitations' => Invitation::query()->pending()->with('inviter')->latest()->get(),
        ]);
    }

    /**
     * Invite a new team member by email. Re-inviting an address replaces its pending invitation.
     */
    public function invite(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        [$invitation, $token] = Invitation::issue(
            $validated['email'],
            $request->user(),
            $request->boolean('is_admin'),
        );

        Mail::to($invitation->email)->send(new InvitationMail($invitation, $token));

        return back()->with('status', 'invitation-sent');
    }

    /**
     * Revoke a pending invitation.
     */
    public function revoke(Invitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at !== null, 404);

        $invitation->delete();

        return back()->with('status', 'invitation-revoked');
    }
}
