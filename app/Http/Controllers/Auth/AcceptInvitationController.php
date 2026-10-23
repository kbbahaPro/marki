<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Rules\Username;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class AcceptInvitationController extends Controller
{
    /**
     * Show the account setup form for a pending invitation.
     */
    public function show(string $token): View
    {
        $invitation = Invitation::findPendingByToken($token);

        abort_if($invitation === null, 404, __('This invitation is invalid or has expired.'));

        return view('auth.accept-invitation', [
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }

    /**
     * Create the invited user's account and sign them in.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', new Username, Rule::unique(User::class)],
            'timezone' => ['required', 'timezone:all'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($token, $validated) {
            // Lock the invitation so a double-submit cannot create two accounts.
            $invitation = Invitation::query()
                ->pending()
                ->where('token_hash', hash('sha256', $token))
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            abort_if($invitation === null, 404, __('This invitation is invalid or has expired.'));
            abort_if(User::where('email', $invitation->email)->exists(), 409, __('An account with this email already exists.'));

            $user = new User([
                'name' => $validated['name'],
                'email' => $invitation->email,
                'username' => $validated['username'],
                'timezone' => $validated['timezone'],
                'password' => $validated['password'],
            ]);
            $user->is_admin = $invitation->is_admin;
            // The invitation link itself proves ownership of the address.
            $user->email_verified_at = now();
            $user->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect(route('dashboard', absolute: false));
    }
}
