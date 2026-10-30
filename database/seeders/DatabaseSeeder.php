<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AccountingSeeder::class);

        $admin = User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.test')],
            [
                'name' => 'Administrator',
                'username' => env('ADMIN_USERNAME', 'superadmin'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'role' => User::ROLE_SUPER_ADMIN,
                'is_active' => true,
            ]
        );

        $admin->profile()->firstOrCreate([], [
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'job_title' => 'Super Administrator',
        ]);
    }
}
