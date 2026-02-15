<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $company = \App\Models\Company::firstOrCreate(
            ['slug' => 'demo'],
            [
                'name' => 'Empresa Demo',
                'email' => 'demo@empresa.com',
                'active' => true
            ]
        );

        \App\Models\User::firstOrCreate(
            ['email' => 'admin@empresa.com'],
            [
                'company_id' => $company->id,
                'name' => 'Admin',
                'password' => bcrypt('password'), // You might want to use Hash::make() in production
                'role' => 'admin',
                'active' => true
            ]
        );
    }
}
