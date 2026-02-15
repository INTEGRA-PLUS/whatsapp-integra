<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class MasterUserSeeder extends Seeder
{
    public function run()
    {
        User::firstOrCreate(
            ['email' => 'master@sistema.com'],
            [
                'name' => 'Master User',
                'password' => bcrypt('password'),
                'role' => 'master',
                'active' => true,
                'company_id' => null
            ]
        );
    }
}
