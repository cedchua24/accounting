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

        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.test')],
            ['name' => 'Administrator', 'password' => Hash::make(env('ADMIN_PASSWORD', 'password'))]
        );
    }
}
