<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'crm:create-admin {--name=} {--email=}';

    protected $description = 'Create (or promote) an administrator account';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Full name');
        $email = Str::lower($this->option('email') ?: $this->ask('Email'));
        $password = $this->secret('Password (min 8 characters, letters and numbers)');

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => $password,
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("Admin ready: {$user->email}");

        return self::SUCCESS;
    }
}
