<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\Username;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin
                            {--name= : Full name}
                            {--email= : Email address}
                            {--username= : Username used in booking links}
                            {--timezone= : IANA time zone, e.g. Europe/Paris}
                            {--password= : Password (prompted when omitted)}';

    protected $description = 'Create an administrator account (use this to bootstrap the first team member)';

    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?? text('Name', required: true),
            'email' => $this->option('email') ?? text('Email', required: true),
            'username' => $this->option('username') ?? text('Username', required: true, hint: 'Lowercase letters, numbers and hyphens'),
            'timezone' => $this->option('timezone') ?? text('Time zone', default: config('booking.default_timezone'), required: true),
            'password' => $this->option('password') ?? password('Password', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'username' => ['required', 'string', new Username, Rule::unique(User::class)],
            'timezone' => ['required', 'timezone:all'],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = new User($validator->validated());
        $user->is_admin = true;
        $user->email_verified_at = now();
        $user->save();

        $this->components->info("Administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
