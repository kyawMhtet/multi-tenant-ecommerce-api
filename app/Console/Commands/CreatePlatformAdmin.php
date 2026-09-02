<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

/**
 * There is deliberately no sign-up endpoint for platform staff. An account
 * that can read and settle money across every tenant is not something a web
 * form should be able to create — issuing them requires shell access to the
 * server, which is a far smaller set of people than "anyone who can reach the
 * API".
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin
                            {--name= : Display name}
                            {--email= : Sign-in email}';

    protected $description = 'Create a platform staff account (can review payments across all tenants)';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');
        // secret(), so the password never lands in the shell history file.
        $password = $this->secret('Password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', Rule::unique('platform_admins', 'email')],
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = PlatformAdmin::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        $this->info("Platform admin #{$admin->id} created for {$admin->email}.");
        $this->line('Sign in at POST /api/v1/platform/login.');

        return self::SUCCESS;
    }
}
