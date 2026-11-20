<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'smartfleet:create-admin';

    protected $description = 'Create a SmartFleet admin account';

    public function handle(): int
    {
        $this->info('SmartFleet - Create Admin');

        $name = trim((string) $this->ask('Admin name'));

        $email = strtolower(
            trim((string) $this->ask('Admin email'))
        );

        if (
            $name === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $this->error('Invalid name or email.');
            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('Email already exists.');
            return self::FAILURE;
        }

        $password = $this->secret('Admin password');
        $confirmation = $this->secret('Confirm password');

        if (
            !is_string($password) ||
            strlen($password) < 12
        ) {
            $this->error('Password must be at least 12 characters.');
            return self::FAILURE;
        }

        if ($password !== $confirmation) {
            $this->error('Passwords do not match.');
            return self::FAILURE;
        }

        DB::transaction(function () use (
            $name,
            $email,
            $password
        ) {
            $user = new User();

            $user->name = $name;
            $user->email = $email;
            $user->password = Hash::make($password);
            $user->role = 'admin';

            $user->save();
        });

        $this->info('Admin account created successfully!');

        return self::SUCCESS;
    }
}