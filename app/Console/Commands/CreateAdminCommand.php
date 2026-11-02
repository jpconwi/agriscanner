<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Quota\QuotaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    protected $signature = 'agriscan:create-admin {email} {name=Administrator}';

    protected $description = 'Create (or promote) an administrator. Prompts for the password so it never lands in shell history.';

    public function handle(QuotaService $quota): int
    {
        $password = $this->secret('Password (min 10 chars, letters and numbers)');
        $v = Validator::make(['email' => $this->argument('email'), 'password' => $password], [
            'email' => ['required', 'email'], 'password' => ['required', Password::min(10)->letters()->numbers()],
        ]);
        if ($v->fails()) {
            $this->error(implode(' ', $v->errors()->all()));

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => strtolower($this->argument('email'))]);
        $user->name = $user->name ?: $this->argument('name');
        $user->password = $password;
        $user->role = User::ROLE_ADMIN;
        $user->is_active = true;
        $user->save();
        $user->profile()->firstOrCreate([]);
        $quota->ensureRows($user);
        $this->info("Administrator ready: {$user->email}");

        return self::SUCCESS;
    }
}
